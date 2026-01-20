#!/bin/bash
set -e

echo "========================================"
echo "GPS Tracker - Starting Container"
echo "========================================"

# Create necessary directories
mkdir -p /var/log/tracker /var/log/supervisor /var/www/html/data

# Set default values for environment variables
export SQLITE_PATH="${SQLITE_PATH:-/var/www/html/data/tracker_database.sqlite}"
export ACCESS_PIN_HASH="${ACCESS_PIN_HASH:-03ac674216f3e15c761ee1a5e255f067953623c8b388b4459e13f978d7c846f4}"
export SENSECAP_CHANNEL_INDEX="${SENSECAP_CHANNEL_INDEX:-1}"
export SENSECAP_LIMIT="${SENSECAP_LIMIT:-50}"
export MEAS_WIFI_MAC="${MEAS_WIFI_MAC:-5001}"
export MEAS_BT_IBEACON_MAC="${MEAS_BT_IBEACON_MAC:-5002}"
export MEAS_GNSS_LON="${MEAS_GNSS_LON:-4197}"
export MEAS_GNSS_LAT="${MEAS_GNSS_LAT:-4198}"
export HYSTERESIS_METERS="${HYSTERESIS_METERS:-50}"
export HYSTERESIS_MINUTES="${HYSTERESIS_MINUTES:-30}"
export UNIQUE_PRECISION="${UNIQUE_PRECISION:-6}"
export UNIQUE_BUCKET_MINUTES="${UNIQUE_BUCKET_MINUTES:-30}"
export MAC_CACHE_MAX_AGE_DAYS="${MAC_CACHE_MAX_AGE_DAYS:-3600}"
export GOOGLE_FORCE="${GOOGLE_FORCE:-0}"
export EMAIL_FROM="${EMAIL_FROM:-tracker@example.com}"
export LOG_LEVEL="${LOG_LEVEL:-info}"
export LOG_FILE="${LOG_FILE:-/var/log/tracker/fetch.log}"
export AUTH_API_URL="${AUTH_API_URL:-http://family-office:3001}"
export LOGIN_URL="${LOGIN_URL:-https://bagron.eu/login}"
export SSO_ENABLED="${SSO_ENABLED:-false}"

# Generate .env file from environment variables
cat > /var/www/html/.env << EOF
# SenseCAP API Configuration
SENSECAP_ACCESS_ID=${SENSECAP_ACCESS_ID:-}
SENSECAP_ACCESS_KEY=${SENSECAP_ACCESS_KEY:-}
SENSECAP_DEVICE_EUI=${SENSECAP_DEVICE_EUI:-}
SENSECAP_CHANNEL_INDEX=${SENSECAP_CHANNEL_INDEX}
SENSECAP_LIMIT=${SENSECAP_LIMIT}

# Measurement IDs
MEAS_WIFI_MAC=${MEAS_WIFI_MAC}
MEAS_BT_IBEACON_MAC=${MEAS_BT_IBEACON_MAC}
MEAS_GNSS_LON=${MEAS_GNSS_LON}
MEAS_GNSS_LAT=${MEAS_GNSS_LAT}

# Hysteresis Settings
HYSTERESIS_METERS=${HYSTERESIS_METERS}
HYSTERESIS_MINUTES=${HYSTERESIS_MINUTES}
UNIQUE_PRECISION=${UNIQUE_PRECISION}
UNIQUE_BUCKET_MINUTES=${UNIQUE_BUCKET_MINUTES}
MAC_CACHE_MAX_AGE_DAYS=${MAC_CACHE_MAX_AGE_DAYS}

# Google Geolocation API
GOOGLE_API_KEY=${GOOGLE_API_KEY:-}
GOOGLE_FORCE=${GOOGLE_FORCE}

# Security
ACCESS_PIN_HASH=${ACCESS_PIN_HASH}

# Database
SQLITE_PATH=${SQLITE_PATH}

# Email Service
EMAIL_SERVICE_URL=${EMAIL_SERVICE_URL:-}
EMAIL_API_KEY=${EMAIL_API_KEY:-}
EMAIL_FROM=${EMAIL_FROM}
EMAIL_FROM_NAME=${EMAIL_FROM_NAME:-Family Tracker}
TRACKER_APP_URL=${TRACKER_APP_URL:-}

# Battery Alert Settings
BATTERY_ALERT_ENABLED=${BATTERY_ALERT_ENABLED:-false}
BATTERY_ALERT_EMAIL=${BATTERY_ALERT_EMAIL:-}

# N8N Integration (optional)
N8N_API_KEY=${N8N_API_KEY:-}

# Logging
LOG_LEVEL=${LOG_LEVEL}
LOG_FILE=${LOG_FILE}

# SSO Authentication
AUTH_API_URL=${AUTH_API_URL}
LOGIN_URL=${LOGIN_URL}
SSO_ENABLED=${SSO_ENABLED}
EOF

# Set proper permissions
chown www-data:www-data /var/www/html/.env
chmod 600 /var/www/html/.env

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
        \$pdo->exec('CREATE TABLE IF NOT EXISTS tracker_data (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp TEXT NOT NULL,
            latitude REAL NOT NULL,
            longitude REAL NOT NULL,
            source TEXT
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
            FOREIGN KEY (perimeter_id) REFERENCES perimeters(id) ON DELETE CASCADE
        )');

        \$pdo->exec('CREATE TABLE IF NOT EXISTS perimeter_state (
            perimeter_id INTEGER PRIMARY KEY,
            is_inside INTEGER DEFAULT 0,
            last_checked TEXT,
            FOREIGN KEY (perimeter_id) REFERENCES perimeters(id) ON DELETE CASCADE
        )');

        // Create indexes
        \$pdo->exec('CREATE INDEX IF NOT EXISTS idx_tracker_timestamp ON tracker_data(timestamp)');
        \$pdo->exec('CREATE INDEX IF NOT EXISTS idx_perimeter_alerts_sent ON perimeter_alerts(sent_at)');

        echo 'Database initialized successfully.';
    "
    echo ""
fi

# Validate required environment variables
if [ -z "${SENSECAP_ACCESS_ID}" ] || [ -z "${SENSECAP_ACCESS_KEY}" ] || [ -z "${SENSECAP_DEVICE_EUI}" ]; then
    echo "WARNING: SenseCAP credentials not configured. Data fetching will not work."
    echo "Please set: SENSECAP_ACCESS_ID, SENSECAP_ACCESS_KEY, SENSECAP_DEVICE_EUI"
fi

if [ -z "${GOOGLE_API_KEY}" ]; then
    echo "WARNING: Google API key not configured. Wi-Fi geolocation will not work."
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
