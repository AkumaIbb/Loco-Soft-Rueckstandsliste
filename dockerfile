FROM php:8.2-apache

# Install extensions and tools
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        git \
        unzip \
        libpq-dev \
        libzip-dev \
    && docker-php-ext-install pdo_mysql pdo_pgsql zip \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

# Copy composer files and install dependencies
COPY app/composer.json app/composer.lock ./
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer \
    && composer install --no-dev --optimize-autoloader

# Copy application source
COPY app/ .

# Ensure mount point for import folder exists
RUN mkdir -p /mnt/Import-Folder
