#!/bin/bash
set -e

echo "========================================"
echo "GPS Tracker - Starting Container"
echo "========================================"

# Create necessary directories
mkdir -p /var/log/tracker /var/log/supervisor /var/www/html/data

# Check if .env file exists (should be bind-mounted from host)
if [ ! -f "/var/www/html/.env" ]; then
    echo "========================================"
    echo "ERROR: .env file not found!"
    echo ""
    echo "The .env file should be bind-mounted from the host."
    echo "Please create .env from .env.example:"
    echo "  cp .env.example .env"
    echo "  nano .env  # Edit with your settings"
    echo ""
    echo "Then restart the container."
    echo "========================================"
    exit 1
fi

# Set proper permissions on .env (readable by www-data)
chown www-data:www-data /var/www/html/.env
chmod 644 /var/www/html/.env

# Set default values for environment variables (used for validation/defaults)
export SQLITE_PATH="${SQLITE_PATH:-/var/www/html/data/tracker_database.sqlite}"

# Ensure data directory has correct permissions
chown -R www-data:www-data /var/www/html/data /var/log/tracker
chmod 755 /var/www/html/data /var/log/tracker

# Initialize database if it doesn't exist
if [ ! -f "${SQLITE_PATH}" ]; then
    echo "Initializing database..."
    touch "${SQLITE_PATH}"
    chown www-data:www-data "${SQLITE_PATH}"
    chmod 664 "${SQLITE_PATH}"

    # Run PHP to initialize database schema
    cd /var/www/html && /usr/local/bin/php -r "
        require_once 'config.php';
        \$pdo = new PDO('sqlite:' . SQLITE_PATH);
        \$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Create tables

        // Multi-device support: devices table
        \$pdo->exec('CREATE TABLE IF NOT EXISTS devices (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            device_eui TEXT NOT NULL UNIQUE,
            color TEXT NOT NULL DEFAULT \"#3388ff\",
            icon TEXT DEFAULT \"default\",
            is_active INTEGER NOT NULL DEFAULT 1,
            notifications_enabled INTEGER DEFAULT 0,
            notification_email TEXT,
            notification_webhook TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )');

        \$pdo->exec('CREATE TABLE IF NOT EXISTS tracker_data (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp TEXT NOT NULL,
            latitude REAL NOT NULL,
            longitude REAL NOT NULL,
            source TEXT,
            device_id INTEGER DEFAULT 1 REFERENCES devices(id)
        )');

        \$pdo->exec('CREATE TABLE IF NOT EXISTS device_status (
            device_eui TEXT PRIMARY KEY,
            battery_state INTEGER,
            latest_message_time TEXT,
            online_status INTEGER,
            last_update TEXT
        )');

        \$pdo->exec('CREATE TABLE IF NOT EXISTS ibeacon_locations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            mac_address TEXT UNIQUE NOT NULL,
            latitude REAL NOT NULL,
            longitude REAL NOT NULL
        )');

        \$pdo->exec('CREATE TABLE IF NOT EXISTS mac_locations (
            mac_address TEXT UNIQUE NOT NULL,
            latitude REAL,
            longitude REAL,
            last_queried TEXT
        )');

        \$pdo->exec('CREATE TABLE IF NOT EXISTS perimeters (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            polygon TEXT NOT NULL,
            alert_on_enter INTEGER DEFAULT 1,
            alert_on_exit INTEGER DEFAULT 1,
            is_active INTEGER DEFAULT 1,
            color TEXT DEFAULT \"#3388ff\",
            device_id INTEGER REFERENCES devices(id),
            created_at TEXT,
            updated_at TEXT
        )');

        \$pdo->exec('CREATE TABLE IF NOT EXISTS perimeter_emails (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            perimeter_id INTEGER NOT NULL,
            email TEXT NOT NULL,
            alert_on_enter INTEGER DEFAULT 1,
            alert_on_exit INTEGER DEFAULT 1,
            FOREIGN KEY (perimeter_id) REFERENCES perimeters(id) ON DELETE CASCADE
        )');

        \$pdo->exec('CREATE TABLE IF NOT EXISTS perimeter_alerts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            perimeter_id INTEGER NOT NULL,
            alert_type TEXT NOT NULL,
            latitude REAL,
            longitude REAL,
            sent_at TEXT,
            email_sent INTEGER DEFAULT 0,
            device_id INTEGER REFERENCES devices(id),
            FOREIGN KEY (perimeter_id) REFERENCES perimeters(id) ON DELETE CASCADE
        )');

        \$pdo->exec('CREATE TABLE IF NOT EXISTS perimeter_state (
            perimeter_id INTEGER PRIMARY KEY,
            is_inside INTEGER DEFAULT 0,
            last_checked TEXT,
            device_id INTEGER REFERENCES devices(id),
            FOREIGN KEY (perimeter_id) REFERENCES perimeters(id) ON DELETE CASCADE
        )');

        // Multi-device: device-specific circular perimeters
        \$pdo->exec('CREATE TABLE IF NOT EXISTS device_perimeters (
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
        )');

        // Refetch state with device support
        \$pdo->exec('CREATE TABLE IF NOT EXISTS refetch_state (
            date TEXT NOT NULL,
            device_id INTEGER NOT NULL DEFAULT 1,
            last_check_time TEXT NOT NULL,
            last_known_timestamp TEXT,
            last_known_count INTEGER DEFAULT 0,
            needs_refetch INTEGER DEFAULT 0,
            last_refetch_time TEXT,
            notes TEXT,
            PRIMARY KEY (date, device_id),
            FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
        )');

        // Create default device from .env
        \$eui = getenv(\"SENSECAP_DEVICE_EUI\") ?: \"UNKNOWN\";
        \$deviceName = getenv(\"DEVICE_NAME\") ?: \"Predvolené zariadenie\";
        \$stmt = \$pdo->prepare(\"INSERT OR IGNORE INTO devices (name, device_eui, color, icon, is_active) VALUES (:name, :eui, '#3388ff', 'default', 1)\");
        \$stmt->execute([':name' => \$deviceName, ':eui' => \$eui]);

        // Create indexes
        \$pdo->exec('CREATE INDEX IF NOT EXISTS idx_tracker_timestamp ON tracker_data(timestamp)');
        \$pdo->exec('CREATE INDEX IF NOT EXISTS idx_tracker_device_timestamp ON tracker_data(device_id, timestamp)');
        \$pdo->exec('CREATE INDEX IF NOT EXISTS idx_tracker_device_id ON tracker_data(device_id)');
        \$pdo->exec('CREATE INDEX IF NOT EXISTS idx_devices_eui ON devices(device_eui)');
        \$pdo->exec('CREATE INDEX IF NOT EXISTS idx_devices_active ON devices(is_active)');
        \$pdo->exec('CREATE INDEX IF NOT EXISTS idx_device_perimeters_device ON device_perimeters(device_id)');
        \$pdo->exec('CREATE INDEX IF NOT EXISTS idx_perimeters_device ON perimeters(device_id)');
        \$pdo->exec('CREATE INDEX IF NOT EXISTS idx_perimeter_alerts_sent ON perimeter_alerts(sent_at)');

        echo 'Database initialized successfully.';
    "
    echo ""
fi

# Run multi-device migration if needed (for existing databases)
if [ -f "${SQLITE_PATH}" ]; then
    echo "Checking for multi-device migration..."
    cd /var/www/html && su -s /bin/bash www-data -c "/usr/local/bin/php migrate_multi_device.php" || true
fi

# Touch log files
touch /var/log/tracker/fetch.log /var/log/tracker/smart_refetch.log /var/log/tracker/php-error.log
chown www-data:www-data /var/log/tracker/*.log

echo "========================================"
echo "Container initialized successfully!"
echo "Database: ${SQLITE_PATH}"
echo "========================================"

# Execute CMD
exec "$@"
