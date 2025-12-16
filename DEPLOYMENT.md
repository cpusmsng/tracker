# Deployment Guide

This guide covers deploying Family Tracker using Docker Compose in both development and production environments.

## Table of Contents

- [Quick Start](#quick-start)
- [Docker Compose Deployment](#docker-compose-deployment)
- [Environment Variables](#environment-variables)
- [Volume Mounts](#volume-mounts)
- [Network Configuration](#network-configuration)
- [Database Initialization](#database-initialization)
- [How to Update/Redeploy](#how-to-updateredeploy)
- [Backup Considerations](#backup-considerations)
- [Reverse Proxy Setup](#reverse-proxy-setup)
- [Troubleshooting](#troubleshooting)

## Quick Start

```bash
# 1. Clone repository
git clone <repository-url>
cd tracker

# 2. Configure environment
cp .env.example .env
nano .env  # Add your SenseCAP credentials

# 3. Deploy
chmod +x deploy.sh
./deploy.sh

# Application available at http://localhost:8080
```

## Docker Compose Deployment

### Development Mode

Uses only `docker-compose.yml`:

```bash
./deploy.sh dev
# or
docker compose up -d
```

### Production Mode

Uses both compose files with additional resource limits:

```bash
./deploy.sh
# or
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

### Deploy Script Commands

| Command | Description |
|---------|-------------|
| `./deploy.sh` | Production deployment |
| `./deploy.sh dev` | Development mode |
| `./deploy.sh stop` | Stop containers |
| `./deploy.sh restart` | Restart containers |
| `./deploy.sh logs` | View logs (tail -f) |
| `./deploy.sh status` | Check health status |
| `./deploy.sh backup` | Backup database |
| `./deploy.sh restore <file>` | Restore from backup |
| `./deploy.sh clean` | Remove everything |

## Environment Variables

### Required for Production

```env
# SenseCAP API (REQUIRED)
SENSECAP_ACCESS_ID=your_access_id
SENSECAP_ACCESS_KEY=your_access_key
SENSECAP_DEVICE_EUI=your_device_eui
```

### Complete Production Configuration

```env
# =============================================================================
# REQUIRED CONFIGURATION
# =============================================================================

# SenseCAP API Credentials
SENSECAP_ACCESS_ID=your_access_id_here
SENSECAP_ACCESS_KEY=your_access_key_here
SENSECAP_DEVICE_EUI=your_device_eui_here

# =============================================================================
# RECOMMENDED CONFIGURATION
# =============================================================================

# Port to expose the tracker application
TRACKER_PORT=8080

# Google Geolocation API (for Wi-Fi positioning)
GOOGLE_API_KEY=your_google_api_key

# Security - PIN Access (CHANGE IN PRODUCTION!)
# Generate hash: echo -n "your_pin" | sha256sum
ACCESS_PIN_HASH=03ac674216f3e15c761ee1a5e255f067953623c8b388b4459e13f978d7c846f4

# =============================================================================
# OPTIONAL CONFIGURATION
# =============================================================================

# SenseCAP Settings
SENSECAP_CHANNEL_INDEX=1
SENSECAP_LIMIT=50

# Measurement IDs (SenseCAP specific)
MEAS_WIFI_MAC=5001
MEAS_BT_IBEACON_MAC=5002
MEAS_GNSS_LON=4197
MEAS_GNSS_LAT=4198

# Hysteresis Settings (position deduplication)
HYSTERESIS_METERS=50
HYSTERESIS_MINUTES=30
UNIQUE_PRECISION=6
UNIQUE_BUCKET_MINUTES=30
MAC_CACHE_MAX_AGE_DAYS=3600

# Google Settings
GOOGLE_FORCE=0

# Email Service (for perimeter alerts)
EMAIL_SERVICE_URL=http://email-service:3004/send
EMAIL_API_KEY=your_email_api_key
EMAIL_FROM=tracker@example.com
EMAIL_FROM_NAME=Family Tracker

# N8N Integration (optional)
N8N_API_KEY=your_n8n_api_key

# Logging Configuration
LOG_LEVEL=info

# SSO Authentication (optional)
SSO_ENABLED=false
AUTH_API_URL=http://family-office:3001
LOGIN_URL=https://bagron.eu/login
```

### Variable Descriptions

| Variable | Required | Description |
|----------|----------|-------------|
| `SENSECAP_ACCESS_ID` | Yes | SenseCAP console API access ID |
| `SENSECAP_ACCESS_KEY` | Yes | SenseCAP console API access key |
| `SENSECAP_DEVICE_EUI` | Yes | EUI of the device to track |
| `TRACKER_PORT` | No | Host port (default: 8080) |
| `GOOGLE_API_KEY` | Recommended | Enables Wi-Fi geolocation |
| `ACCESS_PIN_HASH` | Recommended | SHA-256 hash of access PIN |
| `EMAIL_SERVICE_URL` | For alerts | HTTP endpoint for sending emails |
| `EMAIL_API_KEY` | For alerts | API key for email service |
| `N8N_API_KEY` | For automation | Protects n8n API endpoints |
| `SSO_ENABLED` | No | Enable SSO instead of PIN auth |

## Volume Mounts

The application uses two Docker volumes:

### `gps-tracker-data`

**Purpose:** Persistent SQLite database storage

**Mount point:** `/var/www/html/data`

**Contents:**
- `tracker_database.sqlite` - Main database with all position data

**Backup important:** Yes - contains all historical tracking data

### `gps-tracker-logs`

**Purpose:** Application logs

**Mount point:** `/var/log/tracker`

**Contents:**
- `fetch.log` - Data collection logs
- `smart_refetch.log` - Smart refetch logs
- `php-error.log` - PHP error logs

**Backup important:** No - can be regenerated

### Manual Volume Access

```bash
# List volume contents
docker run --rm -v gps-tracker-data:/data alpine ls -la /data

# Copy database out of volume
docker run --rm -v gps-tracker-data:/data -v $(pwd):/backup alpine \
    cp /data/tracker_database.sqlite /backup/

# Execute SQLite queries
docker run --rm -v gps-tracker-data:/data -it alpine \
    sqlite3 /data/tracker_database.sqlite "SELECT COUNT(*) FROM tracker_data"
```

## Network Configuration

### Default Networks

```yaml
networks:
  tracker-network:
    name: gps-tracker-network
    driver: bridge
  bagron-network:
    external: true
    name: bagron-network
```

**`gps-tracker-network`** - Internal bridge network for the tracker container.

**`bagron-network`** - External network for SSO authentication with family-office service. Only required if `SSO_ENABLED=true`.

### Ports

| Port | Purpose |
|------|---------|
| `${TRACKER_PORT:-8080}:80` | Main application HTTP |

### External Connectivity

The container needs outbound access to:

| Service | URL | Purpose |
|---------|-----|---------|
| SenseCAP API | `https://sensecap.seeed.cc/openapi/*` | Device data |
| Google Geolocation | `https://www.googleapis.com/geolocation/v1/*` | Wi-Fi positioning |
| Email Service | Configured URL | Perimeter alerts |
| Auth Server | Configured URL | SSO authentication |

## Database Initialization

The database is automatically initialized on first container start via `docker/entrypoint.sh`.

### Schema Overview

| Table | Purpose |
|-------|---------|
| `tracker_data` | Position records (timestamp, lat, lng, source) |
| `device_status` | Device battery and online status |
| `ibeacon_locations` | Static iBeacon location markers |
| `mac_locations` | Cached Wi-Fi geolocation lookups |
| `perimeters` | Zone definitions (polygon, alerts config) |
| `perimeter_emails` | Email recipients per zone |
| `perimeter_alerts` | Alert history |
| `perimeter_state` | Current inside/outside state |
| `refetch_state` | Smart refetch tracking |

### Manual Initialization

If you need to reinitialize the database:

```bash
# Stop containers
./deploy.sh stop

# Remove data volume
docker volume rm gps-tracker-data

# Start containers (will recreate database)
./deploy.sh
```

## How to Update/Redeploy

### Standard Update

```bash
# Pull latest code
git pull

# Rebuild and redeploy
./deploy.sh

# Or manually:
docker compose -f docker-compose.yml -f docker-compose.prod.yml build
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
```

### Update with Zero Downtime

```bash
# Build new image
docker compose -f docker-compose.yml -f docker-compose.prod.yml build

# Rolling update (brief downtime)
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --force-recreate
```

### Update Environment Variables

```bash
# Edit .env file
nano .env

# Restart to apply
./deploy.sh restart
```

## Backup Considerations

### Automated Backup

Use the deploy script:

```bash
# Create backup
./deploy.sh backup

# Backups saved to: ./backups/tracker_backup_YYYYMMDD_HHMMSS.sqlite
# Last 10 backups are retained automatically
```

### Manual Backup

```bash
# Copy from volume
docker run --rm \
    -v gps-tracker-data:/data:ro \
    -v $(pwd)/backups:/backup \
    alpine \
    cp /data/tracker_database.sqlite /backup/manual_backup.sqlite
```

### Restore from Backup

```bash
# Using deploy script (interactive)
./deploy.sh restore backups/tracker_backup_20250115_120000.sqlite

# Manual restore
docker compose down
docker run --rm \
    -v gps-tracker-data:/data \
    -v $(pwd)/backups:/backup:ro \
    alpine \
    cp /backup/tracker_backup_20250115_120000.sqlite /data/tracker_database.sqlite
docker compose up -d
```

### Backup Strategy Recommendations

| Type | Frequency | Retention |
|------|-----------|-----------|
| Automated | Daily (cron) | 7 days |
| Manual | Before updates | 1 month |
| Off-site | Weekly | 1 year |

## Reverse Proxy Setup

### Traefik

Enable Traefik labels in `docker-compose.prod.yml`:

```yaml
services:
  tracker:
    labels:
      - "traefik.enable=true"
      - "traefik.http.routers.tracker.rule=Host(`tracker.yourdomain.com`)"
      - "traefik.http.routers.tracker.entrypoints=websecure"
      - "traefik.http.routers.tracker.tls.certresolver=letsencrypt"
      - "traefik.http.services.tracker.loadbalancer.server.port=80"
```

### Nginx

```nginx
server {
    listen 443 ssl http2;
    server_name tracker.yourdomain.com;

    ssl_certificate /path/to/cert.pem;
    ssl_certificate_key /path/to/key.pem;

    location / {
        proxy_pass http://localhost:8080;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

## Troubleshooting

### Container Won't Start

```bash
# Check logs
docker compose logs tracker

# Check container status
docker compose ps

# Verify .env file exists
ls -la .env
```

**Common causes:**
- Missing `.env` file
- Invalid environment variable syntax
- Port already in use

### Health Check Failing

```bash
# Test health endpoint
curl http://localhost:8080/api.php?action=health

# Check inside container
docker exec -it gps-tracker curl http://localhost/api.php?action=health
```

**Common causes:**
- Apache not started
- PHP errors (check logs)
- Database connection issues

### No Data Appearing

```bash
# Check cron is running
docker exec -it gps-tracker pgrep -a cron

# Check fetch logs
docker exec -it gps-tracker tail -f /var/log/tracker/fetch.log

# Run manual fetch
docker exec -it gps-tracker php /var/www/html/fetch_data.php
```

**Common causes:**
- Invalid SenseCAP credentials
- Device not uploading data
- Network connectivity issues

### Database Issues

```bash
# Check database file exists
docker exec -it gps-tracker ls -la /var/www/html/data/

# Check database integrity
docker exec -it gps-tracker sqlite3 /var/www/html/data/tracker_database.sqlite "PRAGMA integrity_check"

# View recent records
docker exec -it gps-tracker sqlite3 /var/www/html/data/tracker_database.sqlite \
    "SELECT * FROM tracker_data ORDER BY timestamp DESC LIMIT 5"
```

### Permission Issues

```bash
# Fix data directory permissions
docker exec -it gps-tracker chown -R www-data:www-data /var/www/html/data

# Fix log directory permissions
docker exec -it gps-tracker chmod 755 /var/log/tracker
```

### Reset Everything

```bash
# Stop and remove everything
./deploy.sh clean

# Remove all Docker artifacts
docker compose down -v --rmi all
docker volume rm gps-tracker-data gps-tracker-logs

# Fresh start
./deploy.sh
```
