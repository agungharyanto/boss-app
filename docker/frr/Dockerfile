# BOSS App — FRR (FRRouting) OSPF sidecar (v0.8.1 design, resource-test
# phase — NOT yet wired into docker-compose.yml as a permanent service).
#
# One shared image, deployed as a sidecar per target container via
# `network_mode: "service:<target>"` (see CLAUDE.md's OSPF design section)
# — the sidecar has NO network of its own, it fully shares the target
# container's netns, so zebra's kernel route installs apply directly to
# whatever process actually routes packets for that container. Confirmed
# before writing this: freeradius/genieacs-cwmp/genieacs-nbi/wireguard's
# own Dockerfile bases AND librenms/librenms:latest (official, no
# Dockerfile of our own) are ALL Alpine — `frr` installs cleanly via apk
# on all of them, so one shared sidecar image covers all 8 targets rather
# than baking FRR into 3 different Dockerfiles plus a runtime-install hack
# for the one official image.
FROM alpine:3.20

# wireguard-tools is only actually needed by handshake-watcher.sh (node
# role, `wg show wg0 latest-handshakes`) — included in the one shared
# image anyway rather than a second image, same "one image for all 8
# targets" reasoning as the rest of this Dockerfile.
RUN apk add --no-cache frr wireguard-tools

COPY entrypoint.sh /entrypoint.sh
COPY handshake-watcher.sh /handshake-watcher.sh
COPY frr.conf.node.template /frr.conf.node.template
COPY frr.conf.consumer.template /frr.conf.consumer.template
RUN chmod +x /entrypoint.sh /handshake-watcher.sh

ENTRYPOINT ["/entrypoint.sh"]
