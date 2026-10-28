FROM php:8.4-fpm-alpine

# v2/v3 additions, with reasons:
#  - curl: ai-connect.php already requires it (curl_init), but v1's
#    image did not explicitly install it. Verify with
#    `php -m | grep -i curl` inside the container after building.
#  - mbstring: chunking / text handling (fallback exists in code,
#    but mbstring is the correct dependency, not a workaround).
#  - fileinfo: upload validation uses finfo (actual file type).
#  - poppler-utils: pdftotext for PDF ingestion. The worker falls
#    back to a pure-PHP extractor if pdftotext is missing, but
#    pdftotext is the reliable path for real PDFs.
RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        postgresql-dev \
        curl-dev \
    && apk add --no-cache libpq libcurl poppler-utils \
    && docker-php-ext-install pdo_pgsql curl mbstring fileinfo \
    && apk del .build-deps

WORKDIR /var/www/html

CMD ["php-fpm", "-F"]
