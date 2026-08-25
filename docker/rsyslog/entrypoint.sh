#!/bin/sh
set -eu

# BOSS App — rsyslog receiver entrypoint (v0.8.4). Substitutes
# __LIBRENMS_API_TOKEN__ into the shared config template (never baked in
# at build time, never bind-mounted with a real secret in it — same
# runtime-substitution idiom as docker/frr/entrypoint.sh's own
# __PLACEHOLDER__ values) and hands off to rsyslogd in the foreground
# (`-n`), the standard way to keep a container's PID 1 tied to the actual
# daemon process rather than a backgrounded fork.

: "${LIBRENMS_API_TOKEN:?LIBRENMS_API_TOKEN must be set}"

sed "s/__LIBRENMS_API_TOKEN__/${LIBRENMS_API_TOKEN}/g" /rsyslog.conf.template > /etc/rsyslog.conf

exec rsyslogd -n
