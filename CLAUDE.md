# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

GPS/Location Tracker application with Wi-Fi geolocation, GNSS positioning, and iBeacon support. Built with vanilla JavaScript (no frameworks), PHP backend, and SQLite database. Uses SenseCAP IoT device for data collection.

**Tech Stack:**
- Frontend: Vanilla JavaScript + Leaflet.js maps
- Backend: PHP 7.4+ with SQLite
- No build process or transpilation required
- Direct file serving (no bundling)

## Architecture

### Data Flow
```
SenseCAP Device (GNSS/Wi-Fi/BT)
  → fetch_data.php (CLI cron job)
  → SQLite database
  → api.php (REST endpoints)
  → app.js (frontend)
  → Leaflet map visualization
```

### Key Components

**Frontend (`app.js` + `index.html`):**
- PIN security layer (4-digit code, SHA-256 hashed, 24h session)
- Fixed topbar with app branding, date controls, and hamburger menu
- Hamburger menu with: Refetch, Perimeter zones, iBeacon management, Settings
- Map visualization with Leaflet.js + Leaflet.draw for polygon zones
- Perimeter zone management with drawing tools and email alerts
- Position history table with sorting
- Settings panel for env variable configuration
- Mobile-responsive design (breakpoints: 768px, 480px)

**Backend (`api.php`):**
- Single-file REST API with action-based routing (`?action=X`)
- Actions: `get_history`, `get_settings`, `save_settings`, `verify_pin`, `get_ibeacons`, etc.
- Perimeter management: `get_perimeters`, `save_perimeter`, `delete_perimeter`
- N8N integration endpoints: `n8n_status`, `n8n_positions`, `n8n_perimeters`, `n8n_alerts`, `n8n_events`
- Uses `respond()` helper for consistent JSON responses
- All database queries use PDO with prepared statements

**Data Collection (`fetch_data.php`):**
- CLI-only script (checked via `PHP_SAPI !== 'cli'`)
- Fetches from SenseCAP API: GNSS (4197/4198), Wi-Fi (5001), BT iBeacon (5002), Battery (5003)
- Supports refetch mode: `--refetch-date=YYYY-MM-DD`
- Uses file locking (`fetch_data.lock`) to prevent concurrent runs
- Perimeter breach detection with email alerts
- Summary emails for batch refetch operations (single email with all events)
- Logs to `fetch.log`

**Smart Refetch (`smart_refetch_v2.php`):**
- CLI script for intelligent data refresh
- Detects delayed data uploads from device
- Automatically refetches days with potential missing data

### Configuration

**Environment Variables (`.env`):**
```env
# SenseCAP API
SENSECAP_ACCESS_ID=
SENSECAP_ACCESS_KEY=
SENSECAP_DEVICE_EUI=
SENSECAP_CHANNEL_INDEX=1
SENSECAP_LIMIT=50

# Measurement IDs
MEAS_WIFI_MAC=5001
MEAS_BT_IBEACON_MAC=5002
MEAS_GNSS_LON=4197
MEAS_GNSS_LAT=4198

# Hysteresis settings (configurable via UI)
HYSTERESIS_METERS=50
HYSTERESIS_MINUTES=30
UNIQUE_PRECISION=6
UNIQUE_BUCKET_MINUTES=30
MAC_CACHE_MAX_AGE_DAYS=3600

# Google Geolocation
GOOGLE_API_KEY=
GOOGLE_FORCE=0

# Security
ACCESS_PIN_HASH=  # SHA-256 hash of PIN (default 1234)

# Database
SQLITE_PATH=./tracker_database.sqlite

# Email Service (for perimeter alerts)
EMAIL_SERVICE_URL=http://email-service:3000/send
EMAIL_FROM=tracker@example.com

# N8N Integration (optional)
N8N_API_KEY=  # If set, required for n8n_* endpoints
```

**Security (`.htaccess`):**
- Blocks direct access to `.env`, `config.php`, CLI scripts, SQLite files, logs
- Sets security headers: X-Frame-Options, X-XSS-Protection, etc.
- **IMPORTANT:** Verify .htaccess protection by testing `https://domain/.env` (should return 403)

### Database Schema

**Main tables:**
- `tracker_data` - Position records (id, timestamp, latitude, longitude, source)
- `ibeacon_locations` - Static iBeacon markers (id, name, mac_address, latitude, longitude)
- `wifi_mac_cache` - Cached Wi-Fi geolocation lookups
- `device_status` - Device battery/online status

**Perimeter tables:**
- `perimeters` - Zone definitions (id, name, coordinates JSON, alert_on_enter, alert_on_exit)
- `perimeter_emails` - Email recipients per perimeter (id, perimeter_id, email, alert_on_enter, alert_on_exit)
- `perimeter_alerts` - Alert history (id, perimeter_id, alert_type, latitude, longitude, sent_at, email_sent)
- `perimeter_state` - Tracks current inside/outside state for breach detection

**Important:** Timestamps stored in UTC, converted to Europe/Bratislava timezone in frontend

## Common Tasks

### Running Data Collection
```bash
# Manual fetch (latest data)
php fetch_data.php

# Refetch specific date
php fetch_data.php --refetch-date=2025-01-15

# Smart refetch (detects missing data)
php smart_refetch_v2.php
```

### Changing PIN Code
```bash
# Generate SHA-256 hash
echo -n "1234" | sha256sum

# Add to .env
ACCESS_PIN_HASH=03ac674216f3e15c761ee1a5e255f067953623c8b388b4459e13f978d7c846f4
```

### Testing API Endpoints
```bash
# Get settings
curl "http://localhost/tracker/api.php?action=get_settings"

# Verify PIN
curl -X POST "http://localhost/tracker/api.php?action=verify_pin" \
  -H "Content-Type: application/json" \
  -d '{"pin":"1234"}'

# Get history for date
curl "http://localhost/tracker/api.php?action=get_history&date=2025-01-15"
```

### N8N Integration API

External API endpoints for n8n workflows and automation. All endpoints support optional API key authentication via `N8N_API_KEY` environment variable.

**Authentication:**
- Header: `X-API-Key: your-api-key`
- Query parameter: `?api_key=your-api-key`
- If `N8N_API_KEY` is not set, endpoints are publicly accessible

**Endpoints:**

```bash
# Device status and last position
curl "http://localhost/tracker/api.php?action=n8n_status" \
  -H "X-API-Key: your-key"

# Position history (with optional date filter)
curl "http://localhost/tracker/api.php?action=n8n_positions&date=2025-01-15&limit=100"

# All perimeters with current inside/outside status
curl "http://localhost/tracker/api.php?action=n8n_perimeters"

# Recent perimeter alerts
curl "http://localhost/tracker/api.php?action=n8n_alerts&limit=50&days=7"

# Perimeter entry/exit events (for webhooks)
curl "http://localhost/tracker/api.php?action=n8n_events&type=entered&limit=20"
curl "http://localhost/tracker/api.php?action=n8n_events&type=exited&since=2025-01-15T10:00:00"
```

**n8n_events parameters:**
- `type`: `entered`, `exited`, or `all` (default: `all`)
- `limit`: Max events to return (default: 20, max: 100)
- `since`: ISO datetime - only events after this time (for polling)

**Response format:**
```json
{
  "ok": true,
  "data": {
    "events": [
      {
        "event_id": 123,
        "event_type": "entered",
        "perimeter_id": 1,
        "perimeter_name": "Home",
        "location": {
          "latitude": 48.1234,
          "longitude": 17.1234,
          "maps_url": "https://www.google.com/maps?q=48.1234,17.1234"
        },
        "timestamp": "2025-01-15 10:30:00",
        "email_sent": true
      }
    ],
    "count": 1,
    "latest_timestamp": "2025-01-15 10:30:00"
  }
}
```

### Debugging Frontend
```javascript
// In browser console
openSettingsOverlay()  // Open settings directly
sessionStorage.clear() // Clear auth token
```

## Important Patterns

### API Response Format
All API endpoints return:
```json
{"ok": true/false, "data": {...}, "error": "..."}
```

### Adding New API Endpoint
1. Add action handler in `api.php`:
   ```php
   if ($action === 'your_action' && $_SERVER['REQUEST_METHOD'] === 'POST') {
       try {
           $j = json_body();
           // ... logic
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
1. Add to `.env` with default value
2. Add to `config.php` with `getenv()` and default
3. Add UI in Settings overlay (`index.html`)
4. Add to `get_settings` and `save_settings` actions in `api.php`
5. Add JavaScript handlers in `app.js`

### Mobile Responsiveness
- Use existing breakpoints: `@media (max-width:768px)` and `@media (max-width:480px)`
- Test on mobile: hamburger menu, PIN pad, settings overlay, map height
- Touch-friendly button sizes: minimum 44x44px tap targets

## Security Considerations

### PIN Authentication
- Frontend: 4-digit PIN → SHA-256 hash → sessionStorage (24h)
- Backend: Compares hash, adds 500ms delay on failure (brute-force protection)
- Session expires after 24h or browser close
- **Never commit** `.env` file or expose API keys

### File Protection
- `.htaccess` blocks sensitive files (.env, config.php, *.sqlite, *.log)
- CLI scripts check `PHP_SAPI === 'cli'` to prevent web access
- API validates all inputs with type casting and boundary checks

### Data Validation
- Frontend: HTML5 validation + JavaScript boundary checks
- Backend: Type casting, range validation, prepared statements
- Settings: Min/max values enforced both client and server-side

## Troubleshooting

### "Settings menu doesn't open"
- Check browser console for errors
- Verify `index.html` has `<button id="menuSettings">`
- Hard refresh: Ctrl+Shift+R (clears cache)
- Test manually: `openSettingsOverlay()` in console

### "PIN screen stuck/won't accept correct PIN"
- Clear session: `sessionStorage.clear()`
- Verify `.env` has correct `ACCESS_PIN_HASH`
- Default PIN is 1234 (hash: `03ac674216f3e15c761ee1a5e255f067953623c8b388b4459e13f978d7c846f4`)

### ".env file accessible via web"
- Verify `.htaccess` exists in root directory
- Check Apache config: `AllowOverride All` required
- Test: `curl https://domain/.env` should return 403

### "No data appearing on map"
- Check `fetch_data.php` ran successfully: `tail -f fetch.log`
- Verify SenseCAP credentials in `.env`
- Check database: `sqlite3 tracker_database.sqlite "SELECT COUNT(*) FROM tracker_data;"`
- Look for API errors in browser console

## File Locations

**Never modify via web:**
- `.env` - Environment config (blocked by .htaccess)
- `config.php` - Loads .env (blocked by .htaccess)
- `*.sqlite` - Database files (blocked by .htaccess)
- `fetch_data.php`, `smart_refetch_v2.php` - CLI only

**Web accessible:**
- `index.html` - Main entry point
- `app.js` - Frontend logic
- `style.css` - All styling
- `api.php` - REST API endpoint

**Documentation:**
- `SECURITY_SETUP.md` - Security configuration guide
- `CLAUDE.md` - This file

## Localization

- UI is in Slovak (`sk-SK` locale)
- Dates/times displayed in Europe/Bratislava timezone
- Database stores UTC timestamps, converted on display
- Month names use Slovak: január, február, etc.
