# BOSS App — L2TP/IPsec VPN node (v0.6.3), separate container from openvpn
# AND wireguard by explicit decision: strongSwan (IKE/IPsec) + xl2tpd (L2TP)
# + pppd is a completely different stack again (needs /dev/ppp, spawns one
# ppp* interface per connected client rather than one shared tunnel
# interface) — a third genuinely distinct set of kernel/daemon requirements.
#
# strongswan (not libreswan): more actively released on Alpine (5.9.13 vs
# libreswan's 5.0) and the far more common reference for L2TP/IPsec PSK
# setups specifically (the classic Mikrotik-compatible shape this container
# implements).
FROM alpine:3.20

RUN apk add --no-cache \
    strongswan \
    xl2tpd \
    ppp \
    iptables \
    bash

COPY ipsec.conf.template /etc/ipsec.conf.template
COPY xl2tpd.conf.template /etc/xl2tpd/xl2tpd.conf.template
COPY options.xl2tpd /etc/ppp/options.xl2tpd
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

EXPOSE 500/udp 4500/udp 1701/udp

ENTRYPOINT ["/entrypoint.sh"]
