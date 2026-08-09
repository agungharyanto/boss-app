FROM node:22-alpine

# GenieACS has no official Docker image (confirmed before building this —
# same due-diligence as freeradius/openvpn/wireguard/l2tp, all custom-built
# instead of trusting a possibly-stale community image). It's installed via
# npm, the project's own documented method — all 4 services (cwmp/nbi/fs/ui)
# ship in this single package; docker-compose.yml picks which one each
# container actually runs via its own `command:`.
RUN npm install -g genieacs
