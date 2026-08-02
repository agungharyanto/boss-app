#!/usr/bin/env bash
# BOSS App — Sprint v0.1.0-foundation
# Scaffold Laravel 12 ke ./app menggunakan container composer sementara,
# supaya host tidak perlu install PHP/Composer sama sekali.
# Jalankan SEKALI saja (idempotent — skip kalau app/artisan sudah ada).

set -euo pipefail
cd "$(dirname "$0")/.."

if [ -f "app/artisan" ]; then
    echo ">> app/artisan sudah ada, Laravel sudah ter-scaffold. Skip."
    exit 0
fi

echo "=== [1/4] Scaffold project Laravel 12 ==="
docker run --rm -v "$(pwd)":/work -w /work composer:2 \
    create-project laravel/laravel app "^12.0" --prefer-dist --no-interaction

echo "=== [2/4] Install Fortify (auth) dan Spatie Permission (role) ==="
docker run --rm -v "$(pwd)/app":/app -w /app composer:2 \
    require laravel/fortify laravel/sanctum spatie/laravel-permission --no-interaction

echo "=== [3/4] Publish config Fortify, Sanctum, dan Permission ==="
docker run --rm -v "$(pwd)/app":/app -w /app php:8.4-cli \
    php artisan vendor:publish --provider="Laravel\\Fortify\\FortifyServiceProvider" --no-interaction || true
docker run --rm -v "$(pwd)/app":/app -w /app php:8.4-cli \
    php artisan vendor:publish --provider="Spatie\\Permission\\PermissionServiceProvider" --no-interaction || true

echo "=== [4/4] Terapkan stub BOSS App (API v1, HealthController, seeder role) ==="
cp -f stubs/laravel-app/routes/api.php app/routes/api.php
mkdir -p app/app/Http/Controllers/Api/V1
cp -f stubs/laravel-app/app/Http/Controllers/Api/V1/HealthController.php \
      app/app/Http/Controllers/Api/V1/HealthController.php
mkdir -p app/database/seeders
cp -f stubs/laravel-app/database/seeders/RolesAndPermissionsSeeder.php \
      app/database/seeders/RolesAndPermissionsSeeder.php

echo ">> Selesai. Langkah selanjutnya:"
echo "   1. Salin APP_KEY: docker compose run --rm boss-app php artisan key:generate --show"
echo "      lalu tempel ke .env (APP_KEY=...)"
echo "   2. docker compose up -d --build"
echo "   3. docker compose exec boss-app php artisan migrate"
echo "   4. docker compose exec boss-app php artisan db:seed --class=RolesAndPermissionsSeeder"
echo "   5. Tes: curl http://localhost/api/v1/health"
