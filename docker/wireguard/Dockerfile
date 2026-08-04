# BOSS App — WireGuard VPN node (v0.6.3), separate container from openvpn
# by explicit decision: WireGuard needs a kernel netlink interface type +
# NET_ADMIN, an entirely different mechanism from OpenVPN/easy-rsa's
# file-based PKI — jamming both into one container would violate this
# repo's single-responsibility container pattern (freeradius-db separate
# from freeradius, whatsapp-gateway separate from boss-app, etc).
FROM alpine:3.20

RUN apk add --no-cache \
    wireguard-tools \
    iptables \
    bash

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 51820/udp

ENTRYPOINT ["/entrypoint.sh"]
