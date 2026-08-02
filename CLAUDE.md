# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

BOSS App (Broadband Operations Support System) — a modular operational platform for an ISP. Laravel is
the central hub for customer data and command/control; GenieACS, MikroTik, FreeRADIUS, LibreNMS, and
WhatsApp (via Baileys) are integrated as external services in later sprints, not built from scratch here.

Repo root vs Laravel app: the Laravel project lives in `app/` (this is *not* the repo root). Everything
outside `app/` — `docker/`, `scripts/`, `docs/`, root `.env` — is deployment/infra tooling that wraps the
Laravel app in Docker. Root `.env.example` configures Docker Compose (Postgres/Redis container creds,
`APP_URL`); `app/.env.example` is the standard Laravel env file inside the container.

## Tech stack

| Layer       | Choice                                |
|-------------|----------------------------------------|
| Backend     | Laravel 12 / PHP 8.4                   |
| Frontend    | Blade + Livewire + Alpine + Tailwind   |
| Database    | PostgreSQL                             |
| Cache/Queue | Redis                                  |
| Web server  | Nginx                                  |
| Deployment  | Docker Compose                        |
| WhatsApp    | Baileys (Node.js) — starts v0.4.0      |
| RADIUS      | FreeRADIUS — starts v0.6.0             |
| ACS         | GenieACS — starts v0.7.0               |
| Monitoring  | LibreNMS — starts v0.8.0               |

## Current status

Active sprint: **v0.1.0-foundation**, on branch `v0.1.0-foundation` (pushed to GitHub). This sprint is
done and verified: `/api/v1/health` returns healthy, migrations run cleanly, base roles are seeded
(`RolesAndPermissionsSeeder`), and the branch has been pushed upstream. Per BOSS-002, do not start
v0.2.0 work until this sprint has gone through merge → `develop` → `main` → tag `v0.1.0` (see
"Bagian C" in `docs/DEPLOYMENT.md`) — if that hasn't happened yet, treat v0.1.0-foundation as still open
and keep changes scoped to it.

## Sprint roadmap (`docs/ROADMAP.md`) — locked order, do not skip or reorder

| Version | Name                     | Contents                                                              |
|---------|--------------------------|------------------------------------------------------------------------|
| v0.1.0  | Foundation               | Repo, Docker, Laravel, PostgreSQL, Redis, Nginx, login, roles, UFW/Fail2ban, backup |
| v0.2.0  | Customer CRM             | Customer data, family contacts, authorized contact, customer timeline |
| v0.3.0  | Registration & Referral  | Multi-channel registration, freelance sales, referral, pending commission |
| v0.4.0  | Communication (Baileys)  | WhatsApp gateway, group notifications, area routing, OTP              |
| v0.5.0  | Installation             | Technician work orders, MAC/serial scan, ODP/PON, install photos      |
| v0.6.0  | FreeRADIUS               | PPPoE accounts via RADIUS, bandwidth profiles, accounting, CoA/disconnect |
| v0.7.0  | GenieACS                 | ONT binding, SSID/password, RX power, reboot, provisioning            |
| v0.8.0  | LibreNMS & Graph         | Device monitoring, graphs, alerts, network dashboard                  |
| v0.9.0  | Commission               | Eligibility, approval, payment, clawback                               |
| v0.10.0 | Outage Engine            | ONT down detection, area correlation, incident, maintenance            |

Each version must be fully complete (full Definition-of-Done, see below) before moving to the next — no
jumping ahead.

## Sprint-based development — read this before doing anything

This repo is built strictly one versioned sprint at a time per `docs/ROADMAP.md` above, and the following
14 rules from `docs/RULES.md` govern how *all* work must be done:

- **BOSS-001 — GitHub as source of truth**: every production-relevant change must be in GitHub: source
  code, Docker Compose, Nginx config, PostgreSQL structure, Redis config, FreeRADIUS, GenieACS, LibreNMS,
  Baileys, firewall, backup scripts, monitoring, migrations, install docs, `.env.example`, deploy/update
  scripts. No production config may live only on the server. The only exception is secrets (passwords,
  tokens, private keys, DB credentials, RADIUS secrets, WhatsApp session, encryption keys) — those go in
  `.env` only, with their shape documented in `.env.example`.
- **BOSS-002 — One version, one chat**: don't move to the next version before: feature complete, testing
  passed, `git status` clean, commit succeeded, push succeeded, tag succeeded.
- **BOSS-003 — Stay in scope**: each sprint's scope is locked. New requirements go into the backlog for a
  future version, never inserted into the active sprint. Check `README.md` for "Sprint aktif" before
  assuming you can add functionality from a later roadmap item (e.g. don't add RADIUS/GenieACS code
  before v0.6.0/v0.7.0).
- **BOSS-004 — Sprint-based execution**: each sprint is delivered as: Goal, Files created/changed,
  Terminal commands, Full script, How to test, Expected result, Commit, Push, Tag. Theory/explanation is
  only given when needed for a decision or troubleshooting.
- **BOSS-005 — Security-first**: layered defense — Internet → Cloudflare (optional) → UFW → Fail2ban →
  Nginx → TLS → Laravel Auth (Fortify) → Role & Permission (Spatie) → REST API Token (Sanctum) → Rate
  Limiting → Audit Log.
- **BOSS-006 — API-first**: every major module gets a service + REST API (`/api/v1/...`), even if the
  initial UI is Blade/Livewire. Business logic lives in Service/Action classes, not Controllers.
  Validation goes through Form Requests. Response format must stay consistent:
  `{"success": bool, "message": string, "data": ..., "meta": {...}}` (see
  `app/app/Http/Controllers/Api/V1/HealthController.php` for the pattern).
- **BOSS-007 — Container-first**: all supporting services run in containers, added incrementally per
  version (not all at once) so troubleshooting stays manageable.
- **BOSS-008 — Data graph and monitoring**: LibreNMS stays the monitoring engine (never rebuilt in-house).
  BOSS App reads data via the LibreNMS API/adapter, never by reading RRD files directly.
- **BOSS-009 — Logically separated databases**: `boss_db` (PostgreSQL, this app), `radius_db`
  (FreeRADIUS), `genieacs_db` (MongoDB), `librenms_db` (MariaDB). Integration happens via API/service,
  never cross-database joins.
- **BOSS-010 — Public server hardening**: initial public ports are 22 (SSH, restricted), 80, 443. Never
  expose to the public: 5432 (PostgreSQL), 6379 (Redis), 27017 (MongoDB), 3306 (MariaDB), 1812/1813
  (RADIUS — except from NAS IPs), 7547 (GenieACS CWMP — special policy).
- **BOSS-011 — Reproducible configuration**: a new server must be rebuildable with only `git clone` →
  `cp .env.example .env` → `docker compose up -d` plus the provisioning scripts in the repo.
- **BOSS-012 — Backup and rollback**: every production version requires: database backup, backup of
  important volumes, safe migrations, a rollback procedure, a Git tag, and a changelog.
- **BOSS-013 — Naming and versioning**: repo is `boss-app`. Branches: `main`, `develop`,
  `v0.1.0-foundation`, `v0.2.0-customer-crm`, etc. Commits: `vX.Y.0 <description>` (e.g. `v0.1.0
  initialize BOSS App foundation`). Tags: `v0.1.0`, `v0.2.0`, `v1.0.0`.
- **BOSS-014 — WhatsApp gateway**: all WhatsApp integration uses **Baileys** as the gateway (not WAHA,
  Wablas, MPWA, or WhatsApp Cloud API). Centralized in a single Node.js service, accessed by BOSS App via
  internal REST API. Implemented starting v0.4.0.

**Definition of Done** for every sprint (`docs/RULES.md`):
- [ ] All files pushed to GitHub
- [ ] No secrets in the repository
- [ ] Docker containers running healthy
- [ ] API tests passing
- [ ] Migrations successful
- [ ] Permissions tested
- [ ] Firewall tested
- [ ] Backup tested
- [ ] Documentation updated
- [ ] `git status` clean
- [ ] Commit and push successful
- [ ] Version tag created

## Commands

All Laravel/composer/artisan commands run **inside the `boss-app` container**, not on the host (the host
has no PHP toolchain by design — see `scripts/02-init-laravel.sh`).

```bash
# Bring the stack up (nginx, app, worker, scheduler, postgres, redis)
docker compose up -d --build
docker compose ps                     # all should be Up/healthy

# Migrations / seeding
docker compose exec boss-app php artisan migrate
docker compose exec boss-app php artisan db:seed --class=RolesAndPermissionsSeeder

# Tests (PHPUnit, sqlite in-memory per phpunit.xml)
docker compose exec boss-app php artisan test
docker compose exec boss-app php artisan test --filter=ExampleTest
docker compose exec boss-app php artisan test tests/Feature/SomeTest.php

# Lint / format (Laravel Pint)
docker compose exec boss-app ./vendor/bin/pint
docker compose exec boss-app ./vendor/bin/pint --test   # check only, no changes

# Health check (validates app + DB + Redis connectivity)
curl http://localhost/api/v1/health

# Backup / deploy / rollback (see docs/DEPLOYMENT.md for full flow)
./scripts/backup.sh
./scripts/deploy.sh
./scripts/rollback.sh <tag>
```

First-time server setup order: `./scripts/01-setup-server.sh` (Docker/UFW/Fail2ban, host-side, run once
per server) → `cp .env.example .env` and fill in secrets → `./scripts/02-init-laravel.sh` (scaffolds
Laravel into `app/`, idempotent — skips if `app/artisan` already exists) → `docker compose up -d`.

## Architecture

**Containers** (`docker-compose.yml`): `boss-nginx` (reverse proxy, port 80/443) → `boss-app` (PHP-FPM,
serves requests) + `boss-worker` (`queue:work`) + `boss-scheduler` (loops `schedule:run` every 60s) →
`boss-postgresql` + `boss-redis` (no host ports exposed, per BOSS-010). All share the `boss-network`
bridge network; `boss-app`'s `app/` directory is bind-mounted read-write, nginx mounts it read-only.

**Auth/authz stack** (RULE BOSS-005 layering): Laravel Fortify handles authentication (see
`app/app/Providers/FortifyServiceProvider.php` and `app/app/Actions/Fortify/*` for the customized
registration/password actions), Spatie `laravel-permission` (`HasRoles` trait on `User`) handles
role/permission checks, Sanctum issues API tokens (`HasApiTokens` trait on `User`). Base roles are
seeded by `database/seeders/RolesAndPermissionsSeeder.php` — `super_admin`, `noc`, `customer_service`,
`teknisi`, `billing`, `sales_internal`, `sales_freelance`, `finance` — with permissions added
incrementally as each module sprint lands.

**API structure**: versioned under `routes/api.php` → `Route::prefix('v1')`. Controllers for a version
live under `App\Http\Controllers\Api\V1\...`. Authenticated routes use `auth:sanctum` middleware.
`GET /api/v1/health` is unauthenticated and checks DB (`DB::connection()->getPdo()`) and Redis
(`Redis::ping()`) connectivity — used both for manual smoke-testing and would be the natural target for
container healthchecks.

**Stubs pattern**: `stubs/laravel-app/` holds files that get copied into a freshly `composer
create-project`-scaffolded `app/` by `scripts/02-init-laravel.sh` (e.g. the versioned `routes/api.php`,
`HealthController`, `RolesAndPermissionsSeeder`). If you're changing baseline scaffolding behavior for
fresh installs, update the stub, not just the live `app/` copy — otherwise a server rebuilt from scratch
via `git clone` + the setup scripts will regenerate the old version.
