FROM php:8.4-cli

# مسیر کاری پروژه
WORKDIR /var/www/html

# نصب ابزارهای مورد نیاز + libbrotli-dev برای Swoole
RUN apt-get update && apt-get install -y \
    libzip-dev \
    libpng-dev \
    libonig-dev \
    libxml2-dev \
    libbrotli-dev \          
    zip unzip git curl \
    && docker-php-ext-install pdo_mysql mbstring exif pcntl bcmath gd

# نصب Swoole
RUN pecl install swoole \
    && docker-php-ext-enable swoole

# نصب Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# کپی پروژه
COPY . /var/www/html

# تنظیم دسترسی‌ها
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 775 /var/www/html/storage \
    && chmod -R 775 /var/www/html/bootstrap/cache

# تغییر کاربر به www-data
USER www-data