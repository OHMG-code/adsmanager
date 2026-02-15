FROM php:8.2-apache

# Apache
RUN a2enmod rewrite headers

# System deps + PHP extensions
RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libxml2-dev \
    && docker-php-ext-install pdo pdo_mysql soap zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# (opcjonalnie) Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# PHP ini (opcjonalnie: własny plik)
# COPY ./php/conf.d/99-dev.ini /usr/local/etc/php/conf.d/99-dev.ini

WORKDIR /var/www/html
