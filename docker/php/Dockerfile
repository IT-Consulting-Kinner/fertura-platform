# Core-Laufzeit: PHP-FPM mit den von CakePHP 5 + PostgreSQL benötigten Extensions
FROM php:8.3-fpm-bookworm

RUN apt-get update && apt-get install -y --no-install-recommends \
        git unzip \
        libicu-dev libpq-dev libzip-dev libonig-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-install -j"$(nproc)" \
        intl mbstring pdo_pgsql pgsql zip opcache \
    && rm -rf /var/lib/apt/lists/*

# Composer aus dem offiziellen Image übernehmen
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# PHP-FPM: Container-Umgebungsvariablen an PHP durchreichen
# (Default wäre clear_env=yes -> env('DATABASE_URL') wäre sonst leer)
RUN printf '[www]\nclear_env = no\n' > /usr/local/etc/php-fpm.d/zz-clearenv.conf

WORKDIR /var/www/html
