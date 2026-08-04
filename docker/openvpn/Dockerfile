# BOSS App — OpenVPN server node #1 (v0.6.2).
#
# Built from a plain alpine base + the distro's own openvpn/easy-rsa
# packages, same pattern as docker/freeradius and docker/php — NOT a
# third-party wrapper image. kylemanna/openvpn (the most common community
# choice) hasn't published an updated image since Dec 2020; for a
# genuinely public-facing VPN endpoint we'd rather track Alpine's own
# current packages (openvpn 2.6.20, easy-rsa 3.1.7 as of this sprint).
FROM alpine:3.20

RUN apk add --no-cache \
    openvpn \
    easy-rsa \
    iptables \
    bash

RUN ln -sf /usr/share/easy-rsa/easyrsa /usr/local/bin/easyrsa

COPY entrypoint.sh /entrypoint.sh
COPY server.conf.template /etc/openvpn/server.conf.template
RUN chmod +x /entrypoint.sh

EXPOSE 1194/udp

ENTRYPOINT ["/entrypoint.sh"]
