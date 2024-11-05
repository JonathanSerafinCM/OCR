FROM php:8.2-fpm

WORKDIR /var/www/html

# Instalar dependencias
RUN apt-get update && apt-get install -y \
    tesseract-ocr \
    tesseract-ocr-spa \
    poppler-utils \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    nginx \
    git \
    unzip \
    libzip-dev \
    default-mysql-client \
    dos2unix

# Instalar extensiones PHP
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd pdo pdo_mysql zip

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Create PHP-FPM socket directory
RUN mkdir -p /run/php && \
    chown www-data:www-data /run/php

# Copy PHP-FPM configuration
COPY php-fpm.conf /usr/local/etc/php-fpm.d/zz-docker.conf

# Copy composer files first
COPY composer.json composer.lock ./ 

# Set proper permissions and install dependencies
ENV COMPOSER_ALLOW_SUPERUSER=1
RUN composer install --no-scripts --no-autoloader

# Copiar archivos de la aplicación
COPY . .

# Configurar permisos y finalizar Composer
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && composer dump-autoload --optimize

COPY nginx.conf /etc/nginx/nginx.conf

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]


EXPOSE 80

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
