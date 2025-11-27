FROM composer:2.8.11 AS composer

FROM php:8.4.15-cli-alpine3.22

RUN apk add --no-cache linux-headers \
    && apk add --update --no-cache --virtual .build-dependencies $PHPIZE_DEPS \
    && pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && pecl clear-cache \
    && docker-php-ext-install mysqli \
    && apk del .build-dependencies

COPY --from=composer /usr/bin/composer /usr/bin/composer

WORKDIR /app/
