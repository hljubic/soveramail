# Sovera Mail API runtime (Laravel 13 / PHP 8.5)
FROM php:8.5-fpm-alpine

ARG UID=1000
ARG GID=1000

# System deps + PHP extensions (via mlocati/php-extension-installer)
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
# `curl` CLI and `mysql-client` are deliberately omitted: the app uses PHP's
# curl/PDO extensions, and keeping those extra tools added avoidable CVE surface.
RUN apk upgrade --no-cache \
    && apk add --no-cache bash git icu-data-full tzdata \
    && install-php-extensions pdo_mysql intl zip bcmath pcntl opcache mbstring \
    && rm -rf /tmp/* /var/cache/apk/*

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# PHP configuration
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-sovera.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-sovera.conf

# Non-root runtime user matching the host UID (bind mounts stay writable)
RUN addgroup -g ${GID} -S sovera 2>/dev/null || true \
    && adduser -u ${UID} -S -G sovera -h /home/sovera -s /bin/bash sovera 2>/dev/null || true \
    && mkdir -p /home/sovera/.composer && chown -R ${UID}:${GID} /home/sovera

WORKDIR /var/www/html

COPY docker/php/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

USER ${UID}
ENV HOME=/home/sovera \
    COMPOSER_HOME=/home/sovera/.composer

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm"]
