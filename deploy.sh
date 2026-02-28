#!/bin/bash
#
# GPS/Location Tracker - Deployment Script
#
# Usage:
#   ./deploy.sh              # Deploy/update in production mode
#   ./deploy.sh dev          # Deploy in development mode
#   ./deploy.sh stop         # Stop all containers
#   ./deploy.sh restart      # Restart containers
#   ./deploy.sh logs         # View logs
#   ./deploy.sh status       # Check status
#   ./deploy.sh backup       # Backup database
#   ./deploy.sh restore FILE # Restore database from backup
#   ./deploy.sh clean        # Remove all containers and volumes
#

set -e

# Configuration
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_NAME="gps-tracker"
COMPOSE_FILE="docker-compose.yml"
COMPOSE_PROD_FILE="docker-compose.prod.yml"
BACKUP_DIR="${SCRIPT_DIR}/backups"
DATA_VOLUME="gps-tracker-data"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Helper functions
log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

# Check prerequisites
check_prerequisites() {
    if ! command -v docker &> /dev/null; then
        log_error "Docker is not installed. Please install Docker first."
        exit 1
    fi

    if ! docker compose version &> /dev/null; then
        log_error "Docker Compose is not available. Please install Docker Compose."
        exit 1
    fi

    if [ ! -f "${SCRIPT_DIR}/.env" ]; then
        log_warning ".env file not found. Creating from .env.example..."
        if [ -f "${SCRIPT_DIR}/.env.example" ]; then
            cp "${SCRIPT_DIR}/.env.example" "${SCRIPT_DIR}/.env"
            log_warning "Please edit .env file with your configuration before deploying."
            exit 1
        else
            log_error ".env.example not found. Cannot create .env file."
            exit 1
        fi
    fi

    # Fix .env permissions if owned by www-data (uid 33) with restrictive perms
    if [ ! -r "${SCRIPT_DIR}/.env" ]; then
        log_warning ".env not readable (likely owned by www-data). Fixing permissions..."
        sudo chmod 644 "${SCRIPT_DIR}/.env" 2>/dev/null || {
            log_error "Cannot fix .env permissions. Run: sudo chmod 644 ${SCRIPT_DIR}/.env"
            exit 1
        }
        log_success ".env permissions fixed"
    fi
}

# Deploy in production mode
deploy_prod() {
    log_info "Deploying GPS Tracker in production mode..."

    check_prerequisites

    cd "${SCRIPT_DIR}"

    # Build and start containers
    log_info "Building Docker image..."
    docker compose -f "${COMPOSE_FILE}" -f "${COMPOSE_PROD_FILE}" build

    log_info "Starting containers..."
    docker compose -f "${COMPOSE_FILE}" -f "${COMPOSE_PROD_FILE}" up -d

    # Wait for health check
    log_info "Waiting for service to be healthy..."
    sleep 5

    # Check if container is running
    if docker compose -f "${COMPOSE_FILE}" ps | grep -q "running"; then
        log_success "GPS Tracker deployed successfully!"

        # Get port from .env or default
        PORT=$(grep "^TRACKER_PORT=" .env 2>/dev/null | cut -d'=' -f2 || echo "8080")
        log_info "Application available at: http://localhost:${PORT}"
    else
        log_error "Deployment failed. Check logs with: ./deploy.sh logs"
        exit 1
    fi
}

# Deploy in development mode
deploy_dev() {
    log_info "Deploying GPS Tracker in development mode..."

    check_prerequisites

    cd "${SCRIPT_DIR}"

    # Build and start containers
    log_info "Building Docker image..."
    docker compose -f "${COMPOSE_FILE}" build

    log_info "Starting containers..."
    docker compose -f "${COMPOSE_FILE}" up -d

    sleep 3

    if docker compose -f "${COMPOSE_FILE}" ps | grep -q "running"; then
        log_success "GPS Tracker (dev) deployed successfully!"
        PORT=$(grep "^TRACKER_PORT=" .env 2>/dev/null | cut -d'=' -f2 || echo "8080")
        log_info "Application available at: http://localhost:${PORT}"
    else
        log_error "Deployment failed. Check logs with: ./deploy.sh logs"
        exit 1
    fi
}

# Stop containers
stop_containers() {
    log_info "Stopping GPS Tracker..."
    cd "${SCRIPT_DIR}"
    docker compose -f "${COMPOSE_FILE}" down
    log_success "GPS Tracker stopped."
}

# Restart containers
restart_containers() {
    log_info "Restarting GPS Tracker..."
    cd "${SCRIPT_DIR}"
    docker compose -f "${COMPOSE_FILE}" restart
    log_success "GPS Tracker restarted."
}

# View logs
view_logs() {
    cd "${SCRIPT_DIR}"
    docker compose -f "${COMPOSE_FILE}" logs -f --tail=100
}

# Check status
check_status() {
    log_info "GPS Tracker Status:"
    echo ""
    cd "${SCRIPT_DIR}"
    docker compose -f "${COMPOSE_FILE}" ps
    echo ""

    # Health check
    PORT=$(grep "^TRACKER_PORT=" .env 2>/dev/null | cut -d'=' -f2 || echo "8080")
    if curl -sf "http://localhost:${PORT}/api.php?action=health" > /dev/null 2>&1; then
        log_success "Health check: HEALTHY"
    else
        log_warning "Health check: UNHEALTHY or not responding"
    fi
}

# Backup database
backup_database() {
    log_info "Backing up database..."

    mkdir -p "${BACKUP_DIR}"

    TIMESTAMP=$(date +"%Y%m%d_%H%M%S")
    BACKUP_FILE="${BACKUP_DIR}/tracker_backup_${TIMESTAMP}.sqlite"

    # Copy database from volume
    docker run --rm \
        -v "${DATA_VOLUME}:/data:ro" \
        -v "${BACKUP_DIR}:/backup" \
        alpine \
        cp /data/tracker_database.sqlite "/backup/tracker_backup_${TIMESTAMP}.sqlite"

    if [ -f "${BACKUP_FILE}" ]; then
        log_success "Database backed up to: ${BACKUP_FILE}"

        # Keep only last 10 backups
        cd "${BACKUP_DIR}"
        ls -t tracker_backup_*.sqlite 2>/dev/null | tail -n +11 | xargs -r rm
        log_info "Cleanup: Keeping last 10 backups."
    else
        log_error "Backup failed!"
        exit 1
    fi
}

# Restore database
restore_database() {
    if [ -z "$1" ]; then
        log_error "Please specify backup file to restore."
        echo "Usage: ./deploy.sh restore <backup_file>"
        echo ""
        echo "Available backups:"
        ls -la "${BACKUP_DIR}"/tracker_backup_*.sqlite 2>/dev/null || echo "No backups found."
        exit 1
    fi

    RESTORE_FILE="$1"

    if [ ! -f "${RESTORE_FILE}" ]; then
        # Try in backup directory
        RESTORE_FILE="${BACKUP_DIR}/$1"
    fi

    if [ ! -f "${RESTORE_FILE}" ]; then
        log_error "Backup file not found: $1"
        exit 1
    fi

    log_warning "This will replace the current database!"
    read -p "Are you sure? (y/N) " -n 1 -r
    echo

    if [[ $REPLY =~ ^[Yy]$ ]]; then
        log_info "Stopping containers..."
        docker compose -f "${COMPOSE_FILE}" down

        log_info "Restoring database..."
        docker run --rm \
            -v "${DATA_VOLUME}:/data" \
            -v "$(dirname "${RESTORE_FILE}"):/backup:ro" \
            alpine \
            cp "/backup/$(basename "${RESTORE_FILE}")" /data/tracker_database.sqlite

        log_info "Starting containers..."
        docker compose -f "${COMPOSE_FILE}" up -d

        log_success "Database restored from: ${RESTORE_FILE}"
    else
        log_info "Restore cancelled."
    fi
}

# Clean everything
clean_all() {
    log_warning "This will remove all containers, images, and volumes!"
    read -p "Are you sure? (y/N) " -n 1 -r
    echo

    if [[ $REPLY =~ ^[Yy]$ ]]; then
        cd "${SCRIPT_DIR}"

        log_info "Stopping and removing containers..."
        docker compose -f "${COMPOSE_FILE}" down -v --rmi local

        log_success "Cleanup complete."
    else
        log_info "Cleanup cancelled."
    fi
}

# Print usage
print_usage() {
    echo "GPS/Location Tracker - Deployment Script"
    echo ""
    echo "Usage: $0 <command>"
    echo ""
    echo "Commands:"
    echo "  (none)       Deploy in production mode"
    echo "  dev          Deploy in development mode"
    echo "  stop         Stop all containers"
    echo "  restart      Restart containers"
    echo "  logs         View container logs"
    echo "  status       Check service status"
    echo "  backup       Backup database"
    echo "  restore FILE Restore database from backup"
    echo "  clean        Remove all containers and volumes"
    echo "  help         Show this help message"
    echo ""
}

# Main
case "${1:-}" in
    "")
        deploy_prod
        ;;
    "dev")
        deploy_dev
        ;;
    "stop")
        stop_containers
        ;;
    "restart")
        restart_containers
        ;;
    "logs")
        view_logs
        ;;
    "status")
        check_status
        ;;
    "backup")
        backup_database
        ;;
    "restore")
        restore_database "$2"
        ;;
    "clean")
        clean_all
        ;;
    "help"|"-h"|"--help")
        print_usage
        ;;
    *)
        log_error "Unknown command: $1"
        print_usage
        exit 1
        ;;
esac
