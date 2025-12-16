# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Related Documentation

- **[README.md](README.md)** - Project overview, setup instructions, features
- **[DEPLOYMENT.md](DEPLOYMENT.md)** - Docker deployment, volumes, networking, backups
- **[ARCHITECTURE.md](ARCHITECTURE.md)** - System design, database schema, data flows

## Project Overview

GPS/Location Tracker application with Wi-Fi geolocation, GNSS positioning, and iBeacon support. Built with vanilla JavaScript (no frameworks), PHP backend, and SQLite database. Uses SenseCAP IoT device for data collection.

**Tech Stack:**
- Frontend: Vanilla JavaScript + Leaflet.js maps + Leaflet.draw (polygon drawing)
- Backend: PHP 8.2+ with SQLite
- Docker with Apache, Supervisor, and cron
- No build process or transpilation required
- Direct file serving (no bundling)

## Quick Start

```bash
# 1. Configure
cp .env.example .env
nano .env  # Add your SenseCAP credentials

# 2. Deploy
chmod +x deploy.sh
./deploy.sh

# Application available at http://localhost:8080
# Default PIN: 1234
```

## Project Structure

```
tracker/
├── api.php                 # REST API (30+ endpoints)
├── fetch_data.php          # CLI cron job for SenseCAP data
├── smart_refetch_v2.php    # Intelligent data gap detection
├── config.php              # Environment loader
├── app.js                  # Frontend JavaScript
├── index.html              # Main HTML page
├── style.css               # Styling (dark/light theme)
├── .env.example            # Environment template
├── .htaccess               # Security rules
├── Dockerfile              # Container image
├── docker-compose.yml      # Service definition
├── docker-compose.prod.yml # Production overrides
├── deploy.sh               # Deployment script
└── docker/                 # Container configuration
    ├── entrypoint.sh       # Startup script
    ├── supervisord.conf    # Process manager
    ├── crontab             # Scheduled jobs
    ├── apache-vhost.conf   # Apache config
    └── php.ini             # PHP settings
```

## Deploy Commands

```bash
./deploy.sh              # Production deployment
./deploy.sh dev          # Development mode
./deploy.sh stop         # Stop containers
./deploy.sh restart      # Restart
./deploy.sh logs         # View logs
./deploy.sh status       # Check health
./deploy.sh backup       # Backup database
./deploy.sh restore FILE # Restore from backup
./deploy.sh clean        # Remove everything
```

## Key Components

### Frontend (`app.js` + `index.html`)

- **Authentication:** PIN (4-digit, SHA-256, 24h session) or SSO
- **Map:** Leaflet.js with position markers and trail
- **Perimeters:** Polygon drawing with Leaflet.draw
- **Settings:** Configurable via overlay panel
- **Theme:** Auto/light/dark mode support
- **Mobile:** Responsive with breakpoints at 768px and 480px

### Backend (`api.php`)

Single-file REST API with action-based routing (`?action=X`).

**Key Actions:**

| Action | Method | Purpose |
|--------|--------|---------|
| `health` | GET | Health check endpoint |
| `get_history` | GET | Position history for date |
| `get_last_position` | GET | Most recent position |
| `verify_pin` | POST | PIN authentication |
| `auth_me` | GET | SSO session check |
| `get_settings` | GET | Application settings |
| `save_settings` | POST | Update settings |
| `refetch_day` | POST | Trigger data refetch |
| `get_ibeacons` | GET | List iBeacons |
| `upsert_ibeacon` | POST | Create/update iBeacon |
| `get_perimeters` | GET | List perimeter zones |
| `save_perimeter` | POST | Create/update perimeter |
| `test_email` | POST | Test email service |
| `n8n_status` | GET | Device status for n8n |
| `n8n_events` | GET | Perimeter events |

### Data Collection (`fetch_data.php`)

CLI-only cron job that:
- Fetches from SenseCAP API: GNSS (4197/4198), Wi-Fi (5001), BT (5002)
- Applies hysteresis filtering to reduce duplicates
- Detects perimeter breaches and sends email alerts
- Uses file locking to prevent concurrent runs

```bash
# Manual fetch
php fetch_data.php

# Refetch specific date
php fetch_data.php --refetch-date=2025-01-15
```

### Smart Refetch (`smart_refetch_v2.php`)

Intelligent data gap detection:
- Detects delayed device uploads
- Identifies days with missing/few records
- Prioritizes and refetches problematic days
- Tracks state in `refetch_state` table

```bash
# Normal run
php smart_refetch_v2.php

# Dry run (show what would be done)
php smart_refetch_v2.php --dry-run

# Check more days
php smart_refetch_v2.php --days=14
```

## Database Schema

### Main Tables

| Table | Purpose |
|-------|---------|
| `tracker_data` | Position records (timestamp, lat, lng, source) |
| `device_status` | Battery and online status cache |
| `ibeacon_locations` | Static iBeacon location markers |
| `mac_locations` | Wi-Fi geolocation cache (positive/negative) |

### Perimeter Tables

| Table | Purpose |
|-------|---------|
| `perimeters` | Zone definitions (polygon, alerts config) |
| `perimeter_emails` | Email recipients per zone |
| `perimeter_alerts` | Alert history |
| `perimeter_state` | Current inside/outside state |

### Utility Tables

| Table | Purpose |
|-------|---------|
| `refetch_state` | Smart refetch tracking |

**Note:** Timestamps stored in UTC, converted to Europe/Bratislava in frontend.

## Environment Variables

### Required

```env
SENSECAP_ACCESS_ID=your_id
SENSECAP_ACCESS_KEY=your_key
SENSECAP_DEVICE_EUI=your_eui
```

### Recommended

```env
GOOGLE_API_KEY=            # Wi-Fi geolocation
ACCESS_PIN_HASH=...        # SHA-256 of PIN (default: 1234)
```

### Optional

```env
TRACKER_PORT=8080          # Host port
HYSTERESIS_METERS=50       # Min distance between positions
HYSTERESIS_MINUTES=30      # Min time between positions
EMAIL_SERVICE_URL=         # For perimeter alerts
EMAIL_API_KEY=             # Email service auth
EMAIL_FROM=                # From address
N8N_API_KEY=               # Protect n8n endpoints
LOG_LEVEL=info             # error, info, debug
SSO_ENABLED=false          # Use SSO instead of PIN
AUTH_API_URL=              # SSO auth server
LOGIN_URL=                 # SSO login page
```

## Common Development Tasks

### Adding New API Endpoint

1. Add handler in `api.php`:
```php
if ($action === 'your_action' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $j = json_body();
        // ... your logic
        respond(['ok' => true, 'data' => $result]);
    } catch (Throwable $e) {
        respond(['ok' => false, 'error' => $e->getMessage()], 500);
    }
}
```

2. Call from frontend:
```javascript
const result = await apiPost('your_action', {param: value});
```

### Adding New Settings

1. Add to `.env.example` with default
2. Add to `config.php` with `getenv()` and default
3. Add UI in Settings overlay (`index.html`)
4. Add to `get_settings` and `save_settings` in `api.php`
5. Add JavaScript handlers in `app.js`

### Changing PIN

```bash
# Generate hash
echo -n "new_pin" | sha256sum

# Update .env
ACCESS_PIN_HASH=<generated_hash>

# Restart
./deploy.sh restart
```

### Testing Endpoints

```bash
# Health check
curl "http://localhost:8080/api.php?action=health"

# Get positions
curl "http://localhost:8080/api.php?action=get_history&date=2025-01-15"

# Verify PIN
curl -X POST "http://localhost:8080/api.php?action=verify_pin" \
  -H "Content-Type: application/json" \
  -d '{"pin":"1234"}'
```

### Debugging

```bash
# Container logs
./deploy.sh logs

# Fetch logs
docker exec -it gps-tracker tail -f /var/log/tracker/fetch.log

# Database query
docker exec -it gps-tracker sqlite3 /var/www/html/data/tracker_database.sqlite \
  "SELECT COUNT(*) FROM tracker_data"
```

```javascript
// Browser console
sessionStorage.clear()     // Clear auth
openSettingsOverlay()      // Open settings
```

## N8N Integration

External API endpoints for automation workflows.

**Authentication:** `X-API-Key` header or `?api_key=` query param (if `N8N_API_KEY` set)

```bash
# Device status
curl "http://localhost:8080/api.php?action=n8n_status" -H "X-API-Key: key"

# Position history
curl "http://localhost:8080/api.php?action=n8n_positions&date=2025-01-15"

# Perimeters with current status
curl "http://localhost:8080/api.php?action=n8n_perimeters"

# Perimeter alerts
curl "http://localhost:8080/api.php?action=n8n_alerts&limit=50"

# Events (for polling)
curl "http://localhost:8080/api.php?action=n8n_events&type=entered&since=2025-01-15T10:00:00"
```

## Security

### File Protection

`.htaccess` blocks:
- `.env` - Environment config
- `config.php` - Config loader
- `*.sqlite` - Database files
- `*.log` - Log files
- `fetch_data.php`, `smart_refetch_v2.php` - CLI only

**Verify:** `curl http://domain/.env` should return 403

### Authentication

- **PIN Mode:** SHA-256 hashed, 500ms delay on failure, 24h session
- **SSO Mode:** Session cookie validated against auth server

### Data Validation

- Backend: Type casting, range validation, prepared statements
- Frontend: HTML5 validation + JavaScript boundary checks

## Cron Jobs

| Schedule | Script | Purpose |
|----------|--------|---------|
| `*/5 * * * *` | `fetch_data.php` | Fetch latest positions |
| `0 2 * * *` | `smart_refetch_v2.php` | Daily gap detection |
| `0 3 * * 0` | `smart_refetch_v2.php --days=14` | Weekly comprehensive |

## Troubleshooting

### No data on map
1. Check SenseCAP credentials in `.env`
2. Verify device is uploading: SenseCAP console
3. Check fetch logs: `./deploy.sh logs`
4. Run manual fetch: `docker exec -it gps-tracker php fetch_data.php`

### PIN not working
1. Verify `ACCESS_PIN_HASH` matches your PIN
2. Clear session: `sessionStorage.clear()` in browser
3. Default PIN is `1234`

### Container issues
```bash
# Check status
./deploy.sh status

# Recreate container
./deploy.sh clean && ./deploy.sh
```

## Code Style

- **PHP:** `declare(strict_types=1)`, PDO with prepared statements
- **JavaScript:** Vanilla JS, async/await, no frameworks
- **CSS:** CSS custom properties for theming, mobile-first
- **Naming:** snake_case for PHP/SQL, camelCase for JavaScript

## Localization

- UI language: Slovak (`sk-SK`)
- Timezone: Europe/Bratislava (display), UTC (storage)
- Date format: DD.MM.YYYY
