FROM php:8.5-fpm
RUN apt-get update \
    && apt-get install -y --no-install-recommends libpq-dev $PHPIZE_DEPS \
    && docker-php-ext-install -j2 pdo_pgsql pdo_mysql \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*
