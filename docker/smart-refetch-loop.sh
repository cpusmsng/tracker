#!/bin/bash
# Smart refetch loop - runs smart_refetch_v2.php every 30 minutes
# More reliable than cron in Docker environments

cd /var/www/html

while true; do
    /usr/local/bin/php smart_refetch_v2.php >> /var/log/tracker/smart_refetch.log 2>&1
    sleep 1800
done
