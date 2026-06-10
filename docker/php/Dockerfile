# Core-Laufzeit: PHP-FPM mit den von CakePHP 5 + PostgreSQL benötigten Extensions
FROM php:8.3-fpm-bookworm

# PGDG-Repo, damit postgresql-client-17 (passend zum PG17-Server) verfügbar ist
# (Debian-Default waere 15 -> pg_dump verweigert gegen neueren Server).
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates gnupg curl \
    && install -d /usr/share/postgresql-common/pgdg \
    && curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc \
         -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc \
    && echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt bookworm-pgdg main" \
         > /etc/apt/sources.list.d/pgdg.list \
    && apt-get update && apt-get install -y --no-install-recommends \
        git unzip postgresql-client-17 \
        libicu-dev libpq-dev libzip-dev libonig-dev \
        libpng-dev libjpeg62-turbo-dev libfreetype6-dev \
    && docker-php-ext-configure intl \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" \
        intl mbstring pdo_pgsql pgsql zip opcache pcntl gd \
    && rm -rf /var/lib/apt/lists/*

# Composer aus dem offiziellen Image übernehmen
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# PHP-FPM: Container-Umgebungsvariablen an PHP durchreichen
# (Default wäre clear_env=yes -> env('DATABASE_URL') wäre sonst leer)
RUN printf '[www]\nclear_env = no\n' > /usr/local/etc/php-fpm.d/zz-clearenv.conf

# Entrypoint (macht frische Klone "clone & up"-fähig)
COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN sed -i 's/\r$//' /usr/local/bin/entrypoint.sh && chmod +x /usr/local/bin/entrypoint.sh

WORKDIR /var/www/html

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
