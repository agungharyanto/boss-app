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

COPY mods-available/sql /etc/raddb/mods-available/sql
RUN ln -sf ../mods-available/sql /etc/raddb/mods-enabled/sql
