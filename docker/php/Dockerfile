FROM php:8.3-fpm-alpine

COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer

RUN install-php-extensions pdo_pgsql pgsql redis intl zip bcmath gd exif pcntl opcache

ARG UID=1000
ARG GID=1000
# Réutilise le groupe s'il existe déjà (ex. GID 20 « staff » sur macOS).
RUN getent group ${GID} > /dev/null || addgroup -g ${GID} sigpro; \
    adduser -D -u ${UID} -G "$(getent group ${GID} | cut -d: -f1)" sigpro

COPY docker/php/php.ini /usr/local/etc/php/conf.d/sigpro.ini

WORKDIR /var/www/html
USER sigpro
