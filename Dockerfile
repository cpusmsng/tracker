# GPS/Location Tracker - Docker Image
# PHP 8.2 with Apache, SQLite, and cron support

FROM php:8.2-apache

LABEL maintainer="GPS Tracker App"
LABEL description="GPS/Location Tracker with Wi-Fi geolocation, GNSS, and iBeacon support"

# Install system dependencies
RUN apt-get update && apt-get install -y --no-install-recommends \
    cron \
    sqlite3 \
    libsqlite3-dev \
    supervisor \
    curl \
    procps \
    && rm -rf /var/lib/apt/lists/*

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_sqlite

# Enable Apache modules
RUN a2enmod rewrite headers

# Configure PHP
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/php.ini /usr/local/etc/php/conf.d/custom.ini

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY --chown=www-data:www-data . /var/www/html/

# Create necessary directories and set permissions
RUN mkdir -p /var/www/html/data /var/log/tracker \
    && chown -R www-data:www-data /var/www/html /var/log/tracker \
    && chmod -R 755 /var/www/html \
    && chmod 777 /var/www/html/data /var/log/tracker

# Copy configuration files
COPY docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf
COPY docker/crontab /etc/cron.d/tracker-cron
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /entrypoint.sh

# Set permissions for cron and entrypoint
RUN chmod 0644 /etc/cron.d/tracker-cron \
    && chmod +x /entrypoint.sh

# Expose port 80
EXPOSE 80

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=5s --retries=3 \
    CMD curl -f http://localhost/api.php?action=health || exit 1

# Use supervisor to run Apache and cron
ENTRYPOINT ["/entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf"]
