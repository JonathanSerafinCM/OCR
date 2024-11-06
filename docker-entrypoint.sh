#!/bin/sh
set -e

# Create required directories and set permissions
mkdir -p /run/php /var/log/nginx
touch /var/log/php-fpm-error.log /var/log/nginx/access.log /var/log/nginx/error.log
chown -R www-data:www-data /run/php /var/log/php-fpm-error.log /var/log/nginx

# Create storage structure and set permissions
mkdir -p /var/www/html/storage/{framework/{sessions,views,cache},logs,app/temp}
chown -R www-data:www-data /var/www/html/storage
chmod -R 775 /var/www/html/storage

# Create and set permissions for Laravel log file
touch /var/www/html/storage/logs/laravel.log
chown www-data:www-data /var/www/html/storage/logs/laravel.log
chmod 664 /var/www/html/storage/logs/laravel.log

# Wait for MySQL to be ready
until mysqladmin ping -h"mysql_db" -u"$DB_USERNAME" -p"$DB_PASSWORD" --silent; do
    echo "Waiting for MySQL..."
    sleep 1
done

# Clear and cache Laravel configurations
php artisan view:clear
php artisan cache:clear
php artisan config:clear
php artisan config:cache
php artisan route:clear
php artisan route:cache
php artisan migrate --force

# Start PHP-FPM in the background
php-fpm &

# Start Nginx in the foreground
exec nginx -g 'daemon off;'
