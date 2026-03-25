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

# Ensure log directory has correct permissions
chown -R www-data:www-data /var/log/tracker
chmod 755 /var/log/tracker

# Database connection parameters (from docker-compose environment)
export DB_HOST="${DB_HOST:-db}"
export DB_PORT="${DB_PORT:-5432}"
export DB_NAME="${DB_NAME:-tracker}"
export DB_USER="${DB_USER:-tracker}"
export DB_PASSWORD="${DB_PASSWORD:-tracker_secret}"

# Wait for PostgreSQL to be ready
echo "Waiting for PostgreSQL at ${DB_HOST}:${DB_PORT}..."
for i in $(seq 1 30); do
    if PGPASSWORD="${DB_PASSWORD}" pg_isready -h "${DB_HOST}" -p "${DB_PORT}" -U "${DB_USER}" > /dev/null 2>&1; then
        echo "PostgreSQL is ready!"
        break
    fi
    if [ "$i" = "30" ]; then
        echo "ERROR: PostgreSQL not available after 30 seconds"
        exit 1
    fi
    sleep 1
done

# Initialize database schema
echo "Initializing database schema..."
cd /var/www/html && /usr/local/bin/php -r "
    require_once 'config.php';
    \$pdo = get_pdo();

    // Create tables

    // Multi-device support: devices table
    \$pdo->exec('CREATE TABLE IF NOT EXISTS devices (
        id SERIAL PRIMARY KEY,
        name TEXT NOT NULL,
        device_eui TEXT NOT NULL UNIQUE,
        color TEXT NOT NULL DEFAULT \'#3388ff\',
        icon TEXT DEFAULT \'default\',
        is_active INTEGER NOT NULL DEFAULT 1,
        notifications_enabled INTEGER DEFAULT 0,
        notification_email TEXT,
        notification_webhook TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )');

    \$pdo->exec('CREATE TABLE IF NOT EXISTS tracker_data (
        id SERIAL PRIMARY KEY,
        timestamp TIMESTAMP NOT NULL,
        latitude DOUBLE PRECISION NOT NULL,
        longitude DOUBLE PRECISION NOT NULL,
        source TEXT,
        raw_wifi_macs TEXT,
        primary_mac TEXT,
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
        id SERIAL PRIMARY KEY,
        name TEXT NOT NULL,
        mac_address TEXT UNIQUE NOT NULL,
        latitude DOUBLE PRECISION NOT NULL,
        longitude DOUBLE PRECISION NOT NULL
    )');

    \$pdo->exec('CREATE TABLE IF NOT EXISTS mac_locations (
        mac_address TEXT UNIQUE NOT NULL,
        latitude DOUBLE PRECISION,
        longitude DOUBLE PRECISION,
        last_queried TEXT
    )');

    \$pdo->exec('CREATE TABLE IF NOT EXISTS perimeters (
        id SERIAL PRIMARY KEY,
        name TEXT NOT NULL,
        polygon TEXT NOT NULL,
        alert_on_enter INTEGER DEFAULT 1,
        alert_on_exit INTEGER DEFAULT 1,
        is_active INTEGER DEFAULT 1,
        color TEXT DEFAULT \'#3388ff\',
        device_id INTEGER REFERENCES devices(id),
        created_at TEXT,
        updated_at TEXT
    )');

    \$pdo->exec('CREATE TABLE IF NOT EXISTS perimeter_emails (
        id SERIAL PRIMARY KEY,
        perimeter_id INTEGER NOT NULL,
        email TEXT NOT NULL,
        alert_on_enter INTEGER DEFAULT 1,
        alert_on_exit INTEGER DEFAULT 1,
        FOREIGN KEY (perimeter_id) REFERENCES perimeters(id) ON DELETE CASCADE
    )');

    \$pdo->exec('CREATE TABLE IF NOT EXISTS perimeter_alerts (
        id SERIAL PRIMARY KEY,
        perimeter_id INTEGER NOT NULL,
        alert_type TEXT NOT NULL,
        latitude DOUBLE PRECISION,
        longitude DOUBLE PRECISION,
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
        id SERIAL PRIMARY KEY,
        device_id INTEGER NOT NULL,
        name TEXT NOT NULL,
        latitude DOUBLE PRECISION NOT NULL,
        longitude DOUBLE PRECISION NOT NULL,
        radius_meters DOUBLE PRECISION NOT NULL DEFAULT 500,
        alert_on_enter INTEGER DEFAULT 0,
        alert_on_exit INTEGER DEFAULT 0,
        is_active INTEGER DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
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

    // Raw WiFi scans
    \$pdo->exec('CREATE TABLE IF NOT EXISTS wifi_scans (
        id SERIAL PRIMARY KEY,
        timestamp TIMESTAMP NOT NULL,
        device_id INTEGER NOT NULL DEFAULT 1,
        macs_json TEXT NOT NULL,
        mac_count INTEGER NOT NULL DEFAULT 0,
        resolved INTEGER NOT NULL DEFAULT 0,
        latitude DOUBLE PRECISION,
        longitude DOUBLE PRECISION,
        source TEXT,
        tracker_data_id INTEGER,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (device_id) REFERENCES devices(id)
    )');

    // Battery alert log
    \$pdo->exec('CREATE TABLE IF NOT EXISTS battery_alert_log (
        id SERIAL PRIMARY KEY,
        sent_at TEXT NOT NULL,
        email TEXT NOT NULL
    )');

    // Create default device from .env
    \$eui = getenv('SENSECAP_DEVICE_EUI') ?: 'UNKNOWN';
    \$deviceName = getenv('DEVICE_NAME') ?: 'Predvolené zariadenie';
    \$stmt = \$pdo->prepare('INSERT INTO devices (name, device_eui, color, icon, is_active) VALUES (:name, :eui, \'#3388ff\', \'default\', 1) ON CONFLICT (device_eui) DO NOTHING');
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
    \$pdo->exec('CREATE INDEX IF NOT EXISTS idx_wifi_scans_device_ts ON wifi_scans(device_id, timestamp)');
    \$pdo->exec('CREATE INDEX IF NOT EXISTS idx_wifi_scans_resolved ON wifi_scans(resolved)');

    echo 'Database initialized successfully.';
"
echo ""

# Touch log files
touch /var/log/tracker/fetch.log /var/log/tracker/smart_refetch.log /var/log/tracker/php-error.log
chown www-data:www-data /var/log/tracker/*.log

echo "========================================"
echo "Container initialized successfully!"
echo "Database: PostgreSQL ${DB_HOST}:${DB_PORT}/${DB_NAME}"
echo "========================================"

# Execute CMD
exec "$@"
