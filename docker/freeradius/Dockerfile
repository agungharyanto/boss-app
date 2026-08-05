# BOSS App — FreeRADIUS container (v0.6.1: core + NAS management).
#
# Base image ships with an intentionally empty/rejecting config — clients.conf
# only trusts 127.0.0.1 and rlm_sql defaults to the "null" driver. We only
# need to point rlm_sql at radius_db (see mods-available/sql) to get
# radcheck/radreply/radacct working; clients.conf and sites-available/default
# are left at their stock defaults on purpose:
#   - the "default" site already runs "-sql" in both authorize{} and
#     accounting{} out of the box (the leading "-" means a missing/failed
#     module doesn't hard-reject the request), so no site file edit needed.
#   - status_server = yes is FreeRADIUS's own default in radiusd.conf, so
#     Status-Server health-check packets are answered without extra config.
#   - real NAS clients (dynamic per-NAS virtual server + port allocator) are
#     v0.6.5 scope — v0.6.1 only stands up the container + schema + rlm_sql
#     wiring, nothing sends it real Access-Request traffic yet.
FROM freeradius/freeradius-server:3.2.10-alpine

# rlm_sql_postgresql.so is compiled in, but its runtime dependency
# libpq.so.5 isn't part of the base alpine image — without it the sql
# module fails to instantiate at all ("Error loading shared library
# libpq.so.5").
RUN apk add --no-cache libpq

# v0.6.5 — coa-worker.sh reads/writes small JSON request/result files
# (CoaService's queue protocol); jq keeps that robust against secrets/
# usernames containing quotes or other characters a hand-rolled sed/awk
# JSON parser would mangle. Own layer, added after libpq (not merged into
# that line) — this project's own Dockerfile-layering lesson from
# v0.6.1-v0.6.3 (a new package sharing a layer with something upstream of
# a slow step invalidates that step's cache too); libpq isn't slow here,
# but keeping the habit consistent is cheap.
RUN apk add --no-cache jq

COPY mods-available/sql /etc/raddb/mods-available/sql
RUN ln -sf ../mods-available/sql /etc/raddb/mods-enabled/sql

# v0.6.5 — dynamic per-NAS virtual server + CoA/Disconnect. Replaces the
# base image's own /docker-entrypoint.sh (a plain `exec radiusd -f`) with a
# supervisor that can restart radiusd when NAS config changes (SIGHUP alone
# does NOT open new listen sockets — verified for real, see entrypoint.sh's
# own comment) and dispatch queued CoA requests.
COPY entrypoint.sh /entrypoint.sh
COPY coa-worker.sh /coa-worker.sh
RUN chmod +x /entrypoint.sh /coa-worker.sh

ENTRYPOINT ["/entrypoint.sh"]
