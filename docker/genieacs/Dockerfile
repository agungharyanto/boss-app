FROM node:22-alpine

# GenieACS has no official Docker image (confirmed before building this —
# same due-diligence as freeradius/openvpn/wireguard/l2tp, all custom-built
# instead of trusting a possibly-stale community image). It's installed via
# npm, the project's own documented method — all 4 services (cwmp/nbi/fs/ui)
# ship in this single package; docker-compose.yml picks which one each
# container actually runs via its own `command:`.
RUN npm install -g genieacs

# v0.7.3 — iproute2 for entrypoint.sh's `ip route replace` (Connection
# Request routing toward a NAS's TR-069 management subnet via a VPN pool
# node). Alpine's base image doesn't ship it — same class of "missing
# package found only when actually running it" gap as ext-sockets/
# wireguard-tools in earlier sprints (see CLAUDE.md).
RUN apk add --no-cache iproute2

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
