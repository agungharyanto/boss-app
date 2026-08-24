#!/bin/sh
set -e

# Self-healing /tmp permission — v0.8.x "419 on Riwayat" investigation
# (see CLAUDE.md's own section on this). REAL root cause, confirmed
# directly from Agung's own browser DevTools Network tab: with /tmp not
# world-writable-sticky (1777), PHP silently discards the ENTIRE POST
# body ("Unable to create temporary file... POST data can't be
# buffered; all data discarded") for ANY request carrying a body —
# including every Livewire AJAX update, whose body carries the CSRF
# token — before Laravel ever sees it. This is indistinguishable from a
# genuine CSRF failure at the framework level (token/payload both read
# as null/empty), which is exactly what made the earlier investigation
# look CSRF-related when it never was.
#
# A one-time manual `chmod 1777 /tmp` was applied once already
# (2026-08-22) but had drifted back to 755 root:root on this same
# long-running container without ever being recreated in between — the
# exact resetting mechanism was investigated (no /tmp mount in `mount`/
# /proc/mounts, no active cron/crond process, container never
# recreated) but not conclusively identified. Rather than chase a cause
# that may never be pinned down with certainty, this is enforced BOTH
# at container start AND periodically for the container's entire
# lifetime — the same "re-apply defensively every cycle, don't rely on
# a one-time fix" posture already used elsewhere in this codebase for
# shared volumes that drifted for similarly unclear reasons
# (freeradius_nas_config, vpn_wg_data address fragments — see CLAUDE.md).
#
# Shared by all 4 containers built from docker/php (boss-app/boss-worker/
# boss-whatsapp-worker/boss-scheduler) — boss-whatsapp-worker/
# boss-scheduler invoke this by prefixing their own docker-compose.yml
# `entrypoint:` array with "/entrypoint.sh" rather than duplicating this
# logic inline; boss-app/boss-worker get it automatically via this
# image's own ENTRYPOINT (Dockerfile), since neither overrides
# `entrypoint:` in docker-compose.yml.
chmod 1777 /tmp

# Self-healing storage/logs/*.log ownership — found and fixed while
# investigating a real reported 500 on the Custom Range "Terapkan" button
# (Device/Traffic History modal, v0.8.3). storage/logs/ ITSELF is
# www-data:www-data 0775 (a fresh daily rotation file, per LOG_STACK=daily,
# is created by www-data fine) — the actual problem is any EXISTING log
# file that a root-run `docker compose exec boss-app ...` session (tinker,
# a one-off artisan command — exec defaults to root, same as every prior
# incident of this bug class in this codebase) happens to write a line
# into: that single write flips the file's owner to root:root, and every
# SUBSEQUENT write from a real www-data php-fpm request then fails
# silently. Confirmed for real: with the day's log file root-owned, an
# exception genuinely thrown inside a component's own catch(Throwable)
# block (which would normally log a WARNING and degrade gracefully) instead
# surfaced as a raw, undetailed 500 — the catch block's own Log::warning()
# call failed trying to open the unwritable file, escalating a handled,
# harmless failure into an unhandled one. Toggling the file back to
# www-data:www-data made the exact same request succeed gracefully again,
# proving this deterministically, not by guessing (see CLAUDE.md's own
# section on this incident for the full account). Same self-healing
# posture as the /tmp loop above and the same bug class already hit
# several times for shared Docker volumes (freeradius_nas_config,
# vpn_wg_data) — re-applied defensively every cycle rather than trusting a
# one-time fix, since the exact moment a root session writes to today's
# log file can't be predicted or fully prevented.
chown www-data:www-data storage/logs/*.log 2>/dev/null || true

(
    while true; do
        chmod 1777 /tmp
        chown www-data:www-data storage/logs/*.log 2>/dev/null || true
        sleep 30
    done
) &

exec "$@"
