#!/bin/bash

php artisan key:generate
php artisan migrate --force
php artisan cache:clear
php artisan config:clear

service nginx start
php-fpm
