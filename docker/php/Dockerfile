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

# wireguard-tools: v0.6.3 — VpnProvisioningService runs `wg genkey`/`wg
# pubkey` (Process facade) from here for WireGuard client keypair
# generation — pure crypto, no live network interface needed, so (unlike
# `wg set`/peer changes, which only affect the wireguard container's own
# network namespace) this part CAN run from boss-app directly. Kept in its
# own layer, after the slow ext-sockets compile above, so adding it doesn't
# invalidate that layer's cache (a mistake made once already this sprint —
# see CLAUDE.md "Multi-Protocol VPN (v0.6.3)").
RUN apk add --no-cache wireguard-tools

# rrdtool: v0.8.2 — LibreNmsService::getTrafficHistory() shells out to
# `rrdtool xport --json` (Process facade) directly against LibreNMS's own
# RRD files (shared read-only volume, see docker-compose.yml's boss-app
# librenms_data mount) — LibreNMS's REST API has no raw time-series JSON
# endpoint in this version (confirmed by reading routes/api.php directly,
# see CLAUDE.md's "Dashboard Monitoring (v0.8.2)" section). Own layer,
# same cache-locality reasoning as wireguard-tools above — added after
# the slow ext-sockets compile so it doesn't invalidate that layer's
# cache.
RUN apk add --no-cache rrdtool

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# v0.8.x — self-heals /tmp's permission (1777) at container start AND
# periodically for the container's whole lifetime, not just once at
# boot — see entrypoint.sh's own docblock for the real "419 on Riwayat"
# investigation this fixes and why a one-time chmod wasn't enough.
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

WORKDIR /var/www/html

EXPOSE 9000

ENTRYPOINT ["/entrypoint.sh"]
CMD ["php-fpm"]
