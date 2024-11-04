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
    default-mysql-client

# Instalar extensiones PHP
RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install gd pdo pdo_mysql zip

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Create PHP-FPM socket directory
RUN mkdir -p /run/php && \
    chown www-data:www-data /run/php

# Copy PHP-FPM configuration
COPY php-fpm.conf /usr/local/etc/php-fpm.d/www.conf

# Configurar permisos
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html \
    && usermod -u 1000 www-data

# Copiar archivos de la aplicación
COPY . .
COPY nginx.conf /etc/nginx/nginx.conf

# Instalar dependencias de Composer
RUN git config --global --add safe.directory /var/www/html \
    && composer install --no-interaction --optimize-autoloader

# Script de inicio
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
