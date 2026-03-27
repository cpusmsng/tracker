#!/bin/bash
# Fetch data loop - runs fetch_worker_pool.php every 60 seconds
# More reliable than cron in Docker environments

cd /var/www/html

while true; do
    /usr/local/bin/php fetch_worker_pool.php >> /var/log/tracker/fetch.log 2>&1
    sleep 60
done
