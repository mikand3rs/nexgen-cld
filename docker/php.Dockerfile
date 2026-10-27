FROM php:8.4-fpm-alpine

RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        postgresql-dev \
    && apk add --no-cache libpq \
    && docker-php-ext-install pdo_pgsql \
    && apk del .build-deps

WORKDIR /var/www/html

CMD ["php-fpm", "-F"]
