#!/bin/bash
#
# Multi-Device Support - Deployment Script
#
# This script upgrades an existing GPS Tracker deployment to support
# multiple tracking devices.
#
# Usage:
#   ./deploy_multi_device.sh                    # Full upgrade (backup + migrate + rebuild + restart)
#   ./deploy_multi_device.sh --dry-run          # Show what would be done without changes
#   ./deploy_multi_device.sh --migrate-only     # Only run DB migration (no container rebuild)
#   ./deploy_multi_device.sh --rollback         # Rollback to pre-migration backup
#
# Safety:
#   - Creates automatic database backup before any changes
#   - Supports --dry-run to preview migration
#   - Rollback restores database from latest backup
#   - Does NOT touch .env (new vars are optional with defaults)
#

set -e

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMPOSE_FILE="docker-compose.yml"
COMPOSE_PROD_FILE="docker-compose.prod.yml"
CONTAINER_NAME="gps-tracker"
DATA_VOLUME="gps-tracker-data"
BACKUP_DIR="${SCRIPT_DIR}/backups"
DB_NAME="tracker_database.sqlite"

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
BOLD='\033[1m'
NC='\033[0m'

log_info()    { echo -e "${BLUE}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[OK]${NC} $1"; }
log_warning() { echo -e "${YELLOW}[WARN]${NC} $1"; }
log_error()   { echo -e "${RED}[ERROR]${NC} $1"; }
log_step()    { echo -e "\n${BOLD}=== $1 ===${NC}"; }

# -------------------------------------------------------------------
# Pre-flight checks
# -------------------------------------------------------------------
preflight() {
    log_step "Pre-flight checks"

    if ! command -v docker &> /dev/null; then
        log_error "Docker not installed."
        exit 1
    fi
    log_success "Docker installed"

    if ! docker compose version &> /dev/null; then
        log_error "Docker Compose not available."
        exit 1
    fi
    log_success "Docker Compose available"

    if [ ! -f "${SCRIPT_DIR}/.env" ]; then
        log_error ".env file not found. Run 'cp .env.example .env' first."
        exit 1
    fi
    log_success ".env file found"

    # Check if .env is readable (often owned by www-data uid 33 with 600 perms)
    if [ ! -r "${SCRIPT_DIR}/.env" ]; then
        log_warning ".env not readable (owned by uid 33 / www-data)"
        log_info "Fixing: sudo chmod 644 .env"
        sudo chmod 644 "${SCRIPT_DIR}/.env" 2>/dev/null || {
            log_error "Cannot fix .env permissions. Run: sudo chmod 644 ${SCRIPT_DIR}/.env"
            exit 1
        }
        log_success ".env permissions fixed (644)"
    fi

    if [ ! -f "${SCRIPT_DIR}/migrate_multi_device.php" ]; then
        log_error "migrate_multi_device.php not found. Are you on the right branch?"
        exit 1
    fi
    log_success "Migration script found"

    # Check if container is running
    if docker ps --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
        CONTAINER_RUNNING=true
        log_success "Container '${CONTAINER_NAME}' is running"
    else
        CONTAINER_RUNNING=false
        log_warning "Container '${CONTAINER_NAME}' is NOT running"
    fi

    # Check if database exists in the volume
    if [ "$CONTAINER_RUNNING" = true ]; then
        if docker exec "${CONTAINER_NAME}" test -f "/var/www/html/data/${DB_NAME}" 2>/dev/null; then
            log_success "Database found in container"
        else
            log_warning "Database not found in container (will be created on first start)"
        fi
    fi
}

# -------------------------------------------------------------------
# Backup database
# -------------------------------------------------------------------
backup_database() {
    log_step "Backing up database"

    mkdir -p "${BACKUP_DIR}"
    TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
    BACKUP_FILE="${BACKUP_DIR}/pre_multidevice_${TIMESTAMP}.sqlite"

    if [ "$CONTAINER_RUNNING" = true ]; then
        # Copy from running container
        docker cp "${CONTAINER_NAME}:/var/www/html/data/${DB_NAME}" "${BACKUP_FILE}" 2>/dev/null
    else
        # Copy from volume
        docker run --rm \
            -v "${DATA_VOLUME}:/data:ro" \
            -v "${BACKUP_DIR}:/backup" \
            alpine \
            cp "/data/${DB_NAME}" "/backup/pre_multidevice_${TIMESTAMP}.sqlite" 2>/dev/null || true
    fi

    if [ -f "${BACKUP_FILE}" ]; then
        local size
        size=$(du -h "${BACKUP_FILE}" | cut -f1)
        log_success "Backup saved: ${BACKUP_FILE} (${size})"
    else
        log_warning "No database to backup (new deployment?)"
        BACKUP_FILE=""
    fi
}

# -------------------------------------------------------------------
# Run database migration (dry-run or live)
# -------------------------------------------------------------------
run_migration() {
    local mode="$1"  # "dry-run" or "live"
    log_step "Database migration (${mode})"

    if [ "$CONTAINER_RUNNING" != true ]; then
        log_warning "Container not running. Migration will run on next container start via entrypoint.sh."
        log_info "The entrypoint automatically calls migrate_multi_device.php."
        return 0
    fi

    local flags=""
    if [ "$mode" = "dry-run" ]; then
        flags="--dry-run"
    fi

    log_info "Running migration inside container..."
    docker exec -u www-data "${CONTAINER_NAME}" \
        php /var/www/html/migrate_multi_device.php ${flags}

    local exit_code=$?
    if [ $exit_code -ne 0 ]; then
        log_error "Migration failed with exit code $exit_code"
        if [ "$mode" = "live" ] && [ -n "${BACKUP_FILE:-}" ]; then
            log_warning "You can rollback with: ./deploy_multi_device.sh --rollback"
        fi
        return $exit_code
    fi

    log_success "Migration ${mode} completed"
}

# -------------------------------------------------------------------
# Check and suggest .env updates
# -------------------------------------------------------------------
check_env_updates() {
    log_step "Checking .env configuration"

    local needs_update=false

    if ! grep -q "^DEVICE_NAME=" "${SCRIPT_DIR}/.env" 2>/dev/null; then
        log_info "Optional: Add DEVICE_NAME to .env for default device label"
        log_info "  echo 'DEVICE_NAME=Tracker' >> .env"
        needs_update=true
    else
        local device_name
        device_name=$(grep "^DEVICE_NAME=" "${SCRIPT_DIR}/.env" | cut -d'=' -f2)
        log_success "DEVICE_NAME is set: ${device_name}"
    fi

    if [ "$needs_update" = false ]; then
        log_success "No .env changes required"
    else
        log_info "These are optional - defaults will be used if not set."
    fi
}

# -------------------------------------------------------------------
# Fix .env permissions for Docker Compose
# -------------------------------------------------------------------
fix_env_permissions() {
    local env_file="${SCRIPT_DIR}/.env"
    if [ ! -r "${env_file}" ]; then
        log_warning ".env is not readable by current user (owned by www-data/uid 33)"
        log_info "Fixing permissions: chmod 644 .env"
        sudo chmod 644 "${env_file}" 2>/dev/null || {
            log_error "Cannot fix .env permissions. Run manually: sudo chmod 644 ${env_file}"
            exit 1
        }
        log_success ".env is now readable"
    fi
}

# -------------------------------------------------------------------
# Rebuild and restart container
# -------------------------------------------------------------------
rebuild_and_restart() {
    log_step "Rebuilding and restarting container"

    cd "${SCRIPT_DIR}"

    # Docker Compose needs to read .env for variable interpolation
    fix_env_permissions

    log_info "Building new Docker image..."
    if [ -f "${COMPOSE_PROD_FILE}" ]; then
        docker compose -f "${COMPOSE_FILE}" -f "${COMPOSE_PROD_FILE}" build
    else
        docker compose -f "${COMPOSE_FILE}" build
    fi
    log_success "Docker image built"

    log_info "Restarting containers..."
    if [ -f "${COMPOSE_PROD_FILE}" ]; then
        docker compose -f "${COMPOSE_FILE}" -f "${COMPOSE_PROD_FILE}" up -d
    else
        docker compose -f "${COMPOSE_FILE}" up -d
    fi

    log_info "Waiting for container to be healthy..."
    local retries=0
    local max_retries=30
    while [ $retries -lt $max_retries ]; do
        if docker exec "${CONTAINER_NAME}" curl -sf http://localhost/api.php?action=health > /dev/null 2>&1; then
            log_success "Container is healthy!"
            return 0
        fi
        retries=$((retries + 1))
        sleep 2
    done

    log_warning "Health check did not pass within 60s. Check logs: ./deploy.sh logs"
}

# -------------------------------------------------------------------
# Verify deployment
# -------------------------------------------------------------------
verify_deployment() {
    log_step "Verifying deployment"

    if ! docker ps --format '{{.Names}}' | grep -q "^${CONTAINER_NAME}$"; then
        log_error "Container is not running!"
        return 1
    fi
    log_success "Container is running"

    # Check if devices table exists
    local devices_count
    devices_count=$(docker exec "${CONTAINER_NAME}" \
        sqlite3 /var/www/html/data/${DB_NAME} \
        "SELECT COUNT(*) FROM devices" 2>/dev/null || echo "FAIL")

    if [ "$devices_count" = "FAIL" ]; then
        log_error "Cannot query devices table"
        return 1
    fi
    log_success "devices table exists (${devices_count} device(s))"

    # Check if tracker_data has device_id column
    local has_device_id
    has_device_id=$(docker exec "${CONTAINER_NAME}" \
        sqlite3 /var/www/html/data/${DB_NAME} \
        "PRAGMA table_info(tracker_data)" 2>/dev/null | grep -c "device_id" || true)

    if [ "${has_device_id:-0}" -ge 1 ]; then
        log_success "tracker_data has device_id column"
    else
        log_error "tracker_data missing device_id column"
        return 1
    fi

    # Check API endpoint
    local port
    port=$(grep "^TRACKER_PORT=" "${SCRIPT_DIR}/.env" 2>/dev/null | cut -d'=' -f2 || echo "8080")
    if curl -sf "http://localhost:${port}/api.php?action=get_devices" > /dev/null 2>&1; then
        log_success "get_devices API endpoint responding"
    else
        log_warning "get_devices API endpoint not responding (may need auth)"
    fi

    # Print summary
    echo ""
    log_info "Data summary:"
    docker exec "${CONTAINER_NAME}" sqlite3 /var/www/html/data/${DB_NAME} \
        "SELECT '  Devices: ' || COUNT(*) FROM devices;
         SELECT '  Positions: ' || COUNT(*) FROM tracker_data;
         SELECT '  Positions with device_id: ' || COUNT(*) FROM tracker_data WHERE device_id IS NOT NULL;
         SELECT '  Perimeters: ' || COUNT(*) FROM perimeters;
         SELECT '  Device perimeters: ' || COUNT(*) FROM device_perimeters;" 2>/dev/null || true
}

# -------------------------------------------------------------------
# Rollback
# -------------------------------------------------------------------
rollback() {
    log_step "Rolling back to pre-migration backup"

    # Find latest backup
    local latest_backup
    latest_backup=$(ls -t "${BACKUP_DIR}"/pre_multidevice_*.sqlite 2>/dev/null | head -1)

    if [ -z "$latest_backup" ]; then
        log_error "No backup files found in ${BACKUP_DIR}"
        echo ""
        echo "Available backups:"
        ls -la "${BACKUP_DIR}"/*.sqlite 2>/dev/null || echo "  None"
        exit 1
    fi

    log_info "Latest backup: ${latest_backup}"
    local size
    size=$(du -h "${latest_backup}" | cut -f1)
    log_info "Backup size: ${size}"

    echo ""
    log_warning "This will REPLACE the current database with the backup!"
    log_warning "All data added after the migration will be LOST."
    read -p "Are you sure? (y/N) " -n 1 -r
    echo

    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        log_info "Rollback cancelled."
        exit 0
    fi

    # Stop container
    cd "${SCRIPT_DIR}"
    log_info "Stopping container..."
    docker compose -f "${COMPOSE_FILE}" down

    # Restore database
    log_info "Restoring database..."
    docker run --rm \
        -v "${DATA_VOLUME}:/data" \
        -v "$(dirname "${latest_backup}"):/backup:ro" \
        alpine \
        cp "/backup/$(basename "${latest_backup}")" "/data/${DB_NAME}"

    # Restart with old code? No, just restart - the code is backward compatible
    log_info "Starting container..."
    if [ -f "${COMPOSE_PROD_FILE}" ]; then
        docker compose -f "${COMPOSE_FILE}" -f "${COMPOSE_PROD_FILE}" up -d
    else
        docker compose -f "${COMPOSE_FILE}" up -d
    fi

    log_success "Database restored from: ${latest_backup}"
    log_warning "NOTE: The entrypoint will re-run the migration on next start."
    log_warning "To prevent re-migration, revert to the previous code version."
}

# -------------------------------------------------------------------
# Print summary
# -------------------------------------------------------------------
print_summary() {
    echo ""
    echo -e "${BOLD}============================================${NC}"
    echo -e "${GREEN}Multi-device upgrade complete!${NC}"
    echo -e "${BOLD}============================================${NC}"
    echo ""
    echo "What changed:"
    echo "  - Database: New 'devices' and 'device_perimeters' tables"
    echo "  - Database: 'device_id' column added to tracker_data, perimeters"
    echo "  - Backend: fetch_data.php now processes all active devices"
    echo "  - Frontend: Device bar, management UI, comparison mode"
    echo ""
    echo "Next steps:"
    echo "  1. Open the tracker in your browser"
    echo "  2. Go to Menu > Zariadenia to manage devices"
    echo "  3. Add additional devices via the UI"
    echo "  4. Existing data is linked to the default device"
    echo ""
    if [ -n "${BACKUP_FILE:-}" ]; then
        echo "Rollback: ./deploy_multi_device.sh --rollback"
        echo "Backup:   ${BACKUP_FILE}"
    fi
    echo ""
}

# -------------------------------------------------------------------
# Main
# -------------------------------------------------------------------
case "${1:-}" in
    "--dry-run")
        echo -e "${BOLD}Multi-Device Upgrade - DRY RUN${NC}"
        echo "No changes will be made."
        echo ""
        preflight
        check_env_updates
        if [ "$CONTAINER_RUNNING" = true ]; then
            run_migration "dry-run"
        else
            log_info "Container not running - showing migration plan."
            log_info "Migration would run automatically on container start."
        fi
        echo ""
        log_info "To perform the actual upgrade, run: ./deploy_multi_device.sh"
        ;;

    "--migrate-only")
        echo -e "${BOLD}Multi-Device Upgrade - Migration Only${NC}"
        echo ""
        preflight
        backup_database
        check_env_updates
        run_migration "live"
        verify_deployment
        echo ""
        log_success "Migration complete. Container was NOT rebuilt."
        log_info "Run './deploy.sh restart' to pick up code changes."
        ;;

    "--rollback")
        rollback
        ;;

    "--help"|"-h")
        echo "Multi-Device Support - Deployment Script"
        echo ""
        echo "Usage: $0 [option]"
        echo ""
        echo "Options:"
        echo "  (none)           Full upgrade: backup + migrate + rebuild + restart"
        echo "  --dry-run        Preview changes without modifying anything"
        echo "  --migrate-only   Run database migration only (no rebuild)"
        echo "  --rollback       Restore database from pre-migration backup"
        echo "  --help           Show this help"
        echo ""
        ;;

    "")
        echo -e "${BOLD}============================================${NC}"
        echo -e "${BOLD}Multi-Device Upgrade${NC}"
        echo -e "${BOLD}============================================${NC}"
        echo ""

        preflight
        backup_database
        check_env_updates
        rebuild_and_restart

        # Migration runs automatically via entrypoint.sh, but verify it
        sleep 3
        verify_deployment
        print_summary
        ;;

    *)
        log_error "Unknown option: $1"
        echo "Run '$0 --help' for usage."
        exit 1
        ;;
esac
