#!/bin/bash

php artisan key:generate
php artisan migrate --force
php artisan cache:clear
php artisan config:clear

php-fpm -D  # Start php-fpm in the background
nginx -g 'daemon off;'  # Run nginx in the foreground
