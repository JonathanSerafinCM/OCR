#!/bin/bash

# Create required directories and set permissions
mkdir -p /run/php /var/log
touch /var/log/php-fpm-error.log
chown -R www-data:www-data /run/php /var/log/php-fpm-error.log

# Laravel setup
php artisan key:generate
php artisan migrate --force
php artisan cache:clear
php artisan config:clear
php artisan route:clear   # Clear routes first
php artisan route:cache   # Then cache routes

php-fpm -D  # Start php-fpm in the background
nginx -g 'daemon off;'  # Run nginx in the foreground
