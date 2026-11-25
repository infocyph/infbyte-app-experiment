FROM dunglas/frankenphp:1-php8.6-bookworm
RUN install-php-extensions pdo_pgsql redis intl zip
