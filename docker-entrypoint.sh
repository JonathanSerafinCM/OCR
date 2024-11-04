#!/bin/bash

# Create required directories and set permissions
mkdir -p /run/php /var/log
touch /var/log/php-fpm-error.log
chown -R www-data:www-data /run/php /var/log/php-fpm-error.log

php artisan key:generate
php artisan migrate --force
php artisan cache:clear
php artisan config:clear

php-fpm -D
nginx -g 'daemon off;'
