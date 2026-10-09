# PHP + Apache + ionCube for a local WHMCS. WHMCS itself is not in the image:
# it is mounted from dev/whmcs/<version>, see setup.sh.
ARG PHP_VERSION=8.3
FROM php:${PHP_VERSION}-apache

ARG PHP_VERSION

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libfreetype6-dev libjpeg62-turbo-dev libpng-dev libzip-dev libicu-dev libgmp-dev unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql mysqli gd zip intl bcmath gmp \
    && rm -rf /var/lib/apt/lists/*

# WHMCS is ionCube-encoded; the loader has to match the PHP minor version.
RUN curl -fsSL https://downloads.ioncube.com/loader_downloads/ioncube_loaders_lin_x86-64.tar.gz -o /tmp/ioncube.tgz \
    && tar -xzf /tmp/ioncube.tgz -C /tmp \
    && cp "/tmp/ioncube/ioncube_loader_lin_${PHP_VERSION}.so" "$(php -r 'echo ini_get("extension_dir");')/" \
    && echo "zend_extension=ioncube_loader_lin_${PHP_VERSION}.so" > /usr/local/etc/php/conf.d/00-ioncube.ini \
    && rm -rf /tmp/ioncube /tmp/ioncube.tgz

RUN a2enmod rewrite

# The WHMCS license is bound to a domain and checked against SERVER_NAME.
# Pinning it to localhost keeps the license valid behind ./tunnel.sh, where
# the Host header (and WHMCS's System URL) is the public tunnel domain.
RUN printf 'ServerName localhost\nUseCanonicalName On\n' > /etc/apache2/conf-enabled/zz-whmcs-dev.conf

COPY php.ini /usr/local/etc/php/conf.d/whmcs.ini
