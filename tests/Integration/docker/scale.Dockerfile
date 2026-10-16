FROM composer:2.10.3@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer
FROM php:8.5-cli-alpine@sha256:93684051146ec037620855feb77f278090bde45ddc030801cd3f2a7685bc4deb

RUN apk add --no-cache curl git unzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && docker-php-ext-install -j2 mysqli pdo_mysql \
    && apk del .build-deps

COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /work
