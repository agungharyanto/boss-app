#!/usr/bin/env bash
# BOSS App — Sprint v0.1.0-foundation
# Setup server Ubuntu 24.04: Docker, Git, UFW, Fail2ban
# Jalankan SEKALI per server, sebagai user dengan sudo.

set -euo pipefail

echo "=== [1/6] Update system ==="
sudo apt-get update -y
sudo apt-get upgrade -y

echo "=== [2/6] Install dependencies dasar ==="
sudo apt-get install -y ca-certificates curl gnupg git ufw fail2ban

echo "=== [3/6] Install Docker Engine + Compose plugin ==="
if ! command -v docker >/dev/null 2>&1; then
    sudo install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
    sudo chmod a+r /etc/apt/keyrings/docker.gpg

    echo \
      "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu \
      $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | \
      sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

    sudo apt-get update -y
    sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin

    sudo usermod -aG docker "$USER"
    echo ">> User $USER ditambahkan ke group docker. Logout/login ulang agar berlaku tanpa sudo."
else
    echo ">> Docker sudah terpasang, skip."
fi

echo "=== [4/6] Konfigurasi UFW (RULE BOSS-010) ==="
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow 22/tcp comment 'SSH'
sudo ufw allow 80/tcp comment 'HTTP'
sudo ufw allow 443/tcp comment 'HTTPS'
# Port database/redis/radius/genieacs SENGAJA TIDAK dibuka di sini.
# Dibuka nanti secara spesifik per sprint (RADIUS hanya dari IP NAS, dst).
sudo ufw --force enable
sudo ufw status verbose

echo "=== [5/6] Konfigurasi Fail2ban untuk SSH ==="
sudo tee /etc/fail2ban/jail.local > /dev/null <<'EOF'
[sshd]
enabled = true
port    = ssh
filter  = sshd
logpath = /var/log/auth.log
maxretry = 5
bantime  = 3600
findtime = 600
EOF
sudo systemctl enable fail2ban
sudo systemctl restart fail2ban

echo "=== [6/6] Selesai ==="
echo "Jika ini instalasi Docker pertama kali, logout lalu login ulang sebelum lanjut ke 02-init-laravel.sh"
