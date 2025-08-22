# Apache + PHP
FROM php:8.3-apache

# Systempakete + Dev-Header für GD/Zip/PgSQL
RUN apt-get update && apt-get install -y \
      git unzip libzip-dev libpq-dev \
      libfreetype6-dev libjpeg62-turbo-dev libpng-dev libwebp-dev \
  && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
  && docker-php-ext-install -j$(nproc) gd pdo_mysql pdo_pgsql zip pgsql \
  && a2enmod rewrite \
  && sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
  && rm -rf /var/lib/apt/lists/*

# Composer ins Image kopieren
COPY --from=composer:2.7 /usr/bin/composer /usr/bin/composer

# Arbeitsverzeichnis
WORKDIR /var/www/html
