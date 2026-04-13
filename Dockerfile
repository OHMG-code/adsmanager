FROM php:8.2-apache-bookworm

# Apache
RUN a2enmod rewrite headers

# System deps + PHP extensions
RUN apt-get update && apt-get install -y \
    git unzip libzip-dev libxml2-dev curl \
    libc-client2007e-dev libkrb5-dev \
    && docker-php-ext-configure imap --with-kerberos --with-imap-ssl \
    && docker-php-ext-install pdo pdo_mysql soap zip imap \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# (opcjonalnie) Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# PHP ini
COPY ./php/conf.d/99-dev.ini /usr/local/etc/php/conf.d/99-dev.ini
COPY ./php/conf.d/99-xdebug.ini /usr/local/etc/php/conf.d/99-xdebug.ini

WORKDIR /var/www/html
