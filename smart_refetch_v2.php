#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * smart_refetch_v2.php - Inteligentný systém na detekciu a opravu chýbajúcich dát
 * 
 * FEATURES:
 * - Detekuje oneskorené uploady (zmena last_known_timestamp)
 * - Kontroluje aj aktuálny deň (INCLUDE_TODAY=true)
 * - Collision avoidance s fetch_data.php
 * - Clean refetch pattern (fetch_data.php zmaže staré záznamy)
 * - Trackuje stav v refetch_state tabuľke
 * 
 * USAGE:
 *   php smart_refetch_v2.php                    # Normálny beh
 *   php smart_refetch_v2.php --dry-run          # Len ukáže čo by urobil
 *   php smart_refetch_v2.php --days=14          # Kontroluj 14 dní
 *   php smart_refetch_v2.php --no-today         # Nekontroluj dnešok
 *   php smart_refetch_v2.php --force            # Ignoruj lock files
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo "CLI only\n";
    exit;
}

date_default_timezone_set('Europe/Bratislava');

const LOG_FILE = __DIR__ . '/smart_refetch.log';
const LOCK_FILE = __DIR__ . '/smart_refetch.lock';
const FETCH_LOCK_FILE = __DIR__ . '/fetch_data.lock';

// ================================================================
// KONFIGURÁCIA - defaults
// ================================================================
$CHECK_DAYS = 7;                  // Koľko dní dozadu kontrolovať (+ dnešok ak INCLUDE_TODAY=true)
$MIN_RECORDS_PER_DAY = 10;        // Minimálny počet záznamov pre deň aby bol "OK"
$DRY_RUN = false;                 // Ak true, len vypíše čo by urobil
$FORCE = false;                   // Ignoruj lock files (opatrne!)
$INCLUDE_TODAY = true;            // DÔLEŽITÉ: Kontroluj aj dnešok (pre detekciu oneskorených uploadov)
$MAX_REFETCH_PER_RUN = 5;         // Maximum počet refetch na jedno spustenie
$DEBUG_MODE = false;              // Načíta sa z .env

// Parse CLI argumenty (môžu prepísať .env nastavenia)
$CLI_DAYS_OVERRIDE = null;
foreach ($argv as $arg) {
    if (preg_match('/^--days=(\d+)$/', $arg, $m)) {
        $CLI_DAYS_OVERRIDE = (int)$m[1];
    }
    if ($arg === '--dry-run') {
        $DRY_RUN = true;
    }
    if ($arg === '--force') {
        $FORCE = true;
    }
    if ($arg === '--no-today') {
        $INCLUDE_TODAY = false;
    }
}

// ================================================================
// HELPER FUNKCIE
// ================================================================

function info_log(string $msg): void {
    $ts = (new DateTimeImmutable())->format('Y-m-d\TH:i:sP');
    $line = "[$ts] $msg\n";
    file_put_contents(LOG_FILE, $line, FILE_APPEND);
    echo $line;
}

function debug_log(string $msg): void {
    global $DEBUG_MODE;
    if (!$DEBUG_MODE) return;
    info_log("[DEBUG] $msg");
}

function load_env(): void {
    global $DEBUG_MODE, $CHECK_DAYS, $CLI_DAYS_OVERRIDE;
    $env = __DIR__ . '/.env';
    if (is_file($env) && is_readable($env)) {
        foreach (file($env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            if ($line !== '' && $line[0] !== '#' && strpos($line, '=') !== false) {
                [$k,$v] = array_map('trim', explode('=', $line, 2));
                if ($k !== '') putenv("$k=$v");
            }
        }
    }

    // Načítaj DEBUG_MODE z .env
    $debugEnv = getenv('DEBUG_MODE');
    if ($debugEnv !== false && in_array(strtolower($debugEnv), ['true', '1', 'yes'])) {
        $DEBUG_MODE = true;
    }

    // Načítaj SMART_REFETCH_DAYS z .env (ak nie je CLI override)
    $envDays = getenv('SMART_REFETCH_DAYS');
    if ($envDays !== false && $envDays !== '' && $CLI_DAYS_OVERRIDE === null) {
        $CHECK_DAYS = (int)$envDays;
    } else if ($CLI_DAYS_OVERRIDE !== null) {
        $CHECK_DAYS = $CLI_DAYS_OVERRIDE;
    }
}

function db(): PDO {
    $path = getenv('SQLITE_PATH') ?: (__DIR__ . '/tracker_database.sqlite');
    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON;');
    return $pdo;
}

function ensure_refetch_state_table(PDO $pdo): void {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS refetch_state (
            date TEXT PRIMARY KEY,
            last_check_time TEXT NOT NULL,
            last_known_timestamp TEXT,
            last_known_count INTEGER DEFAULT 0,
            needs_refetch INTEGER DEFAULT 0,
            last_refetch_time TEXT,
            notes TEXT
        )
    ");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_refetch_date ON refetch_state(date)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_needs_refetch ON refetch_state(needs_refetch)");
}

// ================================================================
// HLAVNÝ SKRIPT
// ================================================================

// Prevent multiple instances
$lockFp = fopen(LOCK_FILE, 'c');
if (!flock($lockFp, LOCK_EX | LOCK_NB)) {
    info_log("Another smart_refetch instance is running, exiting");
    exit(0);
}

info_log("=== SMART REFETCH V2 START ===");
debug_log("Config: CHECK_DAYS=$CHECK_DAYS, MIN_RECORDS=$MIN_RECORDS_PER_DAY, " .
          "INCLUDE_TODAY=" . ($INCLUDE_TODAY ? 'YES' : 'NO') . ", " .
          "MAX_REFETCH=$MAX_REFETCH_PER_RUN, DRY_RUN=" . ($DRY_RUN ? 'YES' : 'NO'));

// ================================================================
// 1. COLLISION AVOIDANCE - Check if fetch_data.php is running
// ================================================================
if (!$FORCE && is_file(FETCH_LOCK_FILE)) {
    $lockAge = time() - filemtime(FETCH_LOCK_FILE);
    if ($lockAge < 300) { // 5 minút
        debug_log("fetch_data.php is running (lock age: {$lockAge}s), waiting...");
        
        // Počkaj max 2 minúty
        $waited = 0;
        while (is_file(FETCH_LOCK_FILE) && $waited < 120) {
            sleep(10);
            $waited += 10;
            $lockAge = time() - filemtime(FETCH_LOCK_FILE);
            if ($lockAge >= 300) {
                debug_log("fetch_data.php lock is stale, continuing");
                break;
            }
        }
        
        if (is_file(FETCH_LOCK_FILE)) {
            info_log("fetch_data.php still running after 2 minutes, exiting to avoid collision");
            flock($lockFp, LOCK_UN);
            fclose($lockFp);
            exit(0);
        }
        
        debug_log("fetch_data.php finished, continuing");
    } else {
        debug_log("fetch_data.php lock is stale ({$lockAge}s old), continuing");
    }
}

load_env();

try {
    $pdo = db();
    ensure_refetch_state_table($pdo);
} catch (Throwable $e) {
    info_log("ERROR: Cannot connect to database: " . $e->getMessage());
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    exit(1);
}

// ================================================================
// 1b. LOAD ACTIVE DEVICES
// ================================================================
$activeDevices = [];
try {
    $tables = [];
    $tq = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='devices'");
    while ($tr = $tq->fetch()) $tables[] = $tr['name'];

    if (in_array('devices', $tables)) {
        $dq = $pdo->query("SELECT id, name, device_eui FROM devices WHERE is_active = 1 ORDER BY id ASC");
        while ($dr = $dq->fetch()) {
            $activeDevices[] = ['id' => (int)$dr['id'], 'name' => $dr['name'], 'eui' => $dr['device_eui']];
        }
    }
} catch (Throwable $e) {
    debug_log("DEVICES: Could not load from DB: " . $e->getMessage());
}

// Fallback: single device mode (no device_id filter)
if (empty($activeDevices)) {
    $activeDevices[] = ['id' => null, 'name' => 'Default', 'eui' => ''];
    info_log("DEVICES: No devices table or no active devices, running in legacy mode");
} else {
    info_log("DEVICES: Found " . count($activeDevices) . " active device(s)");
}

// ================================================================
// 2. ANALÝZA - Per-device analysis for last X days
// ================================================================
$today = new DateTimeImmutable('now', new DateTimeZone('Europe/Bratislava'));
$startOffset = $INCLUDE_TODAY ? 0 : 1;

$all_problematic_days = [];
$all_stats_count = 0;

foreach ($activeDevices as $deviceInfo) {
$DEVICE_ID = $deviceInfo['id'];
$DEVICE_NAME = $deviceInfo['name'];

debug_log("Analyzing device '$DEVICE_NAME' (id=" . ($DEVICE_ID ?? 'null') . "): last $CHECK_DAYS days" . ($INCLUDE_TODAY ? ' + today' : '') . "...");

$stats = [];

for ($i = $startOffset; $i < $CHECK_DAYS + $startOffset; $i++) {
    $date = $today->sub(new DateInterval("P{$i}D"));
    $dateStr = $date->format('Y-m-d');

    $startLocal = new DateTimeImmutable($dateStr . ' 00:00:00', new DateTimeZone('Europe/Bratislava'));
    $endLocal = new DateTimeImmutable($dateStr . ' 23:59:59', new DateTimeZone('Europe/Bratislava'));
    $startUtc = $startLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $endUtc = $endLocal->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');

    // Count records per device
    if ($DEVICE_ID !== null) {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as record_count, MIN(timestamp) as first_record, MAX(timestamp) as last_record
            FROM tracker_data
            WHERE timestamp BETWEEN :start AND :end AND device_id = :did
        ");
        $stmt->execute([':start' => $startUtc, ':end' => $endUtc, ':did' => $DEVICE_ID]);
    } else {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) as record_count, MIN(timestamp) as first_record, MAX(timestamp) as last_record
            FROM tracker_data
            WHERE timestamp BETWEEN :start AND :end
        ");
        $stmt->execute([':start' => $startUtc, ':end' => $endUtc]);
    }
    $row = $stmt->fetch();

    // Get previous state from refetch_state (device-aware)
    if ($DEVICE_ID !== null) {
        $stmt = $pdo->prepare("SELECT * FROM refetch_state WHERE date = :date AND device_id = :did");
        $stmt->execute([':date' => $dateStr, ':did' => $DEVICE_ID]);
    } else {
        $stmt = $pdo->prepare("SELECT * FROM refetch_state WHERE date = :date");
        $stmt->execute([':date' => $dateStr]);
    }
    $previousState = $stmt->fetch();

    $stats[$dateStr] = [
        'date' => $dateStr,
        'record_count' => (int)$row['record_count'],
        'first_record' => $row['first_record'],
        'last_record' => $row['last_record'],
        'age_days' => $i,
        'previous_state' => $previousState
    ];
}

// ================================================================
// 3. DETEKCIA - Identifikuj problémové dni
// ================================================================
$problematic_days = [];

foreach ($stats as $dateStr => $stat) {
    $issues = [];
    $priority = 0;
    
    // Issue 1: Žiadne záznamy
    if ($stat['record_count'] === 0) {
        $issues[] = "NO_DATA";
        $priority += 10;
    }
    // Issue 2: Málo záznamov
    elseif ($stat['record_count'] < $MIN_RECORDS_PER_DAY) {
        $issues[] = "FEW_RECORDS ({$stat['record_count']})";
        $priority += 5;
    }
    
    // Issue 3: NOVÉ DÁTA DETEKOVANÉ (zmena posledného záznamu)
    // TOTO JE KĽÚČOVÉ PRE DETEKCIU ONESKORENÝCH UPLOADOV!
    if ($stat['previous_state'] && $stat['record_count'] > 0) {
        $prevTimestamp = $stat['previous_state']['last_known_timestamp'] ?? null;
        $prevCount = (int)($stat['previous_state']['last_known_count'] ?? 0);
        $currTimestamp = $stat['last_record'];
        $currCount = $stat['record_count'];
        
        // Porovnaj timestamp alebo count
        $timestampChanged = $prevTimestamp && $currTimestamp && $prevTimestamp !== $currTimestamp;
        $countChanged = $currCount !== $prevCount;
        
        if ($timestampChanged || $countChanged) {
            $issues[] = "NEW_DATA_DETECTED (prev: $prevCount @ " . substr($prevTimestamp ?? 'null', 0, 16) . 
                       ", now: $currCount @ " . substr($currTimestamp, 0, 16) . ")";
            $priority += 20; // NAJVYŠŠIA PRIORITA - nové dáta!
        }
    }
    
    // Issue 4: Veľká medzera v pokrytí
    if ($stat['record_count'] > 0 && $stat['first_record'] && $stat['last_record']) {
        try {
            $first = new DateTimeImmutable($stat['first_record']);
            $last = new DateTimeImmutable($stat['last_record']);
            $coverageHours = ($last->getTimestamp() - $first->getTimestamp()) / 3600;
            
            // Ak pokrytie < 12 hodín a málo záznamov
            if ($coverageHours < 12 && $stat['record_count'] < $MIN_RECORDS_PER_DAY * 2) {
                $issues[] = "GAP_DETECTED (coverage " . round($coverageHours, 1) . "h)";
                $priority += 3;
            }
        } catch (Throwable $e) {
            // Ignoruj parse errors
        }
    }
    
    if (count($issues) > 0) {
        $problematic_days[$dateStr] = [
            'date' => $dateStr,
            'issues' => $issues,
            'priority' => $priority,
            'record_count' => $stat['record_count'],
            'age_days' => $stat['age_days'],
            'last_record' => $stat['last_record']
        ];
    }
    
    // Update refetch_state (even if no issues - important for future comparison!)
    if ($DEVICE_ID !== null) {
        $stmt = $pdo->prepare("
            INSERT OR REPLACE INTO refetch_state
            (date, device_id, last_check_time, last_known_timestamp, last_known_count, needs_refetch, notes)
            VALUES (:date, :did, :check_time, :timestamp, :count, :needs, :notes)
        ");
        $stmt->execute([
            ':date' => $dateStr,
            ':did' => $DEVICE_ID,
            ':check_time' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ':timestamp' => $stat['last_record'],
            ':count' => $stat['record_count'],
            ':needs' => count($issues) > 0 ? 1 : 0,
            ':notes' => count($issues) > 0 ? implode(', ', $issues) : null
        ]);
    } else {
        $stmt = $pdo->prepare("
            INSERT OR REPLACE INTO refetch_state
            (date, last_check_time, last_known_timestamp, last_known_count, needs_refetch, notes)
            VALUES (:date, :check_time, :timestamp, :count, :needs, :notes)
        ");
        $stmt->execute([
            ':date' => $dateStr,
            ':check_time' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ':timestamp' => $stat['last_record'],
            ':count' => $stat['record_count'],
            ':needs' => count($issues) > 0 ? 1 : 0,
            ':notes' => count($issues) > 0 ? implode(', ', $issues) : null
        ]);
    }
}

// Sort by priority
uasort($problematic_days, function($a, $b) {
    return $b['priority'] <=> $a['priority'];
});

if (count($problematic_days) > 0) {
    info_log("Device '$DEVICE_NAME': Found " . count($problematic_days) . " problematic days");
    foreach ($problematic_days as $day) {
        info_log(sprintf(
            "  %s [priority=%d, records=%d, age=%dd]: %s",
            $day['date'],
            $day['priority'],
            $day['record_count'],
            $day['age_days'],
            implode(', ', $day['issues'])
        ));
        // Add device info to each problematic day
        $day['device_id'] = $DEVICE_ID;
        $day['device_name'] = $DEVICE_NAME;
        $all_problematic_days[] = $day;
    }
} else {
    info_log("Device '$DEVICE_NAME': ✓ No issues detected.");
}

$all_stats_count += count($stats);

} // end foreach activeDevices

// Sort all problematic days by priority
usort($all_problematic_days, function($a, $b) {
    return $b['priority'] <=> $a['priority'];
});

$problematic_days = $all_problematic_days;
info_log("Total across all devices: " . count($problematic_days) . " problematic day(s)");

if (count($problematic_days) === 0) {
    info_log("✓ No issues detected across all devices.");
    info_log("=== SMART REFETCH V2 END ===");
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    exit(0);
}

// ================================================================
// 4. REFETCH - Oprav problémové dni
// ================================================================
if ($DRY_RUN) {
    info_log("DRY RUN: Would refetch " . count($problematic_days) . " days");
    info_log("=== SMART REFETCH V2 END (DRY RUN) ===");
    flock($lockFp, LOCK_UN);
    fclose($lockFp);
    exit(0);
}

$refetch_count = 0;
$success_count = 0;
$fail_count = 0;

// Limit: max $MAX_REFETCH_PER_RUN dní na jedno spustenie
$max_refetch = min($MAX_REFETCH_PER_RUN, count($problematic_days));
info_log("Will refetch top $max_refetch days (out of " . count($problematic_days) . " problematic)");

// Deduplicate: only refetch each date once (fetch_data.php handles all devices)
$uniqueDates = [];
foreach (array_slice($problematic_days, 0, $max_refetch) as $day) {
    $dateStr = $day['date'];
    if (!isset($uniqueDates[$dateStr])) {
        $uniqueDates[$dateStr] = $day;
    }
}

foreach ($uniqueDates as $dateStr => $day) {
    $deviceLabel = isset($day['device_name']) ? " [{$day['device_name']}]" : '';
    info_log("Refetching $dateStr (priority={$day['priority']})$deviceLabel...");

    $scriptPath = __DIR__ . '/fetch_data.php';
    if (!is_file($scriptPath)) {
        info_log("  ✗ ERROR: fetch_data.php not found");
        $fail_count++;
        continue;
    }

    $envPhp = getenv('PHP_CLI_PATH');
    if ($envPhp && $envPhp !== '') {
        $phpBin = $envPhp;
    } else {
        $phpBin = 'php';
    }

    // Run fetch_data.php in refetch mode (it will iterate all active devices)
    $cmd = sprintf(
        '%s %s --refetch-date=%s 2>&1',
        escapeshellarg($phpBin),
        escapeshellarg($scriptPath),
        escapeshellarg($dateStr)
    );

    $startTime = microtime(true);
    $output = [];
    $returnCode = 0;
    exec($cmd, $output, $returnCode);
    $elapsed = round(microtime(true) - $startTime, 1);

    if ($returnCode === 0) {
        info_log("  ✓ Success ({$elapsed}s)");
        $success_count++;

        // Update refetch_state for all devices that had this date problematic
        $deviceId = $day['device_id'] ?? null;
        if ($deviceId !== null) {
            $stmt = $pdo->prepare("
                UPDATE refetch_state
                SET last_refetch_time = :refetch_time, needs_refetch = 0
                WHERE date = :date AND device_id = :did
            ");
            $stmt->execute([
                ':refetch_time' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                ':date' => $dateStr,
                ':did' => $deviceId
            ]);
        } else {
            $stmt = $pdo->prepare("
                UPDATE refetch_state
                SET last_refetch_time = :refetch_time, needs_refetch = 0
                WHERE date = :date
            ");
            $stmt->execute([
                ':refetch_time' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                ':date' => $dateStr
            ]);
        }
    } else {
        info_log("  ✗ Failed with code $returnCode ({$elapsed}s)");
        if ($DEBUG_MODE) {
            info_log("  Output: " . implode("\n  ", array_slice($output, -5))); // Posledných 5 riadkov
        }
        $fail_count++;
    }
    
    $refetch_count++;
    
    // Pause between refetches (to not overload SenseCAP API)
    // Pauza medzi refetch
    if ($refetch_count < count($uniqueDates)) {
        debug_log("  Sleeping 30 seconds before next refetch...");
        sleep(30);
    }
}

info_log("=== SMART REFETCH V2 END ===");
info_log("Summary: refetched=$refetch_count, success=$success_count, failed=$fail_count");

if (count($problematic_days) > $max_refetch) {
    $remaining = count($problematic_days) - $max_refetch;
    info_log("NOTE: $remaining problematic days remaining (will be processed in next run)");
}

flock($lockFp, LOCK_UN);
fclose($lockFp);

exit($fail_count > 0 ? 1 : 0);
