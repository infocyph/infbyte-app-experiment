FROM dunglas/frankenphp:1-php8.6-bookworm
RUN install-php-extensions pdo_pgsql pdo_mysql redis intl zip
