#!/bin/sh
set -e

# Mostrar mensaje de inicio
echo "\n🚀 Iniciando configuración del servidor...\n"

# Create required directories and set permissions
echo "📁 Creando directorios necesarios..."
mkdir -p /run/php /var/log/nginx
touch /var/log/php-fpm-error.log /var/log/nginx/access.log /var/log/nginx/error.log
chown -R www-data:www-data /run/php /var/log/php-fpm-error.log /var/log/nginx

# Create storage structure and set permissions
echo "🔐 Configurando permisos de almacenamiento..."
mkdir -p /var/www/html/storage/{framework/{sessions,views,cache},logs,app/temp}
chown -R www-data:www-data /var/www/html/storage
chmod -R 775 /var/www/html/storage

# Create and set permissions for Laravel log file
touch /var/www/html/storage/logs/laravel.log
chown www-data:www-data /var/www/html/storage/logs/laravel.log
chmod 664 /var/www/html/storage/logs/laravel.log

# Wait for MySQL to be ready
echo "🔄 Esperando que MySQL esté disponible..."
until mysqladmin ping -h"mysql_db" -u"$DB_USERNAME" -p"$DB_PASSWORD" --silent; do
    echo "⏳ Conectando con MySQL..."
    sleep 1
done

echo "✅ MySQL conectado correctamente"

# Clear and cache Laravel configurations
echo "\n🛠️  Configurando Laravel..."
php artisan view:clear && echo "✓ Vistas limpiadas"
php artisan cache:clear && echo "✓ Cache limpiado"
php artisan config:clear && echo "✓ Configuración limpiada"
php artisan config:cache && echo "✓ Configuración en cache"
php artisan route:clear && echo "✓ Rutas limpiadas"
php artisan route:cache && echo "✓ Rutas en cache"
php artisan migrate --force && echo "✓ Base de datos migrada"

# Start services
echo "\n🚀 Iniciando servicios..."
php-fpm &
echo "\n✨ ¡Todo listo! Puedes probar tu API en:\nhttp://localhost:8000/api/process-menu\n"

# Start Nginx
exec nginx -g 'daemon off;'