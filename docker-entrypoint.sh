#!/bin/bash

php artisan key:generate
php artisan migrate --force
php artisan cache:clear
php artisan config:clear

# Ensure correct permissions
mkdir -p /run/php
chown -R www-data:www-data /run/php

php-fpm -D  # Start php-fpm in the background
nginx -g 'daemon off;'  # Run nginx in the foreground
