#!/bin/sh
set -e

# اجرای migrate و cache بعد از اولین بوت
php artisan migrate:fresh --seed || true
php artisan config:cache
php artisan route:cache
php artisan view:cache

# اجرای FrankenPHP
exec php artisan octane:frankenphp
