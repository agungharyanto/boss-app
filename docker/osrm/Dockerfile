# v0.16.0 Langkah 11 — OSRM routing engine for "Cek Jalur ke ODP".
# Based on the official image; only adds curl (the base image has no
# HTTP client) so the entrypoint can fetch the Geofabrik extract on
# first boot. Same "distro image + our own thin entrypoint" pattern as
# docker/freeradius, docker/openvpn, docker/wireguard, docker/rsyslog.
FROM ghcr.io/project-osrm/osrm-backend:v5.27.1

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl ca-certificates \
    && rm -rf /var/lib/apt/lists/*

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

# osrm-routed listens on 5000 inside the container; never published to
# the host (BOSS-010) — boss-app reaches it at http://osrm:5000 over
# boss-network.
EXPOSE 5000

ENTRYPOINT ["/entrypoint.sh"]
