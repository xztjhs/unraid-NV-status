#!/usr/bin/php -q
<?PHP
/* nvidia-gpu-status :: 采样守护进程
 *
 * 单进程 + 两个子进程（proc_open），stream_select 多路复用：
 *   fast : nvidia-smi --query-gpu=<快层> -lms <SAMPLE_MS>   （常驻，改间隔需重启）
 *   slow : nvidia-smi -q -x                                  （每 SLOW_SEC 秒一次）
 *
 * 合并结果原子写入 /dev/shm/nvidia-gpu-status/latest.json，并 append 历史，
 * 同时向 nchan 频道 nvgpu 推送（有订阅者时前端零延迟）。
 *
 * 启动： bash /etc/rc.d/rc.nvidia-gpu-status start
 */

$docroot ??= '/usr/local/emhttp';
require_once "$docroot/plugins/nvidia-gpu-status/include/collect.php";

$cfg     = nvgpu_cfg();
$shm     = NVGPU_SHM;
$pidFile = '/var/run/nvidia-gpu-status.pid';
$logFile = '/var/log/nvidia-gpu-status.log';

@mkdir($shm, 0755, true);

function nvlog(string $msg): void {
  global $logFile;
  static $lastSize = 0;
  $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n";
  @file_put_contents($logFile, $line, FILE_APPEND);
  // 日志超 512KB 截断，避免写满 /var/log tmpfs
  $s = @filesize($logFile);
  if ($s !== false && $s > 524288) {
    $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    @file_put_contents($logFile, implode("\n", array_slice($lines, -1500)) . "\n");
  }
}

/* ---------------------------------------------------------------- 单实例 */

if (is_file($pidFile)) {
  $old = (int)@file_get_contents($pidFile);
  if ($old > 0 && $old !== getmypid() && @posix_kill($old, 0)) {
    fwrite(STDERR, "nvidia-gpu-status sampler already running (pid $old)\n");
    exit(0);
  }
}

/* 未装驱动直接退出（页面会显示提示，不刷错误） */
if (!is_executable('/usr/bin/nvidia-smi')) {
  nvlog('nvidia-smi 不存在，采样器退出（请先安装 nvidia-driver 插件）');
  exit(0);
}

@file_put_contents($pidFile, (string)getmypid());

/* ---------------------------------------------------------------- 子进程 */

function spawn_fast(int $sampleMs) {
  $cmd = 'exec nvidia-smi --query-gpu=' . nvgpu_fast_fields()
       . ' --format=csv,noheader,nounits -lms ' . max(200, $sampleMs);
  $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
  $p = @proc_open($cmd, $spec, $pipes);
  if (!is_resource($p)) return null;
  stream_set_blocking($pipes[1], false);
  stream_set_blocking($pipes[2], false);
  return ['proc' => $p, 'out' => $pipes[1], 'err' => $pipes[2], 'buf' => ''];
}

function spawn_slow() {
  $spec = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
  $p = @proc_open('exec nvidia-smi -q -x 2>/dev/null', $spec, $pipes);
  if (!is_resource($p)) return null;
  stream_set_blocking($pipes[1], false);
  stream_set_blocking($pipes[2], false);
  return ['proc' => $p, 'out' => $pipes[1], 'err' => $pipes[2], 'buf' => ''];
}

function close_child(?array &$c): void {
  if (!$c) return;
  foreach (['out', 'err'] as $k) {
    if (isset($c[$k]) && is_resource($c[$k])) @fclose($c[$k]);
  }
  if (isset($c['proc']) && is_resource($c['proc'])) @proc_terminate($c['proc'], 15);
  if (isset($c['proc']) && is_resource($c['proc'])) @proc_close($c['proc']);
  $c = null;
}

/* ---------------------------------------------------------------- 主循环 */

$sampleMs  = max(200, min(10000, (int)$cfg['SAMPLE_MS']));
$slowSec   = max(5, min(120, (int)$cfg['SLOW_SEC']));
$cfgMtime  = @filemtime('/boot/config/plugins/' . NVGPU_PLUGIN . '/' . NVGPU_PLUGIN . '.cfg') ?: 0;

$fast = spawn_fast($sampleMs);
$slow = null;
$slowData = ['ok' => false, 'gpus' => []];
$nextSlowAt = microtime(true);          // 立即跑一次慢层
$lastFlush = 0.0;
$lastCfgCheck = 0.0;
$lastPublish = 0.0;
$failCount = 0;

nvlog("sampler start: sample={$sampleMs}ms slow={$slowSec}s push=" . ($cfg['PUSH'] ?? 'yes'));

function nvgpu_shutdown_all($fast, $slow, $pidFile): void {
  close_child($fast);
  close_child($slow);
  @unlink($pidFile);
}

register_shutdown_function('nvgpu_shutdown_all', $fast, $slow, $pidFile);

while (true) {
  $now = microtime(true);

  /* 配置变更 → 热重载采样间隔 */
  if ($now - $lastCfgCheck > 15) {
    $lastCfgCheck = $now;
    $m = @filemtime('/boot/config/plugins/' . NVGPU_PLUGIN . '/' . NVGPU_PLUGIN . '.cfg') ?: 0;
    if ($m && $m !== $cfgMtime) {
      $cfgMtime = $m;
      $newCfg = nvgpu_cfg();
      if (!nvgpu_is_enabled($newCfg)) { nvlog('cfg: disabled -> exit'); break; }
      $newSample = max(200, min(10000, (int)$newCfg['SAMPLE_MS']));
      $slowSec   = max(5, min(120, (int)$newCfg['SLOW_SEC']));
      $cfg = $newCfg;
      if ($newSample !== $sampleMs) {
        $sampleMs = $newSample;
        close_child($fast);
        $fast = spawn_fast($sampleMs);
        nvlog("cfg reload: sample={$sampleMs}ms slow={$slowSec}s");
      }
    }
  }

  /* fast 子进程死了 → 重建 */
  if (!$fast) {
    $fast = spawn_fast($sampleMs);
    if (!$fast) { sleep(2); continue; }
    nvlog('fast child respawned');
  }

  /* 慢层调度 */
  if (!$slow && $now >= $nextSlowAt) {
    $slow = spawn_slow();
    if (!$slow) $nextSlowAt = $now + $slowSec;
  }

  /* 多路复用等待 */
  $read = [];
  if ($fast && is_resource($fast['out'])) $read[] = $fast['out'];
  if ($slow && is_resource($slow['out'])) $read[] = $slow['out'];
  if (!$read) { usleep(200000); continue; }

  $w = null; $e = null;
  $sec = 0; $usec = 200000;
  $n = @stream_select($read, $w, $e, $sec, $usec);
  if ($n === false) { usleep(100000); continue; }

  /* --- fast 行解析 --- */
  if ($fast && is_resource($fast['out']) && in_array($fast['out'], $read, true)) {
    $chunk = @fread($fast['out'], 65536);
    if ($chunk === '' || $chunk === false) {
      if (@feof($fast['out'])) { nvlog('fast child EOF, respawn'); close_child($fast); }
    } else {
      $fast['buf'] .= $chunk;
      while (($pos = strpos($fast['buf'], "\n")) !== false) {
        $line = substr($fast['buf'], 0, $pos);
        $fast['buf'] = substr($fast['buf'], $pos + 1);
        $g = nvgpu_parse_fast($line);
        if (!$g) continue;

        $merged = nvgpu_merge($g, $slowData);
        $merged['flags'] = nvgpu_flags($merged, $cfg);

        $snapshot = [
          'ts'      => microtime(true),
          'ok'      => true,
          'gpus'    => [$merged],
          'slow_ok' => (bool)($slowData['ok'] ?? false),
          'cfg'     => [
            'sample_ms'  => $sampleMs,
            'refresh_ms' => (int)($cfg['REFRESH_MS'] ?? 2000),
            'window_min' => (int)($cfg['WINDOW_MIN'] ?? 5),
            'temp_warn'  => (float)($cfg['TEMP_WARN'] ?? 85),
            'show_proc'  => strtolower((string)($cfg['SHOW_PROCESSES'] ?? 'yes')) !== 'no',
            'push'       => strtolower((string)($cfg['PUSH'] ?? 'yes')) !== 'no',
          ],
        ];

        nvgpu_store($shm, $snapshot, $cfg);

        /* 推送节流：最多 4 次/秒，够 0.5s 刷新用 */
        if ($now - $lastPublish >= 0.25) {
          $lastPublish = $now;
          nvgpu_publish($snapshot, $cfg);
        }
        $lastFlush = $now;
        $failCount = 0;
      }
    }
  }

  /* --- slow XML 收全后解析 --- */
  if ($slow && is_resource($slow['out']) && in_array($slow['out'], $read, true)) {
    $chunk = @fread($slow['out'], 131072);
    if ($chunk === '' || $chunk === false) {
      if (@feof($slow['out'])) {
        if (strpos($slow['buf'], '</nvidia_smi_log>') !== false) {
          $parsed = nvgpu_parse_slow_xml($slow['buf']);
          if ($parsed['ok']) { $slowData = $parsed; }
          else nvlog('slow xml parse failed');
        } else {
          nvlog('slow xml truncated (' . strlen($slow['buf']) . ' bytes)');
        }
        close_child($slow);
        $nextSlowAt = microtime(true) + $slowSec;
      }
    } else {
      $slow['buf'] .= $chunk;
      // 防御：超长缓冲直接丢弃
      if (strlen($slow['buf']) > 4194304) { nvlog('slow buf overflow, dropping'); close_child($slow); $nextSlowAt = microtime(true) + $slowSec; }
    }
  }

  /* 派生死循环轮询检测（无输出 5s 视为异常） */
  if ($lastFlush > 0 && $now - $lastFlush > 30) {
    $failCount++;
    if ($failCount >= 2) {
      nvlog('no fast data for 60s, restarting fast child');
      close_child($fast);
      $fast = spawn_fast($sampleMs);
      $failCount = 0;
      $lastFlush = $now;
    }
  }
}

close_child($fast);
close_child($slow);
@unlink($pidFile);
nvlog('sampler stop');
