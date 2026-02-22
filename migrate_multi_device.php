#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * migrate_multi_device.php - Migration script for multi-device support
 *
 * This script:
 * 1. Backs up the database
 * 2. Creates the `devices` table
 * 3. Creates the `device_perimeters` table
 * 4. Adds `device_id` column to `tracker_data`
 * 5. Adds `device_id` column to `perimeters`, `perimeter_state`, `perimeter_alerts`
 * 6. Adds `device_id` column to `refetch_state`
 * 7. Migrates existing data to a default device (from .env EUI)
 * 8. Creates necessary indexes
 *
 * Usage:
 *   php migrate_multi_device.php              # Run migration
 *   php migrate_multi_device.php --dry-run    # Show what would be done
 *   php migrate_multi_device.php --force      # Run even if already migrated
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo "CLI only\n";
    exit;
}

// Load .env
function load_env(string $path): void {
    if (!is_file($path) || !is_readable($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $v = trim($v, " \t\n\r\0\x0B\"'");
        if ($k !== '') putenv("$k=$v");
    }
}

load_env(__DIR__ . '/.env');

$DRY_RUN = in_array('--dry-run', $argv);
$FORCE = in_array('--force', $argv);

$dbPath = getenv('SQLITE_PATH') ?: (__DIR__ . '/tracker_database.sqlite');

echo "======================================\n";
echo "Multi-Device Migration Script\n";
echo "======================================\n";
echo "Database: $dbPath\n";
echo "Mode: " . ($DRY_RUN ? 'DRY RUN' : 'LIVE') . "\n\n";

if (!is_file($dbPath)) {
    echo "ERROR: Database file not found: $dbPath\n";
    exit(1);
}

// Step 1: Backup database
$backupPath = $dbPath . '.backup_' . date('Y-m-d_His');
echo "Step 1: Backing up database...\n";
if (!$DRY_RUN) {
    if (!copy($dbPath, $backupPath)) {
        echo "ERROR: Failed to backup database to $backupPath\n";
        exit(1);
    }
    echo "  Backup saved to: $backupPath\n";
} else {
    echo "  [DRY RUN] Would backup to: $backupPath\n";
}

// Connect to database
$pdo = new PDO('sqlite:' . $dbPath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$pdo->exec('PRAGMA foreign_keys = ON;');

// Check if migration already ran
$tables = [];
$q = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
while ($r = $q->fetch()) {
    $tables[] = $r['name'];
}

if (in_array('devices', $tables) && !$FORCE) {
    echo "\nMigration already applied (devices table exists).\n";
    echo "Use --force to run again.\n";
    exit(0);
}

echo "\nStep 2: Creating devices table...\n";
$sql = "CREATE TABLE IF NOT EXISTS devices (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    device_eui TEXT NOT NULL UNIQUE,
    color TEXT NOT NULL DEFAULT '#3388ff',
    icon TEXT DEFAULT 'default',
    is_active INTEGER NOT NULL DEFAULT 1,
    notifications_enabled INTEGER DEFAULT 0,
    notification_email TEXT,
    notification_webhook TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
)";
if (!$DRY_RUN) {
    $pdo->exec($sql);
    echo "  Created devices table.\n";
} else {
    echo "  [DRY RUN] Would create devices table.\n";
}

echo "\nStep 3: Creating device_perimeters table...\n";
$sql = "CREATE TABLE IF NOT EXISTS device_perimeters (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    device_id INTEGER NOT NULL,
    name TEXT NOT NULL,
    latitude REAL NOT NULL,
    longitude REAL NOT NULL,
    radius_meters REAL NOT NULL DEFAULT 500,
    alert_on_enter INTEGER DEFAULT 0,
    alert_on_exit INTEGER DEFAULT 0,
    is_active INTEGER DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
)";
if (!$DRY_RUN) {
    $pdo->exec($sql);
    echo "  Created device_perimeters table.\n";
} else {
    echo "  [DRY RUN] Would create device_perimeters table.\n";
}

echo "\nStep 4: Creating default device from .env...\n";
$eui = getenv('SENSECAP_DEVICE_EUI') ?: '';
$deviceName = getenv('DEVICE_NAME') ?: 'Predvolené zariadenie';

if ($eui === '') {
    echo "  WARNING: No SENSECAP_DEVICE_EUI in .env, using placeholder 'UNKNOWN'\n";
    $eui = 'UNKNOWN';
}

if (!$DRY_RUN) {
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO devices (name, device_eui, color, icon, is_active) VALUES (:name, :eui, '#3388ff', 'default', 1)");
    $stmt->execute([':name' => $deviceName, ':eui' => $eui]);

    $stmt = $pdo->prepare("SELECT id FROM devices WHERE device_eui = :eui");
    $stmt->execute([':eui' => $eui]);
    $device = $stmt->fetch();
    $defaultDeviceId = (int)$device['id'];
    echo "  Created default device: id=$defaultDeviceId, name='$deviceName', eui='$eui'\n";
} else {
    $defaultDeviceId = 1;
    echo "  [DRY RUN] Would create default device: name='$deviceName', eui='$eui'\n";
}

echo "\nStep 5: Adding device_id column to tracker_data...\n";
// Check if column already exists
$columns = [];
$q = $pdo->query("PRAGMA table_info(tracker_data)");
while ($r = $q->fetch()) {
    $columns[] = $r['name'];
}

if (!in_array('device_id', $columns)) {
    if (!$DRY_RUN) {
        $pdo->exec("ALTER TABLE tracker_data ADD COLUMN device_id INTEGER DEFAULT $defaultDeviceId REFERENCES devices(id)");
        echo "  Added device_id column with default=$defaultDeviceId\n";

        // Update existing rows
        $stmt = $pdo->prepare("UPDATE tracker_data SET device_id = :did WHERE device_id IS NULL");
        $stmt->execute([':did' => $defaultDeviceId]);
        $count = $stmt->rowCount();
        echo "  Updated $count existing rows to device_id=$defaultDeviceId\n";
    } else {
        echo "  [DRY RUN] Would add device_id column and update existing rows.\n";
    }
} else {
    echo "  Column device_id already exists in tracker_data.\n";
}

echo "\nStep 6: Adding device_id to perimeters table...\n";
$columns = [];
$q = $pdo->query("PRAGMA table_info(perimeters)");
while ($r = $q->fetch()) {
    $columns[] = $r['name'];
}

if (!in_array('device_id', $columns)) {
    if (!$DRY_RUN) {
        $pdo->exec("ALTER TABLE perimeters ADD COLUMN device_id INTEGER REFERENCES devices(id)");
        // Existing perimeters are global (NULL device_id = applies to all devices)
        echo "  Added device_id column to perimeters (NULL = global/all devices).\n";
    } else {
        echo "  [DRY RUN] Would add device_id column to perimeters.\n";
    }
} else {
    echo "  Column device_id already exists in perimeters.\n";
}

echo "\nStep 7: Adding device_id to perimeter_state table...\n";
$columns = [];
$q = $pdo->query("PRAGMA table_info(perimeter_state)");
while ($r = $q->fetch()) {
    $columns[] = $r['name'];
}

if (!in_array('device_id', $columns)) {
    if (!$DRY_RUN) {
        $pdo->exec("ALTER TABLE perimeter_state ADD COLUMN device_id INTEGER REFERENCES devices(id)");
        echo "  Added device_id column to perimeter_state.\n";
    } else {
        echo "  [DRY RUN] Would add device_id column to perimeter_state.\n";
    }
} else {
    echo "  Column device_id already exists in perimeter_state.\n";
}

echo "\nStep 8: Adding device_id to perimeter_alerts table...\n";
$columns = [];
$q = $pdo->query("PRAGMA table_info(perimeter_alerts)");
while ($r = $q->fetch()) {
    $columns[] = $r['name'];
}

if (!in_array('device_id', $columns)) {
    if (!$DRY_RUN) {
        $pdo->exec("ALTER TABLE perimeter_alerts ADD COLUMN device_id INTEGER REFERENCES devices(id)");
        echo "  Added device_id column to perimeter_alerts.\n";
    } else {
        echo "  [DRY RUN] Would add device_id column to perimeter_alerts.\n";
    }
} else {
    echo "  Column device_id already exists in perimeter_alerts.\n";
}

echo "\nStep 9: Adding device_id to refetch_state table...\n";
// refetch_state uses date as PK, we need to handle multi-device
// For multi-device, we'll need composite key (date, device_id)
// Since SQLite doesn't support changing PK, we'll create a new table
$columns = [];
$q = $pdo->query("PRAGMA table_info(refetch_state)");
while ($r = $q->fetch()) {
    $columns[] = $r['name'];
}

if (!in_array('device_id', $columns)) {
    if (!$DRY_RUN) {
        // Rename old table, create new one, migrate data
        $pdo->exec("ALTER TABLE refetch_state RENAME TO refetch_state_old");
        $pdo->exec("CREATE TABLE IF NOT EXISTS refetch_state (
            date TEXT NOT NULL,
            device_id INTEGER NOT NULL DEFAULT $defaultDeviceId,
            last_check_time TEXT NOT NULL,
            last_known_timestamp TEXT,
            last_known_count INTEGER DEFAULT 0,
            needs_refetch INTEGER DEFAULT 0,
            last_refetch_time TEXT,
            notes TEXT,
            PRIMARY KEY (date, device_id),
            FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
        )");
        $pdo->exec("INSERT INTO refetch_state (date, device_id, last_check_time, last_known_timestamp, last_known_count, needs_refetch, last_refetch_time, notes)
            SELECT date, $defaultDeviceId, last_check_time, last_known_timestamp, last_known_count, needs_refetch, last_refetch_time, notes FROM refetch_state_old");
        $pdo->exec("DROP TABLE refetch_state_old");
        echo "  Migrated refetch_state to include device_id.\n";
    } else {
        echo "  [DRY RUN] Would migrate refetch_state table.\n";
    }
} else {
    echo "  Column device_id already exists in refetch_state.\n";
}

echo "\nStep 10: Creating indexes...\n";
if (!$DRY_RUN) {
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tracker_device_timestamp ON tracker_data(device_id, timestamp)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_tracker_device_id ON tracker_data(device_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_devices_eui ON devices(device_eui)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_devices_active ON devices(is_active)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_device_perimeters_device ON device_perimeters(device_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_perimeters_device ON perimeters(device_id)");
    echo "  Created all indexes.\n";
} else {
    echo "  [DRY RUN] Would create indexes.\n";
}

echo "\n======================================\n";
echo "Migration " . ($DRY_RUN ? 'dry run ' : '') . "complete!\n";
echo "======================================\n";

if (!$DRY_RUN) {
    // Verify
    $deviceCount = $pdo->query("SELECT COUNT(*) as c FROM devices")->fetch()['c'];
    $dataCount = $pdo->query("SELECT COUNT(*) as c FROM tracker_data WHERE device_id IS NOT NULL")->fetch()['c'];
    $totalData = $pdo->query("SELECT COUNT(*) as c FROM tracker_data")->fetch()['c'];
    echo "\nVerification:\n";
    echo "  Devices: $deviceCount\n";
    echo "  Tracker data with device_id: $dataCount / $totalData\n";
    echo "  Backup: $backupPath\n";
}
