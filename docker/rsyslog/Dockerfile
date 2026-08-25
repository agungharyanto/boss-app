# BOSS App — rsyslog receiver (v0.8.4). Terima syslog UDP dari NAS
# MikroTik (native RouterOS `/system logging action add type=remote`,
# tidak butuh relay tambahan di sisi router) dan teruskan ke LibreNMS
# via `POST /api/v0/syslogsink` (omhttp), BUKAN via omprog exec ke
# syslog.php — omprog cuma bisa exec program di container/namespace yang
# sama, sementara syslog.php butuh bootstrap penuh framework LibreNMS
# (includes/init.php) dan hidup di container `librenms` yang official
# image-nya sendiri tidak menyertakan rsyslog. Sidecar terpisah + omhttp
# menghindari harus memodifikasi image resmi `librenms/librenms:latest`
# sama sekali — pola sama seperti docker-stats-proxy (kontainer kecil,
# single-purpose).
#
# alpine:3.20 + paket distro asli (rsyslog + rsyslog-http, yang
# menyediakan omhttp.so) — konsisten dengan setiap image base lain di
# proyek ini (freeradius, openvpn, wireguard, l2tp, frr).
FROM alpine:3.20

RUN apk add --no-cache rsyslog rsyslog-http

COPY entrypoint.sh /entrypoint.sh
COPY rsyslog.conf.template /rsyslog.conf.template
RUN chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
