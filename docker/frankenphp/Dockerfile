FROM docker.kernel.ir/dunglas/frankenphp:latest

# نصب اکستنشن‌های ضروری برای Laravel
RUN apt-get update && apt-get install -y \
    libpng-dev \
    libjpeg-dev \
    libfreetype6-dev \
    libonig-dev \
    libzip-dev \
    unzip \
    git \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install \
        pdo \
        pdo_mysql \
        mbstring \
        exif \
        pcntl \
        bcmath \
        gd \
        zip \
        opcache \
    && rm -rf /var/lib/apt/lists/*

# نصب phpredis
RUN pecl install redis \
    && docker-php-ext-enable redis

WORKDIR /var/www/html
