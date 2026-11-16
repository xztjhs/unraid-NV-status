<?PHP
/* nvidia-gpu-status :: 采集与归一化
 * 快层：nvidia-smi --query-gpu（单次 ~90ms，可用 -lms 流式）
 * 慢层：nvidia-smi -q -x（单次 ~240ms，10s 一次）
 * 目标机：Unraid 7.x / PHP 8.4 / 无 ffi、无 python、无 gcc
 */

define('NVGPU_PLUGIN', 'nvidia-gpu-status');
define('NVGPU_SHM', '/dev/shm/nvidia-gpu-status');
define('NVGPU_CHAN', 'nvgpu');

/* 快层字段：全部来自 --query-gpu，一次问完 */
function nvgpu_fast_fields(): string {
  return implode(',', [
    'index', 'name', 'serial', 'uuid', 'vbios_version', 'driver_version',
    'pstate', 'compute_mode',
    'utilization.gpu', 'utilization.memory', 'utilization.encoder', 'utilization.decoder',
    'temperature.gpu', 'temperature.gpu.tlimit',
    'fan.speed',
    'power.draw', 'power.draw.instant', 'power.limit', 'enforced.power.limit', 'power.default_limit',
    'memory.total', 'memory.used', 'memory.free', 'memory.reserved',
    'clocks.current.sm', 'clocks.current.memory', 'clocks.current.graphics', 'clocks.max.sm',
    'pcie.link.gen.current', 'pcie.link.gen.max', 'pcie.link.width.current', 'pcie.link.width.max',
    'clocks_throttle_reasons.active', 'clocks_throttle_reasons.gpu_idle',
    'ecc.mode.current', 'mig.mode.current',
  ]);
}

/* ---------------------------------------------------------------- 工具 */

function nvgpu_num($v) {
  if ($v === null) return null;
  $s = trim((string)$v);
  if ($s === '' || $s === 'N/A' || $s === '[N/A]' || $s === '[Not Supported]' || $s === 'N/A ') return null;
  if (stripos($s, 'not supported') !== false) return null;
  // 去掉单位与百分号，保留数字（含负号与小数点）
  if (preg_match('/^-?\d+(\.\d+)?/', str_replace(',', '', $s), $m)) return $m[0] + 0;
  return null;
}

function nvgpu_str($v) {
  if ($v === null) return null;
  $s = trim((string)$v);
  if ($s === '' || $s === 'N/A' || $s === '[N/A]') return null;
  if (stripos($s, 'Requested functionality has been deprecated') !== false) return null;
  return $s;
}

function nvgpu_cfg(): array {
  $defaults = [
    'SAMPLE_MS'      => 500,
    'REFRESH_MS'     => 2000,
    'WINDOW_MIN'     => 5,
    'SLOW_SEC'       => 10,
    'COLUMN'         => 'column3',
    'TEMP_WARN'      => 85,
    'SHOW_PROCESSES' => 'yes',
    'PUSH'           => 'yes',
    'ENABLED'        => 'yes',
  ];
  $ram = '/usr/local/emhttp/plugins/' . NVGPU_PLUGIN . '/default.cfg';
  $rom = '/boot/config/plugins/' . NVGPU_PLUGIN . '/' . NVGPU_PLUGIN . '.cfg';
  $cfg = $defaults;
  foreach ([$ram, $rom] as $f) {
    if (!is_file($f)) continue;
    $ini = @parse_ini_file($f, false, INI_SCANNER_NORMAL);
    if (is_array($ini)) $cfg = array_merge($cfg, $ini);
  }
  return $cfg;
}

function nvgpu_is_enabled(array $cfg): bool {
  return strtolower((string)($cfg['ENABLED'] ?? 'yes')) !== 'no';
}

/* ---------------------------------------------------------------- 快层解析 */

function nvgpu_parse_fast(string $line): ?array {
  $line = rtrim($line, "\r\n");
  if ($line === '') return null;
  $cols = str_getcsv($line, ',');
  $map = [
    'index', 'name', 'serial', 'uuid', 'vbios_version', 'driver_version',
    'pstate', 'compute_mode',
    'utilization.gpu', 'utilization.memory', 'utilization.encoder', 'utilization.decoder',
    'temperature.gpu', 'temperature.gpu.tlimit',
    'fan.speed',
    'power.draw', 'power.draw.instant', 'power.limit', 'enforced.power.limit', 'power.default_limit',
    'memory.total', 'memory.used', 'memory.free', 'memory.reserved',
    'clocks.current.sm', 'clocks.current.memory', 'clocks.current.graphics', 'clocks.max.sm',
    'pcie.link.gen.current', 'pcie.link.gen.max', 'pcie.link.width.current', 'pcie.link.width.max',
    'clocks_throttle_reasons.active', 'clocks_throttle_reasons.gpu_idle',
    'ecc.mode.current', 'mig.mode.current',
  ];
  if (count($cols) < count($map)) return null;
  $raw = [];
  foreach ($map as $i => $k) $raw[$k] = trim($cols[$i] ?? '');

  return [
    'index'         => (int)$raw['index'],
    'name'          => nvgpu_str($raw['name']),
    'serial'        => nvgpu_str($raw['serial']),
    'uuid'          => nvgpu_str($raw['uuid']),
    'vbios'         => nvgpu_str($raw['vbios_version']),
    'driver'        => nvgpu_str($raw['driver_version']),
    'pstate'        => nvgpu_str($raw['pstate']),
    'compute_mode'  => nvgpu_str($raw['compute_mode']),
    'gpu_pct'       => nvgpu_num($raw['utilization.gpu']),
    'mem_pct'       => nvgpu_num($raw['utilization.memory']),
    'enc_pct'       => nvgpu_num($raw['utilization.encoder']),
    'dec_pct'       => nvgpu_num($raw['utilization.decoder']),
    'temp'          => nvgpu_num($raw['temperature.gpu']),
    'temp_tlimit'   => nvgpu_num($raw['temperature.gpu.tlimit']),
    'fan'           => nvgpu_num($raw['fan.speed']),
    'power'         => nvgpu_num($raw['power.draw']),
    'power_instant' => nvgpu_num($raw['power.draw.instant']),
    'power_limit'   => nvgpu_num($raw['power.limit']),
    'power_enforced'=> nvgpu_num($raw['enforced.power.limit']),
    'power_default' => nvgpu_num($raw['power.default_limit']),
    'mem_total'     => nvgpu_num($raw['memory.total']),
    'mem_used'      => nvgpu_num($raw['memory.used']),
    'mem_free'      => nvgpu_num($raw['memory.free']),
    'mem_reserved'  => nvgpu_num($raw['memory.reserved']),
    'clk_sm'        => nvgpu_num($raw['clocks.current.sm']),
    'clk_mem'       => nvgpu_num($raw['clocks.current.memory']),
    'clk_gfx'       => nvgpu_num($raw['clocks.current.graphics']),
    'clk_sm_max'    => nvgpu_num($raw['clocks.max.sm']),
    'pcie_gen'      => nvgpu_num($raw['pcie.link.gen.current']),
    'pcie_gen_max'  => nvgpu_num($raw['pcie.link.gen.max']),
    'pcie_width'    => nvgpu_num($raw['pcie.link.width.current']),
    'pcie_width_max'=> nvgpu_num($raw['pcie.link.width.max']),
    'throttle_mask' => nvgpu_str($raw['clocks_throttle_reasons.active']),
    'gpu_idle'      => strtolower(nvgpu_str($raw['clocks_throttle_reasons.gpu_idle']) ?? '') === 'active',
    'ecc_mode'      => nvgpu_str($raw['ecc.mode.current']),
    'mig_mode'      => nvgpu_str($raw['mig.mode.current']),
  ];
}

/* ---------------------------------------------------------------- 进程归属解析
 *
 * 把 nvidia-smi 报的 host pid 映射到 docker 容器名 / libvirt 虚拟机名。
 * 慢层调用（每 SLOW_SEC 秒），不进快层——docker ps + 遍历 /proc 不适合秒级。
 */

function nvgpu_pid_owners(): array {
  $containers = [];
  // --no-trunc 拿全 ID，才能和 cgroup 里的 64 位 ID 直接比对
  $out = @shell_exec('timeout 4 docker ps --no-trunc --format "{{.ID}}|{{.Names}}" 2>/dev/null');
  if (is_string($out) && $out !== '') {
    foreach (explode("\n", trim($out)) as $line) {
      $p = explode('|', $line, 2);
      if (count($p) === 2 && $p[0] !== '' && $p[1] !== '') $containers[trim($p[0])] = trim($p[1]);
    }
  }

  // VM：qemu 进程的 cmdline 里带 -name guest=<name>
  $vms = [];
  foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $f) {
    $c = @file_get_contents($f);
    if (!is_string($c) || strpos($c, 'qemu-system') === false) continue;
    if (preg_match('/guest=([^,\x00]+)/', $c, $m)) {
      $vms[(int)basename(dirname($f))] = $m[1];
    }
  }

  return ['containers' => $containers, 'vms' => $vms];
}

function nvgpu_pid_scope(int $pid, array $owners): array {
  // 1) 容器：/proc/<pid>/cgroup 形如 0::/docker/<64hex>
  $cg = @file_get_contents("/proc/$pid/cgroup");
  if (is_string($cg) && preg_match('#/docker/([0-9a-f]{64})#', $cg, $m)) {
    $cid = $m[1];
    foreach ($owners['containers'] as $full => $name) {
      if (strpos($full, $cid) === 0 || strpos($cid, $full) === 0) {
        return ['kind' => 'container', 'name' => $name];
      }
    }
    return ['kind' => 'container', 'name' => substr($cid, 0, 12)];
  }

  // 2) 虚拟机：沿父进程链找 qemu（GPU 进程通常是 qemu 的子进程）
  $cur = $pid;
  for ($i = 0; $i < 25 && $cur > 1; $i++) {
    if (isset($owners['vms'][$cur])) return ['kind' => 'vm', 'name' => $owners['vms'][$cur]];
    $st = @file_get_contents("/proc/$cur/status");
    if (!is_string($st) || !preg_match('/^PPid:\s+(\d+)/m', $st, $mm)) break;
    $cur = (int)$mm[1];
  }

  return ['kind' => 'host', 'name' => ''];
}

/* ---------------------------------------------------------------- 慢层解析 (-q -x) */

/** 解析 nvidia-smi -q -x 的 XML 文本（daemon 与 api 共用，单一事实来源） */
function nvgpu_parse_slow_xml(string $xml): array {
  if ($xml === '') return ['ok' => false, 'gpus' => []];

  // 抑制 XML 解析告警（-q -x 偶尔带非法字符）
  $prev = libxml_use_internal_errors(true);
  $doc = simplexml_load_string($xml);
  libxml_clear_errors();
  libxml_use_internal_errors($prev);
  if ($doc === false) return ['ok' => false, 'gpus' => []];

  $owners = null;
  $out = [];
  foreach ($doc->gpu as $g) {
    $idx = (int)(string)$g->minor_number;
    $r = [
      'index'               => $idx,
      'ecc_mode'            => nvgpu_str((string)($g->ecc_mode->current_ecc ?? '')),
      'ecc_pending'         => nvgpu_str((string)($g->ecc_mode->pending_ecc ?? '')),
      'ecc_corr_vol'        => nvgpu_num((string)($g->ecc_errors->volatile->dram_correctable ?? '')),
      'ecc_uncorr_vol'      => nvgpu_num((string)($g->ecc_errors->volatile->dram_uncorrectable ?? '')),
      'ecc_corr_agg'        => nvgpu_num((string)($g->ecc_errors->aggregate->dram_correctable ?? '')),
      'ecc_uncorr_agg'      => nvgpu_num((string)($g->ecc_errors->aggregate->dram_uncorrectable ?? '')),
      'sram_corr_vol'       => nvgpu_num((string)($g->ecc_errors->volatile->sram_correctable ?? '')),
      'sram_uncorr_vol'     => nvgpu_num((string)($g->ecc_errors->volatile->sram_uncorrectable_secded ?? '')),
      'retired_sbe'         => nvgpu_num((string)($g->retired_pages->multiple_single_bit_retirement->retired_count ?? '')),
      'retired_dbe'         => nvgpu_num((string)($g->retired_pages->double_bit_retirement->retired_count ?? '')),
      'retired_pending'     => nvgpu_str((string)($g->retired_pages->pending_retirement ?? '')),
      'remap_correctable'   => nvgpu_num((string)($g->remapped_rows->remapped_row_corr ?? '')),
      'remap_uncorrectable' => nvgpu_num((string)($g->remapped_rows->remapped_row_unc ?? '')),
      'remap_pending'       => nvgpu_str((string)($g->remapped_rows->remapped_row_pending ?? '')),
      'remap_failure'       => nvgpu_str((string)($g->remapped_rows->remapped_row_failure ?? '')),
      'replay'              => nvgpu_num((string)($g->pci->replay_counter ?? '')),
      'replay_rollover'     => nvgpu_num((string)($g->pci->replay_rollover_counter ?? '')),
      'pcie_tx'             => nvgpu_str((string)($g->pci->tx_util ?? '')),
      'pcie_rx'             => nvgpu_str((string)($g->pci->rx_util ?? '')),
      'enc_sessions'        => nvgpu_num((string)($g->encoder_stats->session_count ?? '')),
      'enc_fps'             => nvgpu_num((string)($g->encoder_stats->average_fps ?? '')),
      'enc_latency'         => nvgpu_num((string)($g->encoder_stats->average_latency ?? '')),
      'fb_total'            => nvgpu_num((string)($g->fb_memory_usage->total ?? '')),
      'bar1_total'          => nvgpu_num((string)($g->bar1_memory_usage->total ?? '')),
      'bar1_used'           => nvgpu_num((string)($g->bar1_memory_usage->used ?? '')),
      'inforom_image'       => nvgpu_str((string)($g->inforom_version->image_version ?? '')),
      'processes'           => [],
      'throttle'            => [],
    ];

    // 降频原因（明细比位掩码可读）
    if (isset($g->clocks_event_reasons)) {
      foreach ($g->clocks_event_reasons->children() as $k => $v) {
        if (strtolower(trim((string)$v)) === 'active') {
          $r['throttle'][] = str_replace('clocks_event_reason_', '', $k);
        }
      }
    }

    // 进程占用 + 归属（docker 容器 / libvirt 虚拟机 / 宿主机）
    if (isset($g->processes->process_info)) {
      if ($owners === null) $owners = nvgpu_pid_owners();  // 一次 XML 只查一遍
      foreach ($g->processes->process_info as $p) {
        $pid   = (int)(string)$p->pid;
        $path  = nvgpu_str((string)($p->process_name ?? ''));
        $scope = nvgpu_pid_scope($pid, $owners);
        $r['processes'][] = [
          'pid'       => $pid,
          'name'      => nvgpu_str(basename((string)($path ?? ''))),
          'path'      => $path,
          'type'      => nvgpu_str((string)($p->type ?? '')),
          'mem'       => nvgpu_num((string)($p->used_memory ?? '')),
          'owner'     => $scope['name'],
          'ownerKind' => $scope['kind'],
        ];
      }
      usort($r['processes'], fn($a, $b) => ($b['mem'] ?? 0) <=> ($a['mem'] ?? 0));
    }

    $out[$idx] = $r;
  }
  return ['ok' => true, 'ts' => microtime(true), 'gpus' => $out];
}

function nvgpu_slow_collect(): array {
  $xml = @shell_exec('timeout 8 nvidia-smi -q -x 2>/dev/null');
  if (!is_string($xml) || $xml === '') return ['ok' => false, 'gpus' => []];
  return nvgpu_parse_slow_xml($xml);
}

/* ---------------------------------------------------------------- 合并 */

function nvgpu_merge(array $fast, array $slow): array {
  $idx = (int)($fast['index'] ?? 0);
  $s = $slow['gpus'][$idx] ?? [];
  $merged = $fast;
  foreach ($s as $k => $v) {
    if ($k === 'index') continue;
    $merged[$k] = $v;
  }
  // ECC 显式关闭时，错误计数一律视为「不适用」，避免 UI 把 N/A 当 0 报警
  $eccOff = strtolower((string)($merged['ecc_mode'] ?? '')) === 'disabled';
  foreach (['ecc_corr_vol','ecc_uncorr_vol','ecc_corr_agg','ecc_uncorr_agg','sram_corr_vol','sram_uncorr_vol'] as $k) {
    if ($eccOff) $merged[$k] = null;
  }
  $merged['ecc_enabled'] = !$eccOff && !empty($merged['ecc_mode']);
  if (!isset($merged['processes'])) $merged['processes'] = [];
  if (!isset($merged['throttle'])) $merged['throttle'] = [];
  return $merged;
}

/* ---------------------------------------------------------------- 派生告警
 *
 * 返回结构化告警 [level, code, args]，具体文案由前端按语言渲染
 * （采样器是守护进程，没有 locale）。
 */
function nvgpu_flags(array $g, array $cfg): array {
  $flags = [];
  $tw = (float)($cfg['TEMP_WARN'] ?? 85);

  if ($g['temp'] !== null && $g['temp'] >= $tw)          $flags[] = ['warn', 'temp', [(float)$g['temp'], $tw]];
  if ($g['ecc_enabled']) {
    if (($g['ecc_uncorr_vol'] ?? 0) > 0)                 $flags[] = ['error', 'ecc_uncorr', [$g['ecc_uncorr_vol']]];
    if (($g['remap_failure'] ?? null) === 'Yes')         $flags[] = ['error', 'remap_failure', []];
    if (($g['remap_pending'] ?? null) === 'Yes')         $flags[] = ['warn', 'remap_pending', []];
    if (($g['ecc_corr_vol'] ?? 0) > 1000)                $flags[] = ['warn', 'ecc_corr', [$g['ecc_corr_vol']]];
  }
  // PCIe：只有真正在跑才谈降级（空闲降到 Gen1 属正常，实测 A5000 空闲就是 Gen1）
  $busy = ($g['gpu_pct'] ?? 0) >= 20 || ($g['mem_pct'] ?? 0) >= 20;
  if ($busy && $g['pcie_gen'] !== null && $g['pcie_gen_max'] !== null && $g['pcie_gen'] < $g['pcie_gen_max']) {
    $flags[] = ['warn', 'pcie_gen', [(int)$g['pcie_gen'], (int)$g['pcie_gen_max']]];
  }
  if ($busy && $g['pcie_width'] !== null && $g['pcie_width_max'] !== null && $g['pcie_width'] < $g['pcie_width_max']) {
    $flags[] = ['warn', 'pcie_width', [(int)$g['pcie_width'], (int)$g['pcie_width_max']]];
  }
  $thr = $g['throttle'] ?? [];
  if (in_array('hw_thermal_slowdown', $thr, true))   $flags[] = ['warn', 'throttle_hw_thermal', []];
  if (in_array('sw_thermal_slowdown', $thr, true))   $flags[] = ['warn', 'throttle_sw_thermal', []];
  if (in_array('hw_slowdown', $thr, true))           $flags[] = ['warn', 'throttle_hw', []];
  if ($g['power'] !== null && $g['power_limit'] && $g['power'] > 0 && ($g['power'] / $g['power_limit']) > 1.02) {
    $flags[] = ['warn', 'power_over', []];
  }
  if ($g['mem_total'] && $g['mem_used'] !== null && ($g['mem_used'] / $g['mem_total']) > 0.97) {
    $flags[] = ['warn', 'vram_full', []];
  }
  return $flags;
}

/* ---------------------------------------------------------------- 存储 */

function nvgpu_atomic_write(string $file, string $data): bool {
  $tmp = $file . '.' . getmypid() . '.tmp';
  if (@file_put_contents($tmp, $data, LOCK_EX) === false) return false;
  if (!@rename($tmp, $file)) { @unlink($tmp); return false; }
  return true;
}

function nvgpu_store(string $shm, array $snapshot, array $cfg): void {
  @mkdir($shm, 0755, true);
  nvgpu_atomic_write($shm . '/latest.json', json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));

  // 历史：按 1s 节流写入，只留最近 60 分钟
  $histFile = $shm . '/hist.jsonl';
  $now = (int)floor($snapshot['ts']);
  $stat = @filemtime($shm . '/hist.stamp');
  if ($stat === false || $now - $stat >= 1) {
    @touch($shm . '/hist.stamp');
    foreach ($snapshot['gpus'] as $g) {
      $row = [
        't'  => $now,
        'i'  => $g['index'],
        'g'  => $g['gpu_pct'],
        'm'  => ($g['mem_total'] && $g['mem_used'] !== null) ? round($g['mem_used'] / $g['mem_total'] * 100, 1) : null,
        'T'  => $g['temp'],
        'p'  => $g['power'],
        'e'  => $g['enc_pct'],
        'd'  => $g['dec_pct'],
      ];
      @file_put_contents($histFile, json_encode($row, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
    }
    // 截断到 60 分钟
    $lines = @file($histFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines)) {
      $keep = 3600 * 2; // 每秒 1 行 → 60min=3600；留 2 倍冗余应对多卡
      if (count($lines) > $keep) {
        nvgpu_atomic_write($histFile, implode("\n", array_slice($lines, -$keep)) . "\n");
      }
    }
  }
}

function nvgpu_read_json(string $file): ?array {
  if (!is_file($file)) return null;
  $raw = @file_get_contents($file);
  if ($raw === false || $raw === '') return null;
  $d = json_decode($raw, true);
  return is_array($d) ? $d : null;
}

/* ---------------------------------------------------------------- 推送 */

function nvgpu_publish(array $snapshot, array $cfg): void {
  if (strtolower((string)($cfg['PUSH'] ?? 'yes')) === 'no') return;
  $docroot = $GLOBALS['docroot'] ?? '/usr/local/emhttp';
  $payload = json_encode([
    'type' => 'sample',
    'ts'   => $snapshot['ts'],
    'gpus' => $snapshot['gpus'],
  ], JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
  if ($payload === false) return;

  // 直接打 nginx unix socket：免依赖 publish.php 的静态状态，返回 202 即成功
  $ch = @curl_init('http://localhost/pub/' . NVGPU_CHAN . '?buffer_length=1');
  if ($ch === false) return;
  curl_setopt_array($ch, [
    CURLOPT_UNIX_SOCKET_PATH => '/var/run/nginx.socket',
    CURLOPT_POST             => true,
    CURLOPT_POSTFIELDS       => $payload,
    CURLOPT_RETURNTRANSFER   => true,
    CURLOPT_TIMEOUT_MS       => 1500,
    CURLOPT_HTTPHEADER       => ['Accept: text/json'],
  ]);
  @curl_exec($ch);
  @curl_close($ch);
}
