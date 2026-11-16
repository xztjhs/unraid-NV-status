<?PHP
/* nvidia-gpu-status :: 只读 API
 *
 * GET  api.php                    → 最新一帧（cache: no-store）
 * GET  api.php?window=300         → 附带抽稀后的历史序列（<=360 点）
 * GET  api.php?action=refresh     → 触发一次即时采集（无守护进程时的兜底）
 * GET  api.php?action=selftest    → 环境自检
 *
 * 只读，无副作用（除 refresh 会写 shm），不需要 CSRF 之外的额外保护：
 * Unraid nginx 已对 /plugins/* 做 auth_request。
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

$docroot ??= ($_SERVER['DOCUMENT_ROOT'] ?: '/usr/local/emhttp');
require_once "$docroot/plugins/nvidia-gpu-status/include/collect.php";

function out(array $data, int $code = 200): void {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
  exit;
}

$cfg = nvgpu_cfg();
$shm = NVGPU_SHM;
$action = $_GET['action'] ?? '';

/* ---------------------------------------------------------------- 环境自检 */

if ($action === 'selftest') {
  $pidFile = '/var/run/nvidia-gpu-status.pid';
  $pid = is_file($pidFile) ? (int)@file_get_contents($pidFile) : 0;
  out([
    'ok' => true,
    'nvidia_smi' => is_executable('/usr/bin/nvidia-smi'),
    'daemon_running' => $pid > 0 && @posix_kill($pid, 0),
    'daemon_pid' => $pid ?: null,
    'shm_writable' => is_dir($shm) ? is_writable($shm) : is_writable(dirname($shm)),
    'latest_exists' => is_file("$shm/latest.json"),
    'latest_age_s' => is_file("$shm/latest.json") ? max(0, time() - (int)@filemtime("$shm/latest.json")) : null,
    'hist_lines' => is_file("$shm/hist.jsonl")
      ? count(@file("$shm/hist.jsonl", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])
      : 0,
    'php' => PHP_VERSION,
    'cfg' => $cfg,
    'push_channel' => NVGPU_CHAN,
  ]);
}

/* ---------------------------------------------------------------- 兜底即时采集 */

if ($action === 'refresh') {
  if (!is_executable('/usr/bin/nvidia-smi')) out(['ok' => false, 'error' => 'nvidia-smi 不存在'], 503);
  $line = @shell_exec('timeout 5 nvidia-smi --query-gpu=' . nvgpu_fast_fields()
    . ' --format=csv,noheader,nounits 2>/dev/null | head -1');
  $g = $line ? nvgpu_parse_fast($line) : null;
  if (!$g) out(['ok' => false, 'error' => 'nvidia-smi 无输出'], 503);
  $merged = nvgpu_merge($g, nvgpu_slow_collect());
  $merged['flags'] = nvgpu_flags($merged, $cfg);
  $snap = ['ts' => microtime(true), 'ok' => true, 'gpus' => [$merged], 'slow_ok' => true, 'cfg' => [
    'sample_ms' => (int)$cfg['SAMPLE_MS'], 'refresh_ms' => (int)$cfg['REFRESH_MS'],
    'window_min' => (int)$cfg['WINDOW_MIN'], 'temp_warn' => (float)$cfg['TEMP_WARN'],
    'show_proc' => strtolower((string)$cfg['SHOW_PROCESSES']) !== 'no',
    'push' => false,
  ]];
  nvgpu_store($shm, $snap, $cfg);
  out($snap);
}

/* ---------------------------------------------------------------- 常规读取 */

$latest = nvgpu_read_json("$shm/latest.json");
$windowSec = (int)($_GET['window'] ?? 0);

if (!$latest) {
  // 守护进程未跑：尝试现场采一帧，避免页面空白
  if (is_executable('/usr/bin/nvidia-smi')) {
    $line = @shell_exec('timeout 5 nvidia-smi --query-gpu=' . nvgpu_fast_fields()
      . ' --format=csv,noheader,nounits 2>/dev/null | head -1');
    $g = $line ? nvgpu_parse_fast($line) : null;
    if ($g) {
      $merged = nvgpu_merge($g, ['ok' => false, 'gpus' => []]);
      $merged['flags'] = nvgpu_flags($merged, $cfg);
      $latest = ['ts' => microtime(true), 'ok' => true, 'gpus' => [$merged], 'slow_ok' => false,
        'cfg' => ['sample_ms' => (int)$cfg['SAMPLE_MS'], 'refresh_ms' => (int)$cfg['REFRESH_MS'],
                  'window_min' => (int)$cfg['WINDOW_MIN'], 'temp_warn' => (float)$cfg['TEMP_WARN'],
                  'show_proc' => strtolower((string)$cfg['SHOW_PROCESSES']) !== 'no',
                  'push' => false],
        'degraded' => 'daemon'];
    }
  }
  if (!$latest) {
    out(['ok' => false, 'error' => 'no data', 'hint' => '采样器未运行，请检查 nvidia-driver 插件与 nvidia-gpu-status 服务'], 503);
  }
}

$latest['age_s'] = max(0, time() - (int)@filemtime("$shm/latest.json"));

/* 历史抽稀：窗口内最多 360 点 */
if ($windowSec > 0) {
  $histFile = "$shm/hist.jsonl";
  $series = [];
  if (is_file($histFile)) {
    $now = time();
    $from = $now - max(60, min(3600, $windowSec));
    $rows = @file($histFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($rows as $r) {
      $d = json_decode($r, true);
      if (!is_array($d) || ($d['t'] ?? 0) < $from) continue;
      $series[] = $d;
    }
    $total = count($series);
    if ($total > 360) {
      $step = (int)ceil($total / 360);
      $thin = [];
      for ($i = 0; $i < $total; $i += $step) $thin[] = $series[$i];
      // 保证末点在内
      if (end($thin) !== end($series)) $thin[] = end($series);
      $series = $thin;
    }
  }
  $latest['hist'] = $series;
  $latest['hist_window_s'] = $windowSec;
}

out($latest);
