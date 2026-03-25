<?php
declare(strict_types=1);
/**
 * fetch_worker_pool.php – Parallel fetch orchestrator for 100+ devices
 *
 * Splits active devices into batches and runs fetch_data.php in parallel
 * worker processes. Uses PostgreSQL advisory locks to prevent conflicts.
 *
 * Usage:
 *   php fetch_worker_pool.php                    # Auto-detect workers
 *   php fetch_worker_pool.php --workers=10       # Explicit worker count
 *   php fetch_worker_pool.php --batch-size=5     # Devices per worker
 *   php fetch_worker_pool.php --dry-run          # Show plan without executing
 *   php fetch_worker_pool.php --refetch-date=2025-01-15  # Pass to workers
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo "Not found\n";
    exit;
}

date_default_timezone_set('Europe/Bratislava');

// Load env
$envPath = __DIR__ . '/.env';
if (is_file($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $v = trim($v, " \t\n\r\0\x0B\"'");
        if ($k !== '') putenv("$k=$v");
    }
}

// Log file
$LOG_FILE = getenv('LOG_FILE') ?: (is_dir('/var/log/tracker') ? '/var/log/tracker/fetch.log' : __DIR__ . '/fetch.log');

function pool_log(string $msg): void {
    global $LOG_FILE;
    $ts = (new DateTimeImmutable())->format('Y-m-d\TH:i:sP');
    file_put_contents($LOG_FILE, "[$ts] [INFO] [POOL] $msg\n", FILE_APPEND);
    echo "[$ts] [POOL] $msg\n";
}

// Parse CLI options
$options = getopt('', ['workers::', 'batch-size::', 'dry-run', 'refetch-date::', 'device-ids::']);
$maxWorkers = (int)($options['workers'] ?? getenv('FETCH_WORKERS') ?: 0);
$batchSize = (int)($options['batch-size'] ?? getenv('FETCH_BATCH_SIZE') ?: 5);
$dryRun = isset($options['dry-run']);
$refetchDate = $options['refetch-date'] ?? null;
$specificDeviceIds = isset($options['device-ids']) ? array_map('intval', explode(',', $options['device-ids'])) : null;

// Lock file for pool orchestrator
$POOL_LOCK = '/tmp/tracker_fetch_pool.lock';
$lockFp = fopen($POOL_LOCK, 'w');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    pool_log("Another worker pool is already running. Exiting.");
    exit(0);
}
fwrite($lockFp, (string)getmypid());

// Connect to DB
require_once __DIR__ . '/config.php';
$pdo = get_pdo();

// Load active devices
$sql = "SELECT id, name, device_eui FROM devices WHERE is_active = 1 ORDER BY id ASC";
if ($specificDeviceIds) {
    $placeholders = implode(',', $specificDeviceIds);
    $sql = "SELECT id, name, device_eui FROM devices WHERE is_active = 1 AND id IN ($placeholders) ORDER BY id ASC";
}
$devices = $pdo->query($sql)->fetchAll();

$deviceCount = count($devices);
if ($deviceCount === 0) {
    pool_log("No active devices found. Exiting.");
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    exit(0);
}

// Auto-determine worker count based on device count
if ($maxWorkers <= 0) {
    if ($deviceCount <= 5) {
        $maxWorkers = 1; // Sequential for few devices (original behavior)
    } elseif ($deviceCount <= 20) {
        $maxWorkers = 4;
    } elseif ($deviceCount <= 50) {
        $maxWorkers = 8;
    } else {
        $maxWorkers = min(16, (int)ceil($deviceCount / $batchSize));
    }
}

// CPU limit
$cpuCount = 1;
if (is_file('/proc/cpuinfo')) {
    $cpuCount = max(1, substr_count(file_get_contents('/proc/cpuinfo'), 'processor'));
}
$maxWorkers = min($maxWorkers, $cpuCount * 4); // Max 4x CPU cores

// Split devices into batches
$batches = array_chunk($devices, $batchSize);
$batchCount = count($batches);

pool_log("=== WORKER POOL START ===");
pool_log("Devices: $deviceCount | Workers: $maxWorkers | Batch size: $batchSize | Batches: $batchCount");

if ($dryRun) {
    pool_log("DRY RUN - Execution plan:");
    foreach ($batches as $i => $batch) {
        $names = array_map(fn($d) => "{$d['name']}({$d['device_eui']})", $batch);
        pool_log("  Batch " . ($i + 1) . ": " . implode(', ', $names));
    }
    pool_log("Would use $maxWorkers parallel worker(s)");
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    exit(0);
}

// For small device counts, just run fetch_data.php directly (no pool overhead)
if ($deviceCount <= 5 && !$refetchDate) {
    pool_log("Few devices ($deviceCount) - running single fetch_data.php process");
    $cmd = 'php ' . escapeshellarg(__DIR__ . '/fetch_data.php');
    if ($refetchDate) {
        $cmd .= ' --refetch-date=' . escapeshellarg($refetchDate);
    }
    passthrough($cmd, $exitCode);
    pool_log("Single process completed with exit code: $exitCode");
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    exit($exitCode);
}

// Run batches in parallel using proc_open
$running = [];
$completed = 0;
$failed = 0;
$startTime = microtime(true);
$batchQueue = $batches;

while (!empty($batchQueue) || !empty($running)) {
    // Launch new workers up to max
    while (!empty($batchQueue) && count($running) < $maxWorkers) {
        $batch = array_shift($batchQueue);
        $batchIndex = $batchCount - count($batchQueue) - count($running);
        $deviceIds = implode(',', array_map(fn($d) => $d['id'], $batch));
        $deviceNames = implode(', ', array_map(fn($d) => $d['name'], $batch));

        // Run fetch_data.php with --device-ids to process specific devices
        $cmd = 'php ' . escapeshellarg(__DIR__ . '/fetch_data.php') . " --device-ids=$deviceIds";
        if ($refetchDate) {
            $cmd .= ' --refetch-date=' . escapeshellarg($refetchDate);
        }

        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $proc = proc_open($cmd, $descriptors, $pipes);
        if (is_resource($proc)) {
            // Set pipes to non-blocking
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);

            $running[] = [
                'proc' => $proc,
                'pipes' => $pipes,
                'device_ids' => $deviceIds,
                'device_names' => $deviceNames,
                'start' => microtime(true),
            ];
            pool_log("Worker started: [$deviceNames] (IDs: $deviceIds)");
        } else {
            pool_log("ERROR: Failed to start worker for devices: $deviceNames");
            $failed++;
        }
    }

    // Check running workers
    $stillRunning = [];
    foreach ($running as $worker) {
        $status = proc_get_status($worker['proc']);
        if ($status['running']) {
            $stillRunning[] = $worker;
        } else {
            // Worker finished
            $exitCode = $status['exitcode'];
            $duration = round(microtime(true) - $worker['start'], 1);

            // Read remaining output
            $stdout = stream_get_contents($worker['pipes'][1]);
            $stderr = stream_get_contents($worker['pipes'][2]);
            fclose($worker['pipes'][1]);
            fclose($worker['pipes'][2]);
            proc_close($worker['proc']);

            if ($exitCode === 0) {
                $completed++;
                pool_log("Worker done ({$duration}s): [{$worker['device_names']}]");
            } else {
                $failed++;
                pool_log("Worker FAILED (exit=$exitCode, {$duration}s): [{$worker['device_names']}]");
                if ($stderr) {
                    pool_log("  STDERR: " . substr($stderr, 0, 500));
                }
            }
        }
    }
    $running = $stillRunning;

    // Brief sleep to avoid busy waiting
    if (!empty($running)) {
        usleep(200000); // 200ms
    }
}

$totalDuration = round(microtime(true) - $startTime, 1);
pool_log("=== WORKER POOL DONE: completed=$completed failed=$failed duration={$totalDuration}s ===");

// Cleanup
flock($lockFp, LOCK_UN);
fclose($lockFp);

exit($failed > 0 ? 1 : 0);
