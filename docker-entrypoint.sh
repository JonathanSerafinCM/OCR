#!/bin/bash

# Create required directories and set permissions
mkdir -p /run/php /var/log/nginx
touch /var/log/php-fpm-error.log /var/log/nginx/access.log /var/log/nginx/error.log
chown -R www-data:www-data /run/php /var/log/php-fpm-error.log /var/log/nginx

# Laravel setup with debugging
php artisan key:generate
php artisan migrate --force

# Clear all caches
php artisan config:clear
php artisan route:clear
php artisan cache:clear
php artisan view:clear

echo "Debug: Listing all routes"
php artisan route:list

echo "Debug: Optimizing application"
php artisan optimize

# Start services
echo "Starting PHP-FPM..."
php-fpm -D
echo "Starting Nginx..."
nginx -g 'daemon off;'
