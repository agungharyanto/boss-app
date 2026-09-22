# olt-sidecar (v0.23.3) — container BARU, pola librenms-dispatcher (bukan
# VM/consumer terpisah). Python dipilih karena `pexpect` adalah port paling
# langsung dari logika expect/Tcl yang SUDAH TERUJI sepanjang v0.23.1/
# v0.23.2 (olt_session.exp/olt_telnet_session.exp) — lihat
# docs/omci/sidecar-design.md §2 untuk alasan lengkap.
FROM python:3.12-alpine

# openssh-client: dipanggil sebagai subprocess oleh pexpect.spawn() untuk
# sesi SSH (HSGQ E04ID/G02ID). busybox-extras: Alpine's busybox default
# TIDAK menyertakan applet `telnet` penuh — busybox-extras menambahkannya
# (dipakai untuk sesi Telnet ZTE C300).
RUN apk add --no-cache openssh-client busybox-extras

WORKDIR /app

COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

COPY app ./app
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh && mkdir -p /var/log/olt-sidecar

ENTRYPOINT ["/entrypoint.sh"]
# waitress — pure-Python WSGI server, tanpa kompilasi C tambahan
# (konsisten filosofi container-lean BOSS-007), cukup untuk volume
# traffic internal sidecar ini (satu antrean per OLT, lihat §6 desain).
CMD ["waitress-serve", "--host=0.0.0.0", "--port=8080", "app.main:app"]
