
FROM php:8.3-fpm

RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libicu-dev \
    && docker-php-ext-install \
       pdo_mysql zip intl bcmath opcache \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html
