# Family Tracker

A GPS/Location Tracker application with Wi-Fi geolocation, GNSS positioning, iBeacon support, and perimeter zone alerts. Built for real-time location monitoring of SenseCAP IoT devices.

## What It Does

This application tracks the location of a SenseCAP tracker device and displays position history on an interactive map. It supports multiple positioning methods:

- **GNSS (GPS)** - Primary location source when available
- **Wi-Fi Geolocation** - Uses Google Geolocation API when GPS is unavailable
- **iBeacon** - Static location markers for indoor positioning
- **Perimeter Zones** - Define geographic boundaries with entry/exit email alerts

## Tech Stack

| Component | Technology |
|-----------|------------|
| Frontend | Vanilla JavaScript, Leaflet.js (maps), Leaflet.draw (polygon drawing) |
| Backend | PHP 8.2+ |
| Database | SQLite |
| Infrastructure | Docker, Apache, Supervisor, Cron |
| External APIs | SenseCAP API, Google Geolocation API |

**No build process required** - Direct file serving without transpilation or bundling.

## Project Structure

```
tracker/
├── api.php                 # REST API (30+ endpoints, action-based routing)
├── fetch_data.php          # CLI cron job for SenseCAP data collection
├── smart_refetch_v2.php    # Intelligent data gap detection & refetch
├── config.php              # Environment variable loader
├── app.js                  # Frontend JavaScript (auth, map, UI)
├── index.html              # Main HTML page
├── style.css               # All styling (dark/light theme support)
├── .env.example            # Environment template
├── .htaccess               # Security rules (blocks sensitive files)
├── Dockerfile              # PHP 8.2 Apache image with cron
├── docker-compose.yml      # Service definition
├── docker-compose.prod.yml # Production overrides
├── deploy.sh               # Deployment script
└── docker/                 # Docker configuration files
    ├── entrypoint.sh       # Container startup (DB init, env setup)
    ├── supervisord.conf    # Process manager (Apache + cron)
    ├── crontab             # Scheduled jobs
    ├── apache-vhost.conf   # Apache configuration
    └── php.ini             # PHP settings
```

## Prerequisites

- **Docker** and **Docker Compose** (v2.x+)
- **SenseCAP Account** with API credentials ([console.sensecap.seeed.cc](https://console.sensecap.seeed.cc))
- **Google Cloud API Key** for Wi-Fi geolocation (optional but recommended)

## Local Development Setup

### 1. Clone and Configure

```bash
# Clone the repository
git clone <repository-url>
cd tracker

# Copy environment template
cp .env.example .env

# Edit configuration
nano .env
```

### 2. Required Environment Variables

```env
# SenseCAP API (REQUIRED)
SENSECAP_ACCESS_ID=your_access_id
SENSECAP_ACCESS_KEY=your_access_key
SENSECAP_DEVICE_EUI=your_device_eui

# Google Geolocation (optional but recommended)
GOOGLE_API_KEY=your_google_api_key
```

### 3. Deploy with Docker

```bash
# Make deploy script executable
chmod +x deploy.sh

# Start in development mode
./deploy.sh dev

# Or production mode
./deploy.sh
```

The application will be available at `http://localhost:8080` (default port).

### 4. Access the Application

1. Open `http://localhost:8080` in your browser
2. Enter the PIN code (default: `1234`)
3. View the map with position history

## Environment Variables

### Required

| Variable | Description |
|----------|-------------|
| `SENSECAP_ACCESS_ID` | SenseCAP API access ID |
| `SENSECAP_ACCESS_KEY` | SenseCAP API access key |
| `SENSECAP_DEVICE_EUI` | Device EUI to track |

### Optional

| Variable | Default | Description |
|----------|---------|-------------|
| `TRACKER_PORT` | `8080` | Host port for the application |
| `GOOGLE_API_KEY` | - | Google Geolocation API key |
| `ACCESS_PIN_HASH` | (1234 hash) | SHA-256 hash of access PIN |
| `HYSTERESIS_METERS` | `50` | Min distance between recorded positions |
| `HYSTERESIS_MINUTES` | `30` | Min time between positions at same location |
| `EMAIL_SERVICE_URL` | - | URL for perimeter alert emails |
| `EMAIL_API_KEY` | - | API key for email service |
| `EMAIL_FROM` | `tracker@example.com` | From address for alerts |
| `N8N_API_KEY` | - | API key for n8n integration endpoints |
| `LOG_LEVEL` | `info` | Logging level (error, info, debug) |
| `SSO_ENABLED` | `false` | Enable SSO authentication |

## How to Run Locally

### Using Docker (Recommended)

```bash
# Development mode (single compose file)
./deploy.sh dev

# Production mode (with resource limits)
./deploy.sh

# View logs
./deploy.sh logs

# Check status
./deploy.sh status

# Stop
./deploy.sh stop

# Backup database
./deploy.sh backup

# Clean everything
./deploy.sh clean
```

### Manual Data Fetch

```bash
# Inside the container
docker exec -it gps-tracker php fetch_data.php

# Refetch specific date
docker exec -it gps-tracker php fetch_data.php --refetch-date=2025-01-15

# Smart refetch (detect missing data)
docker exec -it gps-tracker php smart_refetch_v2.php
```

## API Endpoints

The API is accessed via `api.php?action=<action>`. Key endpoints:

| Action | Method | Description |
|--------|--------|-------------|
| `health` | GET | Health check |
| `get_history` | GET | Get positions for a date |
| `get_last_position` | GET | Latest position |
| `verify_pin` | POST | PIN authentication |
| `get_settings` | GET | Application settings |
| `save_settings` | POST | Update settings |
| `get_perimeters` | GET | List perimeter zones |
| `save_perimeter` | POST | Create/update perimeter |
| `n8n_status` | GET | Device status for n8n |
| `n8n_events` | GET | Perimeter events for automation |

## Features

### Authentication

- **PIN Mode**: 4-digit PIN with SHA-256 hashing, 24-hour session
- **SSO Mode**: Integration with external auth server

### Position Tracking

- Automatic data collection every 5 minutes via cron
- Multiple sources: GNSS, Wi-Fi geolocation, iBeacon
- Hysteresis filtering to reduce duplicate positions

### Perimeter Zones

- Draw polygon zones on the map
- Configure entry/exit alerts per zone
- Multiple email recipients per zone
- Alert history tracking

### Smart Refetch

- Detects delayed data uploads from device
- Automatically fills data gaps
- Daily and weekly scheduled runs

## Changing the PIN

```bash
# Generate SHA-256 hash of your new PIN
echo -n "your_pin" | sha256sum

# Update .env file
ACCESS_PIN_HASH=<generated_hash>

# Restart container
./deploy.sh restart
```

## Troubleshooting

### "No data on map"

1. Check that SenseCAP credentials are correct in `.env`
2. Verify the device has uploaded data to SenseCAP portal
3. Check logs: `./deploy.sh logs`
4. Run manual fetch: `docker exec -it gps-tracker php fetch_data.php`

### "PIN not working"

1. Verify `ACCESS_PIN_HASH` in `.env` matches your PIN
2. Clear browser session storage
3. Default PIN is `1234`

### "Wi-Fi locations not appearing"

1. Ensure `GOOGLE_API_KEY` is set and valid
2. Google Geolocation API requires billing enabled

## License

Proprietary - Family use only.
