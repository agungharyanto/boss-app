FROM php:8.4-fpm-alpine

# System deps
# linux-headers: needed to compile ext-sockets below — Alpine doesn't ship
# full kernel headers by default, and sockets.c fails on a missing
# linux/sock_diag.h without it.
# openssl + easy-rsa: v0.6.2 — VpnProvisioningService runs `easyrsa` (Process
# facade) directly against the shared vpn-pki volume to issue/revoke OpenVPN
# client certs. This is the ONLY process that mutates that PKI besides the
# openvpn container's own first-boot CA/server-cert bootstrap — no Docker
# socket is mounted here (that would be a much bigger hole), this container
# just needs the same CLI tooling available locally.
RUN apk add --no-cache \
    bash \
    git \
    curl \
    unzip \
    postgresql-dev \
    libzip-dev \
    icu-dev \
    oniguruma-dev \
    autoconf \
    g++ \
    make \
    linux-headers \
    openssl \
    easy-rsa

# easy-rsa's script isn't on $PATH by default — same symlink as
# docker/openvpn's Dockerfile, so both containers invoke it identically.
RUN ln -sf /usr/share/easy-rsa/easyrsa /usr/local/bin/easyrsa

# PHP extensions
# sockets: required by evilfreelancer/routeros-api-php (v0.6.1, RouterOS API
# client used by NasService to test-connect to a NAS's Mikrotik API).
RUN docker-php-ext-install \
    pdo \
    pdo_pgsql \
    pgsql \
    zip \
    intl \
    mbstring \
    bcmath \
    opcache \
    sockets

# Redis extension via PECL
RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

EXPOSE 9000

CMD ["php-fpm"]
