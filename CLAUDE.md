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

Other docs: `docs/API.md` (endpoint reference — update it whenever `routes/api.php` changes),
`CHANGELOG.md` (one section per tagged version, written when the sprint closes).

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

v0.1.0-foundation is merged and tagged (`v0.1.0`, on `main`/`develop`). v0.2.0-customer-crm was fully
built, tested, merged to `develop`/`main`, and tagged `v0.2.0` — **then the `v0.2.0-customer-crm` branch
kept receiving commits after that tag** (the row-level multi-tenancy foundation below, added mid-sprint
at the user's request before answering some Customer CRM design follow-ups). So: tag `v0.2.0` and
`main`/`develop` currently point at the *pre-tenancy* state; the `v0.2.0-customer-crm` branch is ahead of
that with tenant scaffolding not yet re-merged/re-tagged. Don't assume `git tag v0.2.0` is the full
current state of this sprint — check `v0.2.0-customer-crm` directly.

Delivered in v0.2.0 so far: `customers`, `customer_contacts`, `customer_timeline_entries`, and `tenants`
tables; `CustomerStatus`/`ContactAccessLevel` enums; models with auto-logging Observers; row-level
multi-tenancy (see dedicated section below); granular Spatie permissions (`customers.view`,
`customers.manage`, `customer_contacts.manage`, `customer_timeline.view`); Policies; Form Requests; an
Actions layer; API Resources; `Api/V1` controllers wired into `routes/api.php`; feature tests; and a
Livewire UI (`CustomerIndex`/`CustomerShow`) wired into `routes/web.php`. Do not start v0.3.0 work until
this sprint's Definition of Done is complete and it has gone through merge → `develop` → `main` → tag
`v0.2.0` again (BOSS-002).

**Three latent v0.1.0 infrastructure bugs were found and fixed while building this sprint** (not new
v0.2.0 scope — pre-existing gaps that silently broke security-critical or test-correctness paths since
the initial scaffold):
- Sanctum's `personal_access_tokens` migration was never published, so **no API token auth ever
  actually worked** despite `auth:sanctum` already gating `/api/v1/me` in v0.1.0. Fixed by publishing
  `--tag=sanctum-migrations`.
- Fortify had no `LoginViewResponse` bound, so **`/login` 500'd for every request** — there was no
  working login page at all. Fixed with a minimal `resources/views/auth/login.blade.php` bound via
  `Fortify::loginView()` in `FortifyServiceProvider`. Fortify's `home` redirect target was also
  updated from the never-created `/home` to `/customers`.
- `phpunit.xml`'s `<env>` overrides (meant to isolate tests on in-memory sqlite) were silently losing to
  the container's real process env (`DB_CONNECTION=pgsql` etc from docker-compose's `env_file`) because
  that real value lands in `$_SERVER`, which `force="true"` doesn't clear — only `$_ENV`/`putenv()`. **All
  feature tests had been quietly running against the real dev Postgres database**, not the isolated
  sqlite config the file documents. Fixed with `tests/bootstrap.php` (referenced via phpunit.xml's
  `bootstrap` attribute) that explicitly unsets the affected `$_SERVER` keys before Laravel boots.

If you're debugging "why doesn't auth work" or "why do tests leave junk data in the dev DB" in older
commits, these are why — check all three are still in place before assuming the bug is elsewhere.

**A fourth infrastructure bug (same root cause class) was found while building v0.3.2**: root `.env`
(the file Docker Compose loads as `env_file` for `boss-app`/`boss-worker`/`boss-scheduler` — not
`app/.env`) had `APP_ENV=production` even though `app/.env` said `local`, and the container's real
process env wins over `.env`, same precedence issue as the phpunit bug above. Consequence: this dev/sprint
VM (`45.123.142.242`, the single server `docs/DEPLOYMENT.md` describes — there is no separate staging vs
production deployment yet) resolved `app()->environment()` as `production` the entire time, silently
disabling every `app()->environment('local')`-gated code path (e.g. dev-only seeders) and forcing a
confirmation prompt on every `artisan migrate`. Fixed by changing this server's root `.env` (gitignored,
not `.env.example`) to `APP_ENV=local`, then recreating `boss-app`/`boss-worker`/`boss-scheduler` so the
new `env_file` value is actually re-read (editing `.env` alone does not affect already-running
containers). **`root .env.example`'s `APP_ENV=production` default is intentionally correct and must NOT
be changed** — it's the documented template value for a genuine future production deploy. **Reminder for
whoever runs the actual go-live for this server**: flip this same server's root `.env` back to
`APP_ENV=production` at that point — this VM is planned to become production in place, not be replaced
by a separate prod server, so nothing will do this flip automatically.

## Sprint roadmap — locked order, do not skip or reorder

**`docs/ROADMAP.md` is the single source of truth for the sprint list, cluster grouping, and status** —
don't duplicate it here (a stale copy previously lived in this file and drifted out of sync; don't
reintroduce that). Each version must be fully complete (full Definition-of-Done, see below) before moving
to the next — no jumping ahead. `docs/ROADMAP.md` also carries forward cross-sprint dependency notes (e.g.
what a later sprint's migrations/contracts must include because an earlier sprint deferred them) — check
it, not just this file, before starting a new sprint.

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

# Dev-only: one demo tenant + one user per role, all password "password",
# for manually logging into the Livewire UI as e.g. customer_service@boss.local
docker compose exec boss-app php artisan db:seed --class=DemoUsersSeeder

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

## Multi-tenancy (row-level, added mid-v0.2.0)

BOSS App will eventually be rented out as SaaS to multiple ISP businesses. Chosen strategy: **row-level
multi-tenancy** — one shared database, a `tenant_id` column on every tenant-owned table, isolation
enforced automatically via an Eloquent global scope (not separate databases/schemas per tenant).

- `tenants` table (`app/database/migrations/..._create_tenants_table.php`) is intentionally minimal for
  now: `id`, `uuid`, `name`, `slug` (unique), `is_active`. Branding/settings/licensed-modules columns are
  deferred to their own future sprint — don't add them speculatively.
- `App\Models\Concerns\BelongsToTenant` trait: apply it to any model that belongs to a tenant. It (1)
  registers `App\Models\Scopes\TenantScope` as a global scope, which filters every query by
  `Auth::user()->tenant_id` whenever a user is authenticated, and (2) auto-fills `tenant_id` on
  `creating` from the authenticated user if not already set. Currently applied to `Customer`,
  `CustomerContact`, `CustomerTimelineEntry`. **`User` itself deliberately does NOT use this trait** —
  scoping the user table by "the current user's tenant" would be circular during login lookups; `User`
  just has a plain `tenant_id` column + `tenant()` relation instead.
- **`super_admin` is tenant-scoped, not a cross-tenant platform role** (explicit decision — don't
  reintroduce a cross-tenant bypass without asking first). Every user, including `super_admin`, has a
  required (`NOT NULL`) `tenant_id`. A genuine cross-tenant "BOSS App platform operator" concept, if
  ever needed, would be a new role/mechanism in its own sprint, not a reuse of `super_admin`.
  Consequence: Spatie roles/permissions stay global entities (e.g. one `customer_service` role row
  shared across all tenants), but the *data* a user with that role can act on is still restricted to
  their own tenant by `TenantScope` — so authorization is effectively per-tenant in practice even though
  role definitions aren't duplicated per tenant.
- Because Postgres can't reorder columns via `ALTER TABLE` (no `AFTER` support), `tenant_id` was added by
  directly editing the not-yet-deployed-anywhere `customers`/`customer_contacts`/`customer_timeline_entries`
  migrations (to put it right after `id`) rather than bolting on a separate alter migration. `users` got
  a proper alter-table migration instead, since its base migration is an older, more "sealed" v0.1.0 file.
- Factories: `CustomerFactory`/`UserFactory` default `tenant_id` to a fresh `Tenant::factory()`.
  `CustomerContactFactory`/`CustomerTimelineEntryFactory` instead derive `tenant_id` from their
  `customer_id`'s actual tenant (`Customer::withoutGlobalScopes()->find(...)->tenant_id`) — never give
  them an independent random tenant, or you'll create inconsistent cross-tenant test fixtures.
- Tests creating cross-tenant fixtures must bypass the scope explicitly (`Model::withoutGlobalScopes()`)
  when arranging data for a tenant the acting test user doesn't belong to — see
  `tests/Feature/Tenancy/TenantIsolationTest.php` for the pattern (also the reference example for how to
  prove new tenant-scoped models are actually isolated: plain Eloquent query, API index, and API show/update
  via route-model-binding all naturally 404/exclude another tenant's row with zero manual `where()` calls).

## Tax engine integration contract (v0.3.4)

v0.3.3 (Regulatory Tax Engine) built the tax calculation/ledger foundation deliberately **without** any
automatic hook into invoicing — invoicing itself doesn't exist yet (that's v0.3.4, Invoicing Core). When
`InvoiceService` is built in v0.3.4, every invoice generation **must** call the tax engine in this exact
sequence — this is a stable, already-tested contract, not a suggestion:

```php
use App\Services\Tax\TaxCalculationService;

// 1. Calculate the breakdown for this invoice's base amount.
$breakdown = $taxCalculationService->calculateForAmount(
    $customer->reseller,   // ?Reseller — null for a direct ISP customer
    $invoiceBaseAmount,    // float
    $invoice->created_at,  // ?Carbon — which tax_components/policies are effective as of this date
);

// 2. Persist it — one reseller_tax_ledger row per TaxBreakdown component.
$ledgerRows = $taxCalculationService->writeLedgerEntry(
    $breakdown,
    $customer->reseller,
    Invoice::class,        // reference_type — reseller_tax_ledger.reference_type is a plain
    $invoice->id,           // reference_id  — string/unsignedBigInteger, no FK constraint, no migration needed
    $invoice->created_at,   // transaction_date
    'system',                // source — 'system' for real invoices, 'seeded' is testing-only
);

// 3. $breakdown->grandTotal (base + tax) is what the customer is actually billed.
```

Why this shape, so it isn't "fixed" by accident in a later sprint:
- `calculateForAmount` resolves active `tax_components`/`reseller_tax_policies` via the caller's
  `Auth::user()->tenant_id` (`TenantScope`, same convention as every other tenant-scoped query in this
  codebase) — so this must run inside an authenticated request/job context for the correct tenant, or a
  queued job must `Auth::login()` first.
- `writeLedgerEntry` deliberately does **not** depend on `Auth` — it derives `tenant_id` from `$reseller`
  or from the breakdown's own `tax_component_id` — so it stays correct even if invoice generation runs in
  a queued job with no authenticated request (e.g. a monthly billing cron).
- Policy↔component resolution is keyed by `tax_components.code` (the stable identifier), not the specific
  effective-dated row id — a reseller's burden/split agreement survives a tax rate change untouched. See
  `App\Services\Tax\ResellerTaxPolicyService::getActivePolicies()`.
- `reseller_tax_ledger.reference_type`/`reference_id` are already generic/polymorphic with **no** FK
  constraint (see the migration) specifically so v0.3.4 needs zero schema changes to start populating
  them with `App\Models\Invoice::class`/`$invoice->id`.

**This contract has been fulfilled** — `App\Services\InvoiceService::generateForPeriod()` (v0.3.4) is the
first real caller, following the exact sequence above.

## Cross-database date comparison gotcha (found building v0.3.4, affects v0.3.3 code too)

A `'date'`-cast column (e.g. `tax_components.effective_from`, `invoices.period_start`) can be **stored**
with a time suffix depending on driver — SQLite (what the test suite runs on, per `phpunit.xml`) keeps
whatever Eloquent's date mutator serialized, which turned out to be `"2026-08-01 00:00:00"`, not a bare
`"2026-08-01"`; Postgres's native `DATE` type strips time regardless of what's sent. A plain
`->where('effective_from', '<=', $date->toDateString())` does a **raw string comparison** against
whatever's actually stored — under SQLite this silently gives the wrong answer exactly when the compared
date matches the stored date's calendar day (e.g. a subscription whose `started_at` equals a tax
component's `effective_from` down to the same day resolved **zero** tax, because
`"2026-08-01 00:00:00" <= "2026-08-01"` is false as a string comparison, the trailing suffix making it
lexicographically "greater"). It went undetected through all of v0.3.3's own tests because none of them
happened to compare against an exactly-matching boundary date — v0.3.4's invoice generation flow does,
constantly (a subscription's first period naturally starts on the same day a tax rate went into effect).

**Always use `->whereDate($column, $operator, $value)` instead of a plain `->where(...)` for any
comparison between a `'date'`-cast column and a `toDateString()`/plain-date value** — `whereDate()` wraps
both sides with the driver's own date-extraction SQL, making the comparison correct regardless of stored
format. Fixed in `TaxCalculationService`, `ResellerTaxPolicyService`, `RemittanceSummaryService`,
`InvoiceService`, `MarkOverdueInvoices`, `TaxLedgerController`, `RemittanceSummaryController` while
building v0.3.4 — check any *new* date-range query you write against this same gotcha, in this codebase
or elsewhere with the same driver-precision mismatch between SQLite tests and Postgres dev/prod.

## Payment gateway (Xendit, v0.3.5) — sandbox posture and integration notes

**Sandbox only** — `config('services.xendit.is_production')` (env `XENDIT_IS_PRODUCTION`) must stay `false`
on this server until an explicit, deliberate go-live decision for payments specifically (separate from the
server's own `APP_ENV` go-live, which is a different concern — see the `APP_ENV` bug entry above).
`App\Services\Payment\XenditGatewayService`'s constructor **refuses to run at all**
(`RuntimeException`) if `is_production=true` while Laravel's own `app()->environment()` isn't
`'production'` — a deliberate guard against exactly the "sandbox testing but production key/flag
confused" scenario BOSS-005 worries about. `is_production` itself stays a root `.env`/`config('services.xendit.*')`
value (it's an environment-safety flag, not a per-request credential) — but the actual secret/token below
is **not** read from there anymore as of Fase H.

**Credential source changed mid-sprint (Fase H, before first commit): `.env` → encrypted DB row, not a
later "fix"** — `XENDIT_SECRET_KEY`/`XENDIT_CALLBACK_TOKEN` were the runtime source for Fase A-G, but this
was amended (confirmed by Agung) to move to an admin-editable settings row instead, so channels/credentials
can be changed without a redeploy. `App\Services\Payment\PaymentGatewaySettingsService` is now the **only**
allowed reader/writer of `payment_gateway_settings` (a platform-level singleton row, id=1, encrypted
`xendit_secret_key`/`xendit_webhook_token` columns) — `XenditGatewayService` and
`PaymentService::verifySignature()` both depend on it, never on `config('services.xendit.secret_key'/
'callback_token')` directly anymore. `config('services.xendit.*')`'s `secret_key`/`callback_token` keys
still exist (still populated from root `.env`) but are read exactly once more, by the manual command
`php artisan payment-gateway:import-env` — a one-time transition helper, never auto-run from a
migration/seeder. Don't reintroduce a direct `config()`/`env()` read of these two values in request-time
code — that would silently bypass the admin UI's ability to rotate credentials without a deploy.

**`invoice_number` (not the numeric `id`) is Xendit's `external_id`** — `PaymentService::createPaymentFor()`
and `::handleWebhook()` both key off it. Any future code that creates a Xendit payment object or parses a
webhook must do the same; matching by numeric `Invoice::id` would still work by coincidence in dev but
breaks the intended human-readable reconciliation trail this format exists for.

**Two legitimate callers of `InvoiceService::markPaid()` now exist, not one** — confirmed explicitly by
Agung when this tension was flagged during v0.3.5: the pre-existing manual `PATCH
/api/v1/invoices/{invoice}/paid` endpoint (v0.3.4, no payment verification at all — an admin can mark any
non-terminal invoice paid by hand) was deliberately **kept**, alongside the new fully-verified
`PaymentService::handleWebhook()` path (signature + idempotency + exact amount match, v0.3.5). Don't
"clean this up" by removing the manual endpoint without asking first — if a future sprint wants stricter
audit trail parity for manually-recorded payments (e.g. requiring a `payments` row for those too, not just
a bare status flip), that's new scope requiring its own confirmation, not an assumed cleanup.

**Webhook signature verification is a static shared-token comparison, not HMAC** — Xendit's actual
callback verification mechanism is comparing the `x-callback-token` request header against the token shown
in the Xendit dashboard (`hash_equals()`, timing-safe), not a computed signature over the payload. Don't
"upgrade" this to HMAC-style verification without checking Xendit's docs first — it would silently reject
every real webhook.

**`payments`/`payment_webhook_logs` schema is deliberately generic** — `payments.channel_type` is a plain
`varchar`, not one table per channel. It was originally also a fixed 3-case backed enum
(`App\Enums\PaymentChannelType`: `virtual_account`/`qris`/`invoice`) for Fase A-G, but Fase H (same sprint,
before first commit) replaced that with a dynamic admin-managed catalog table `payment_gateway_channels`
(`code`/`label`/`category`/`enabled`, no hard FK from `payments.channel_type` — validated in
`PaymentService` instead) so channels can be turned on/off from the UI without a migration/redeploy. The
old enum is deleted; `channel_type` now stores a `payment_gateway_channels.code` value (e.g. `BRI_VA`,
`QRIS`, `XENDIT_INVOICE`). **Scope boundary to remember**: the catalog also lists `ewallet`/
`retail_outlet`/`credit_card` category channels (OVO, DANA, Alfamart, credit card, etc. — for the
settings-page checklist, matching the MikRadius-style reference UI) but `PaymentService::createPaymentFor()`
only actually calls Xendit for `bank_transfer_va`/`qris`/`invoice` categories — the other three exist in the
catalog/checklist only, and deliberately throw a clear "belum didukung" error if selected, since their
Xendit API integration was never built this sprint. Adding real support for one of those categories later
needs a new `XenditGatewayService` method + a new `match()` arm in `createPaymentFor()`, still no new
migration for the catalog itself.

**`payment_gateway_settings`/`payment_gateway_channels` are platform-level, not tenant/reseller-scoped** —
same posture as `payment_webhook_logs` (one Xendit account serves the whole ISP). Only
`payment_gateway_settings.manage`/`.view` permissions (super_admin-only, see
`RolesAndPermissionsSeeder::seedPaymentGatewaySettingsPermissions()`) gate the settings UI
(`App\Livewire\Settings\PaymentGatewaySettings`, `/settings/payment-gateway`) — deliberately stricter than
`invoices.*` (which `billing` role also has), since this page holds the actual Xendit secret. The settings
form never re-renders a saved secret/token back to the browser (masked placeholder only); an empty submit
leaves the previously-saved value untouched — see `PaymentGatewaySettingsService::update()`.

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
`teknisi`, `billing`, `sales_internal`, `sales_freelance`, `finance`.

**Permission pattern per module** (established in v0.2.0 Customer CRM, follow this for future
modules): the seeder's own comment says permission detail is added "per modul ditambahkan bertahap" —
each module adds a small set of granular permission strings (e.g. `customers.view`, `customers.manage`)
seeded in `RolesAndPermissionsSeeder::seed<Module>Permissions()`, assigned to whichever roles need
them. Policies (`App\Policies\*`) check these via `$user->can('module.action')` rather than hardcoding
role names — this stays correct as the permission-to-role mapping evolves without touching Policy code.
Keep the copy in `stubs/laravel-app/database/seeders/RolesAndPermissionsSeeder.php` in sync (BOSS-011
reproducibility — see "Stubs pattern" below).

**API response envelope helper**: `App\Http\Controllers\Concerns\ApiResponds` (a trait, `use` it in any
`Api/V1` controller) provides `$this->success($data, $message, $meta, $status)` matching the
`{success, message, data, meta}` shape from `HealthController` — don't hand-roll the envelope per
controller.

**Livewire UI**: Livewire was *not* actually installed until v0.2.0 (v0.1.0's README described the
target stack as "Blade + Livewire + Alpine + Tailwind" but only Tailwind was ever scaffolded — don't
assume README/CLAUDE.md stack descriptions were fully wired just because they're mentioned). It's
`livewire/livewire` (currently a v4.x release) via Composer; Alpine.js ships bundled with Livewire, no
separate npm package needed. Full-page Livewire components live in `app/Livewire/<Module>/`, paired
with a view in `resources/views/livewire/<module>/`, generated with
`php artisan make:livewire <Name> --class` (the `--class` flag matters — without it, Livewire 4
defaults to single-file components with emoji-prefixed filenames, which doesn't match this repo's
existing class/view separation convention). They're wrapped by `resources/views/layouts/app.blade.php`
automatically. **Route name collisions**: API resources (`routes/api.php`) and web pages
(`routes/web.php`) must not share a route name — `Route::apiResource('customers', ...)` already claims
`customers.index`/`customers.show`/etc., so web-facing Livewire page routes are named with a `web.`
prefix (`Route::name('web.')->group(...)` in `routes/web.php`) to avoid silently overwriting the API's
route name (whichever file's routes are registered later in `bootstrap/app.php` — `api` after `web` —
wins the name, so this breaks `route()` calls in Blade with no error, just a wrong URL).

**Frontend build**: the `boss-app` PHP container has no Node.js (keeps the image lean — RULE BOSS-007).
Compile Tailwind/Livewire assets with a throwaway Node container, same pattern as
`scripts/02-init-laravel.sh`'s throwaway Composer container:
`docker run --rm -v "$(pwd)/app":/app -w /app node:22-alpine sh -c "npm install && npm run build"`.
`scripts/deploy.sh` runs this on every deploy; do this manually after `git pull` in dev too, or Blade
changes to Livewire views won't show any Tailwind styling (Livewire's own JS still works either way —
it's served from the package directly, not through the Vite build).

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
