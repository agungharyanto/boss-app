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
# Bring the stack up (nginx, app, worker, boss-whatsapp-worker, scheduler,
# whatsapp-gateway, postgres, redis)
docker compose up -d --build
docker compose ps                     # all should be Up/healthy

# Migrations / seeding
docker compose exec boss-app php artisan migrate
docker compose exec boss-app php artisan db:seed --class=RolesAndPermissionsSeeder

# One default WhatsApp template per event_type, per existing tenant
# (v0.4.0) — safe to re-run, never overwrites an admin-edited template.
docker compose exec boss-app php artisan db:seed --class=WhatsappMessageTemplateSeeder

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

## WhatsApp Gateway (Baileys, v0.4.0)

**Topology (final, don't reintroduce a different shape without asking first)**: one WhatsApp number per
reseller, plus one "direct" session for a customer with no reseller (`customer.reseller_id` null) —
consistent with the "direct row" pattern already used in `reseller_tax_ledger`/
`komdigi_remittance_summary`. `session_key` (a plain string, used as the Redis queue name suffix, the Node
service's in-memory map key, and the `auth_state/{session_key}/` folder name) is `(string) reseller_id`, or
the literal `"direct"` when `reseller_id` is null — see `App\Models\WhatsappSession::sessionKeyFor()`.

**Two-service split**: `whatsapp-gateway/` (root-level, Node.js, `@whiskeysockets/baileys`) is a
multi-session Baileys manager exposing a small internal HTTP API (`POST /sessions/{key}/send`,
`GET /sessions/{key}/qr`, `GET /sessions/{key}/health`, `GET /sessions`) — it knows nothing about
customers/invoices, only `session_key` + phone number + message text. It runs as its own `whatsapp-gateway`
Docker container, **no host port published** (BOSS-010) — only reachable from `boss-app`/
`boss-whatsapp-worker` over `boss-network`. Laravel's side lives entirely under
`App\Services\Whatsapp\*`/`App\Jobs\SendWhatsappMessageJob`.

**HMAC-SHA256, not a static token** — unlike Xendit's webhook (`hash_equals` against a stored token),
every request between Laravel and the Node service is signed: `App\Support\WhatsappHmac::sign()` HMACs
`"{timestamp}.{body}"` with the shared secret (`WHATSAPP_GATEWAY_HMAC_SECRET`, infra-level like `APP_KEY`,
**not** a business credential — must match byte-for-byte in both `.env` files, root and
`whatsapp-gateway/.env`), and `verify()` rejects anything outside a 5 minute tolerance window even with a
byte-correct signature (replay protection). Node's `src/hmac.js` is a deliberate line-for-line mirror of
the PHP version — keep them in sync if this ever changes.

**Auth state persistence**: `whatsapp-gateway/auth_state/{session_key}/` (bind-mounted volume) — a Baileys
session survives container restarts (`SessionManager.restoreAll()` re-attaches every persisted session on
boot). A genuine WhatsApp-side logout wipes that session's folder and starts a fresh pairing (new QR) only
when explicitly asked (`getOrRefreshQr()`), never automatically on every disconnect — a merely transient
disconnect instead reconnects on its own using the same saved creds.

**Outbound-only this sprint** — no inbound/2-way handling at all (planned future integration point:
Chatwoot, one shared number for all CS, tracked as backlog in `docs/ROADMAP.md`, not started).

**Template resolution**: `App\Services\Whatsapp\WhatsappTemplateService::resolve()` — a reseller's own
active override (`whatsapp_message_templates.reseller_id` = that reseller) wins; otherwise falls back to
the tenant's default ISP-level template (`reseller_id` null) for the same `event_type`. Both `resolve()`
and every query in this module use `withoutGlobalScopes()` explicitly and pass `tenant_id` manually — this
runs from queued jobs and scheduled commands with no authenticated user, where `BelongsToTenant`'s
`TenantScope` (which only filters `if (Auth::check())`) wouldn't help anyway.

**Four fixed trigger types** (`App\Enums\WhatsappEventType`) — **no fifth type without asking first**:
`invoice_due_reminder` (H-5 and H-0 only — **explicitly no overdue reminder**, once an invoice passes
H-0 unpaid there is no further WhatsApp nudge for it, enforced almost for free by
`whatsapp:send-due-reminders` only ever querying `InvoiceStatus::Pending`, never `Overdue`),
`payment_received` (hooked into `InvoiceService::markPaid()` — fires for **both** legitimate callers, the
v0.3.4 manual PATCH endpoint and `PaymentService::handleWebhook()`, same as every other `markPaid()`
consumer), `customer_registered` (hooked into `RegistrationService::register()`, dispatched **after**
`DB::transaction()` returns so a rolled-back registration never notifies), `customer_suspended_reminder`
(daily for as long as `customer.status` stays `CustomerStatus::Suspend` — value `"suspend"`, not
`"suspended"` — no manual stop flag, it just stops the moment the scheduled query no longer matches that
customer).

**Known gap, accepted as-is (confirmed explicitly before the first migration ran)**: `RegistrationService::register()`
never sets `customer.reseller_id` (not in its `$data` shape, no auto-fill anywhere in that flow) — so
`customer_registered` always resolves to the `"direct"` session, never a reseller's, even for a
reseller-attributed customer. This is v0.3.0's existing registration behavior, deliberately untouched;
fixing it (if ever needed) is new scope for its own sprint, not a v0.4.0 retrofit.

**`{payment_link}` is generated on demand, not read from a stored column** — no `invoice_url`/`payment_link`
field is persisted anywhere ahead of time (a `Payment` row for the invoice-being-reminded-about may not
exist yet at reminder time). `WhatsappGatewayService::buildAndQueue()` calls
`PaymentService::createPaymentFor($invoice, 'XENDIT_INVOICE')` right when rendering an
`invoice_due_reminder`, lazily resolved via `app(PaymentService::class)` (**not** constructor-injected —
`PaymentService` depends on `InvoiceService`, which depends on `WhatsappGatewayService`; a constructor
dependency here is a circular resolution loop that exhausts memory building the container graph before any
code runs — this bit the test suite once already, don't reintroduce it). Failure (channel not enabled,
Xendit API error, etc.) is caught and logged — the reminder still sends without a link rather than never
sending at all.

**Per-session queue naming + the worker that actually drains it**: every send goes through
`App\Jobs\SendWhatsappMessageJob`, dispatched onto queue `whatsapp-{session_key}` (never the shared
`default` queue) — a disconnected/rate-limited reseller's backlog can't block another reseller's queue.
Because these queue names are dynamic (one per reseller, created whenever an admin sets up a new session),
a normal static `queue:work --queue=...` flag can't enumerate them ahead of time. `boss-whatsapp-worker`
(separate container from `boss-worker`) solves this the same way `boss-scheduler` solves polling: its
entrypoint loops `php artisan queue:work --queue=$(php artisan whatsapp:queue-names) --max-time=300`,
restarting every 5 minutes so a newly created reseller session's queue is picked up automatically, no
manual restart needed. `SendWhatsappMessageJob` itself applies the global rate-limit delay
(`WhatsappGatewaySettings::current()`, random 5-10s by default) via a plain `sleep()` before attempting the
send, and retries up to 3 times with 30s/2min/5min backoff via explicit `$this->release()` calls (not
exception-based retry) so both the HTTP-non-2xx path and the thrown-exception path behave identically.

**`session_key` `"direct"` is only unique while this deployment serves a single ISP tenant** — same
operating assumption already documented for `payment_gateway_settings` (one Xendit account for "the whole
ISP", not per-tenant). `App\Services\Whatsapp\WhatsappSessionService::resolveSessionByKey()` picks the one
existing `reseller_id IS NULL` session for the literal key `"direct"` — correct today, but would need a
tenant-qualified key instead of the bare literal if this ever becomes genuine multi-tenant SaaS with
several ISPs sharing one `whatsapp-gateway` container. A non-null `session_key` (a reseller id) doesn't
have this problem — `resellers.id` is a platform-wide primary key, not per-tenant, so it resolves
unambiguously on its own.

**Authorization mirrors the reseller/tax-engine pattern, not Spatie permissions, for reseller-owned
resources**: `WhatsappSessionPolicy`/`WhatsappMessageTemplatePolicy`/`WhatsappMessageLogPolicy` check
`whatsapp_gateway.view`/`.manage` (super_admin, seeded in
`RolesAndPermissionsSeeder::seedWhatsappGatewayPermissions()`) for full/ISP-admin **view** access, or **any
active `reseller_users` membership** (owner **or** staff — unlike `ResellerTaxPolicyPolicy`, this module
does **not** restrict staff to read-only) for a reseller's own session/templates/queue. The platform-level
rate limit config (`whatsapp_gateway_settings.view`/`.manage`, `WhatsappGatewaySettingsPolicy`) is a fully
separate, stricter, super_admin-only permission pair — no reseller ever gets a say in the global rate
limit (tracked as backlog: "rate limit setting per-reseller", `docs/ROADMAP.md`).

**`WhatsappSessionPolicy::manage()` is ownership-exclusive, not permission-additive** (tightened during the
session-creation bugfix, confirmed explicitly — don't revert to "admin can manage everything"): for the
`reseller_id`-null direct session, only `whatsapp_gateway.manage` grants manage rights; for a reseller-owned
session, only that reseller's own `reseller_users` membership does — an ISP admin can always *see* every
session's status (`view` still checks the permission first) but can never create/refresh-QR a reseller's
session, only their own direct one. `App\Services\Whatsapp\WhatsappSessionService::createSession()` is the
one place a brand-new `whatsapp_sessions` row gets inserted (there is no seeder/factory path in production
— every session starts from a user clicking "Hubungkan Nomor" in `App\Livewire\Whatsapp\WhatsappGatewayIndex`);
it inserts the row then immediately calls `refreshQrCode()` once to kick off the Node-side Baileys
`connect()` — the actual QR arrives asynchronously via the `connection.update` webhook, so the UI polls
(`wire:poll.3s`, plain re-render — no repeated Node HTTP calls) rather than expecting a QR back from the
create call itself.

**Three infrastructure gaps found and fixed post-implementation (same class of bug as the `APP_ENV`/phpunit
entries above — a new sprint's `.env.example` addition never automatically reaches a server's real,
gitignored `.env`)**, all on this dev VM:
1. `whatsapp-gateway`/`boss-whatsapp-worker` containers were never actually built/started — every command
   during development ran via `docker compose exec boss-app ...` against already-running containers,
   `docker compose up -d --build` for the two new services was never run. `docker ps -a` showed neither
   container existing at all (not crash-looping — simply never created).
2. `whatsapp-gateway/Dockerfile`'s `npm install` failed (`spawn git ENOENT`) — `node:22-alpine` has no
   `git` binary, and a transitive Baileys dependency resolves from a git URL. Fixed with `RUN apk add
   --no-cache git` before `npm install`.
3. Root `.env` (the real one this server's containers read, gitignored) had no `WHATSAPP_GATEWAY_URL`/
   `WHATSAPP_GATEWAY_HMAC_SECRET` at all — only `.env.example` got these keys during development, the
   already-existing real `.env` was never told about them. `WhatsappSessionService::refreshQrCode()`
   correctly logged `"services.whatsapp_gateway.url not configured"` and bailed out (not a silent
   swallow), but the result was indistinguishable from a deeper bug until this log line was actually
   checked. Fixed by adding both keys to this VM's real `.env`, then **recreating** (not just restarting)
   `boss-app`/`boss-worker`/`boss-whatsapp-worker`/`boss-scheduler` — editing `.env` alone doesn't refresh
   an already-running container's `env_file`, same lesson as the `APP_ENV` bug entry earlier in this file.

After all three fixes, the full loop was verified working end-to-end: `createSession()` inserts the row →
Node generates a QR asynchronously → `connection.update` webhook lands back on
`/api/v1/whatsapp/webhook/session-status` → `whatsapp_sessions.qr_code_data` gets a genuine base64 PNG
(verified by decoding the data URI, not just checking it's a non-empty string). **A real WhatsApp-app phone
scan was never performed** (no physical device access in this environment) — everything up to and
including QR delivery to the browser is confirmed working; the final "scan → connected" hop relies on the
same `applyStatus()` code path already covered by `WhatsappSessionWebhookTest`, but hasn't been observed
against a real Baileys/WhatsApp handshake.

## Installation / Work Order (v0.5.0)

**Topology**: `odps`, `technicians`, `work_orders` are all tenant-scoped with a nullable `reseller_id`
(null = owned directly by ISP A) — the exact same shape as `whatsapp_sessions`/`customers.reseller_id`.
`odp_ports`/`work_order_devices`/`work_order_photos` are child tables with no `tenant_id`/`reseller_id` of
their own — they're scoped implicitly through their parent (`odp_id`/`work_order_id`).

**`OdpLocatorService::findNearestAvailable()`** computes Haversine distance entirely in raw SQL (no
PostGIS extension) — verified working identically on SQLite (what the test suite runs on) and Postgres;
this environment's PHP SQLite build happens to include `acos`/`radians`/`cos`/`sin`, which isn't guaranteed
on every SQLite build, so re-verify this if the test-runtime environment ever changes. Scoped to ODPs
owned by the customer's own reseller (or the direct/no-reseller ODPs when the customer has none), same
tenant, `odp_ports.status = 'available'` only.

**A brand-new ODP has zero ports until `Odp::provisionPorts()` runs** — this is a model method, called
explicitly by `OdpController::store()` right after creating the row (creates `port_number` 1..`total_ports`,
all `available`). Deliberately **not** a model `created` event: that would silently fire for every
`Odp::factory()->create()` in tests too, colliding with `OdpPortFactory`'s own independently-created ports
on the `unique(odp_id, port_number)` constraint. If you ever add another real-world Odp-creation path
(a seeder, an import command), remember to call `provisionPorts()` there too — it's opt-in, not automatic.

**`WorkOrderStatus` state machine** (`App\Enums\WorkOrderStatus::canTransitionTo()`, mirrors
`InvoiceStatus`/`CustomerStatus`): a fixed linear happy path —
`pending_odp_check -> pending_verification -> ready -> assigned -> in_progress -> completed` — with
`odp_unavailable` as a dead-end reachable only from `pending_odp_check` (no `WorkOrderService` method ever
advances it further except `cancel()`), and any non-terminal status can be cancelled from anywhere.
`completed`/`cancelled` are terminal. **`WorkOrderService::complete()` checks transition legality FIRST,
before checking photo/device completeness** — found via its own test (`illegal transition ... is
rejected` initially failed because `IncompleteWorkOrderException` fired before
`InvalidWorkOrderStatusTransitionException` did); an illegal jump must fail as a transition error even when
photos/devices also happen to be incomplete, not get masked by the readiness check.

**`verify()` doesn't error on `equipmentReady=false`** — it just records the flag and leaves the work
order at `pending_verification` (not ready yet, not a failure state). It only actually transitions to
`Ready` when `equipmentReady` is true AND a port has already been reserved.

**`equipment_ready` is a manual placeholder, not real stock data** — the real stock/inventory module is
explicitly out of scope this sprint (see `docs/ROADMAP.md`); an admin/CS confirms it by hand via
`verify()`. Don't wire this to a real inventory count without new scope confirmation.

**Photo storage**: `App\Services\Installation\WorkOrderPhotoService::store()` — one photo per
`(work_order_id, type)`, DB-enforced via a unique constraint. Re-uploading an already-present type deletes
the old file first, then `updateOrCreate`s the row — never leaves two files or two rows for the same type.
Stored on the `'local'` Laravel disk (private, never publicly served) — its actual root on Laravel 12 is
`storage/app/private/`, not bare `storage/app/` (a framework-version detail, not a deliberate deviation
from "use the local disk").

**Authorization mirrors the reseller/WhatsApp-gateway pattern**: `OdpPolicy`/`TechnicianPolicy`/
`WorkOrderPolicy` check `odps.*`/`technicians.*`/`work_orders.*` permissions (super_admin-only, seeded in
`RolesAndPermissionsSeeder::seedInstallationPermissions()`) for full ISP-admin access, or **any active
`reseller_users` membership** (owner **or** staff, not owner-only) for a reseller's own ODPs/
technicians/work orders. The existing `teknisi` Spatie role (an `Agent` type used for field registration/
commission since v0.3.0, a completely different concept from the new `Technician` model here) deliberately
does **not** get these permissions automatically — a technician's own scoped access (seeing only their own
assigned work orders), if ever wanted, is new scope for a later sprint, not assumed here.

**Deferred, explicitly out of scope this sprint** (see `docs/ROADMAP.md`): real stock/inventory module,
automatic WhatsApp notifications for work order events (the v0.4.0 WhatsApp Gateway module exists and could
integrate here later, but wasn't wired up this sprint), and the html5-qrcode browser camera-scanning UI
component (the `POST /work-orders/{id}/devices` API endpoint is ready to receive whatever a scan produces,
the camera UI itself wasn't built).

## FreeRADIUS Core & NAS Management (v0.6.1)

**v0.6.0 was split into 5 sub-versions (`v0.6.1`-`v0.6.5`)** to replicate the VPN+RADIUS pattern from
competitor reference MixRadius V3.2 — see `docs/ROADMAP.md` for the full breakdown and the locked
cluster-wide architecture decisions (multi-protocol VPN, pool-ready-but-1-node topology, unique RADIUS
port per NAS). This sprint is only the first slice: the FreeRADIUS container itself + Laravel's own NAS
inventory/CRUD. No real NAS trusts this FreeRADIUS instance yet — that's v0.6.5 (dynamic virtual server +
port allocator).

**Two completely different `nas` tables, same name, different databases — do not confuse them**:
`boss_db.nas` (this sprint's migration, `App\Models\Nas`) is BOSS App's own business inventory of Mikrotik
routers — reseller-scoped, encrypted API/RADIUS credentials, driven entirely by `NasService`/`NasController`.
`radius_db.nas` is FreeRADIUS's own standard schema table (`nasname`/`shortname`/`secret`/...) — the
RADIUS-protocol client whitelist FreeRADIUS itself consults to decide which IPs may send it Access-Request
packets. **v0.6.1 does NOT sync between them** — creating a row in `boss_db.nas` has zero effect on
`radius_db.nas` right now. That sync (along with the dynamic virtual server + port allocator that makes a
per-NAS unique port meaningful) is v0.6.5 scope.

**Two separate Postgres containers, not two databases in one container** (confirmed explicitly before
building, BOSS-009: `radius_db` logically separated from `boss_db`, no cross-database joins) —
`freeradius-db` (own container, own volume, own credentials, `RADIUS_DB_*` env) is completely independent
of `boss-postgresql`. Root `.env.example` had reserved a distinct `RADIUS_DB_HOST` placeholder since
v0.1.0, which is what confirmed this was the intended shape rather than a shared instance. Neither
`freeradius-db` nor `freeradius` publish a host port (BOSS-010) — nothing outside `boss-network` needs to
reach FreeRADIUS yet; real NAS/Mikrotik traffic arrives via the VPN concentrator container starting v0.6.2,
which will join `boss-network` and relay internally.

**`nas.mikrotik_ip`/`auth_port`/`acct_port` are nullable on purpose, not a gap** — every NAS starts in a
pre-VPN-provisioning state. `mikrotik_ip` gets filled automatically once VPN provisioning exists (v0.6.2);
`auth_port`/`acct_port` get filled once the dynamic port allocator exists (v0.6.5). `StoreNasRequest`/
`UpdateNasRequest` deliberately don't accept `mikrotik_ip` as user input at all in v0.6.1 — there is no
manual "type in the router's IP" path, by design, so a later sprint can't accidentally skip the VPN
provisioning step. `coa_port` is the one exception with a real default (3799, the RFC 5176
Change-of-Authorization/Disconnect port) since it doesn't need to be unique the same way auth/acct do until
v0.6.5 actually wires up CoA.

**`App\Services\Network\Contracts\RouterOsGateway`** is a deliberate boundary around the MikroTik RouterOS
API transport (`evilfreelancer/routeros-api-php`, raw sockets — not HTTP) so `NasService::testConnection()`
stays testable without a real router: there's no `Http::fake()` equivalent for a raw-socket protocol, so
tests bind a fake implementation of the interface directly (see `Tests\Feature\Network\NasServiceTest`).
`RouterOsApiGateway` (the real implementation) builds a fresh `RouterOS\Client` per call from the NAS row's
own encrypted credentials — never a static/global config — same per-row-credentials posture as
`WhatsappGatewayService`'s per-session resolution. `NasService::testConnection()` refuses outright
(`NasNotProvisionedException`, 422) when `mikrotik_ip` is still null, rather than attempting a connection
that could only ever fail confusingly.

**Response envelope never includes `api_password`/`radius_secret` values** — `NasResource` only exposes
`has_api_password`/`has_radius_secret` booleans, same posture as `payment_gateway_settings` never
re-rendering a saved secret back to the browser.

**Three infrastructure gaps found and fixed while building** (same class of bug as the `APP_ENV`/WhatsApp
`.env` entries earlier in this file — a new dependency's build/runtime requirement not yet reflected in the
image or config that ships it):
1. `ext-sockets` (required by `evilfreelancer/routeros-api-php`) failed to compile in `docker/php/Dockerfile`
   with `fatal error: linux/sock_diag.h: No such file or directory` — Alpine's base image doesn't ship full
   kernel headers. Fixed by adding the `linux-headers` apk package before `docker-php-ext-install sockets`.
2. `freeradius/freeradius-server:*-alpine`'s compiled-in `rlm_sql_postgresql.so` failed to instantiate
   (`Error loading shared library libpq.so.5`) — the alpine base image doesn't include the Postgres client
   runtime library. Fixed by adding `apk add libpq` to `docker/freeradius/Dockerfile`.
3. The first `mods-available/sql` overlay used `${env:RADIUS_DB_HOST}`-style syntax for injecting
   `RADIUS_DB_*` into the connection string, which FreeRADIUS rejected at parse time
   (`Reference "${env}" not found`) — `${...}` in a FreeRADIUS config file means "reference another config
   value defined elsewhere," not environment-variable interpolation. The correct syntax, confirmed working
   end-to-end (a manually inserted `radcheck` row authenticated successfully via `radclient auth` and
   returned a real `Access-Accept`), is Perl-style `$ENV{RADIUS_DB_HOST}`.

**Healthcheck is a real Status-Server round-trip, not just "is the process running"** — it sends a
Status-Server packet via `radclient` (binary lives at `/opt/bin/radclient` in this image, not on `$PATH`)
against the stock `localhost` client already defined in the unmodified default `clients.conf` (secret
`testing123`) and greps for `Access-Accept`. `status_server = yes` is FreeRADIUS's own default in
`radiusd.conf`, so this works with zero extra config — and because `-sql` is already wired into
`authorize{}` in the stock `sites-enabled/default` (the leading `-` means a missing/failed module doesn't
hard-reject the request; no site-file edits were needed at all this sprint), a passing healthcheck is
reasonable evidence the `sql` module itself instantiated correctly too, not just that the process is alive.

**Out-of-scope v0.6.1, explicitly deferred to later v0.6.x sub-versions** (see `docs/ROADMAP.md`): any VPN
protocol/server (v0.6.2/v0.6.3), `vpn_servers` pool/failover schema (v0.6.4), dynamic per-NAS FreeRADIUS
virtual server + port allocator + CoA/disconnect (v0.6.5) — meaning `auth_port`/`acct_port`/`coa_port` on
the `nas` table are currently unused by FreeRADIUS itself, and no real NAS can authenticate against this
FreeRADIUS instance yet.

## VPN Server Node #1 (OpenVPN, v0.6.2)

**Built from `alpine:3.20` + the distro's own `openvpn`/`easy-rsa` packages, not a community wrapper
image** — same reasoning as `docker/freeradius`: `kylemanna/openvpn` (the default community choice) hasn't
published an updated Docker Hub image since Dec 2020. Alpine 3.20 ships current `openvpn` 2.6.20 and
`easy-rsa` 3.1.7.

**`boss-network` now has a fixed IPAM subnet (`172.28.0.0/24`) and `freeradius` is pinned to a static
`ipv4_address` (`172.28.0.10`, `FREERADIUS_INTERNAL_IP` in `.env`)** — this is not a convenience, it's load
-bearing: the openvpn container's `push route` AND its iptables `FORWARD` allowlist are both keyed off this
exact address, matching the locked decision "FreeRADIUS selalu diakses di SATU IP internal tetap dari sisi
Mikrotik." An unpinned bridge IP could silently drift on container recreation and break both.

**PKI bootstrap happens in the `openvpn` container's entrypoint on first boot, using EC certs
(`EASYRSA_ALGO=ec`, `secp384r1`) + `dh none`** — avoids the classic multi-minute `openvpn --genkey
dh2048.pem` first-boot delay. `status_server`/CRL behavior confirmed from OpenVPN's own docs (not assumed):
`crl-verify` is re-read fresh on every new connection/TLS renegotiation, so `revoke()` needs no daemon
signal/restart at all — it only blocks *future* connection attempts; an already-connected session isn't
force-dropped (would need the OpenVPN management interface, not built this sprint — known limitation,
tracked as backlog, not silently missing).

**Provisioning architecture is a shared Docker volume, not a Docker-socket-mounted exec, not a sidecar HTTP
API** (explicit decision, confirmed before implementation) — `boss-app` and `openvpn` both mount the same
two named volumes (`vpn_pki`, `vpn_ccd`). `App\Services\Network\VpnProvisioningService` runs `easyrsa`
directly (Process facade) from inside `boss-app` against the PKI the `openvpn` container already
bootstrapped. **Gotcha found running this for real, not just in tests**: `easyrsa init-pki` does a hard
`rm -rf` reset of `--pki-dir` on first run — this fails with `Resource busy` if `--pki-dir` is literally a
volume's own mountpoint. Both containers mount the volume one level up from the actual PKI
(`.../pki-data/pki`, not `.../pki-data`) specifically to avoid this. The shared volume is also
`chmod -R 0777`'d by the `openvpn` entrypoint after bootstrap — `boss-app`'s php-fpm workers run as
`www-data`, a different UID than the `openvpn` container's root process, and both need write access to the
same `pki/issued`, `pki/private`, `pki/index.txt`, etc.

**`vpn_ip_pool` is a table that wasn't in the original spec** — added because race-condition-safe IP
allocation needs a *pool of individually lockable rows* (`SELECT ... WHERE status='available' ORDER BY id
LIMIT 1 FOR UPDATE`, exactly the `OdpPort`/`WorkOrderService` pattern from v0.5.0), not just a
"lock the parent and scan" approach — locking `vpn_servers` itself would serialize every concurrent
provisioning attempt server-wide for no reason. `VpnServer::provisionIpPool()` (via `App\Support\CidrRange`,
pure IP arithmetic, unit-tested independent of any DB) generates one `available` row per usable host address
in `subnet_cidr` — same "explicit method call, not a `created` event" reasoning as `Odp::provisionPorts()`
(avoids colliding with `VpnIpPoolFactory` rows in tests). `.1` in every subnet is reserved for the VPN
node's own tun0 endpoint address and never appears in the pool.

**`provision()` deliberately splits DB allocation from the slow `easyrsa` call across the transaction
boundary**: the IP lock+insert transaction is fast and commits immediately; `easyrsa build-client-full` (and
the ccd file write) run *after*, outside any lock. If that external step fails, the DB allocation is
explicitly rolled back (IP released back to the pool, the `vpn_accounts` row deleted, `current_clients`
decremented) rather than left behind as a phantom "active" account with no real certificate behind it —
verified by a real induced failure in `VpnProvisioningServiceTest`, not just a happy-path assumption.

**Hub-and-spoke isolation is enforced at three independent layers, not just "don't push a route"** (a
NAS-facing VPN client that merely isn't *told* about `boss-postgresql`/`boss-redis` could still reach them
by IP unless actively blocked):
1. `client-config-dir` (`ccd/<username>`, `topology subnet`) — `ifconfig-push <internal_ip> <netmask>` only,
   for static per-NAS IP assignment. No per-client route lives here — every NAS is allowed the exact same
   one destination, so there's nothing to differentiate.
2. A single global `push "route <FREERADIUS_INTERNAL_IP> 255.255.255.255"` in `server.conf` — the only
   route any NAS is ever told about.
3. `iptables` inside the `openvpn` container's own network namespace (`FORWARD` policy `DROP`, one explicit
   `ACCEPT` rule scoped to `-i tun0 -d $FREERADIUS_INTERNAL_IP`) plus `MASQUERADE` on traffic leaving the VPN
   subnet toward FreeRADIUS — set by the entrypoint at every container start (kernel netfilter state doesn't
   persist across container recreation, so this can't be a one-time manual step).

**Testing a raw-socket/CLI external dependency**: unlike `WhatsappGatewayService` (HTTP, testable via
`Http::fake()`) or `RouterOsGateway` (v0.6.1, a hand-rolled interface + fake binding because the RouterOS
API client has no fake mode), `easyrsa`/`openssl` calls go through Laravel's own `Process` facade, which
has first-class `Process::fake([...])` support — no custom abstraction needed here. **Gotcha found writing
these tests**: `Process::fake()` pattern-matches against `Symfony\Component\Process\Process::getCommandline()`,
which quotes every argument (`'easyrsa' '--pki-dir=...' '--batch' ...`) — a fake pattern must start with `*`
(`'*easyrsa*build-client-full*'`) to account for the leading quote character, or the pattern silently never
matches and the test executes the *real* binary instead (which is how this was actually caught — a real
`easyrsa` process failed against a fake `ca.crt`, not a passing-for-the-wrong-reason test).

**Real end-to-end verification performed, not just mocked tests**: after bringing the `openvpn` container up
for real, `boss-app` successfully ran `easyrsa build-client-full`/`revoke`/`gen-crl` directly against the
same PKI the `openvpn` entrypoint had bootstrapped, and `VpnProvisioningService::provision()`/`revoke()` were
called for real (not `Process::fake()`) end-to-end against a real `Nas` row, producing a real signed
certificate, a real allocated `internal_ip`, and a real CRL update on revoke. A real Mikrotik→OpenVPN
connection was **not** performed (no physical/virtual NAS device available in this environment) — everything
up to and including cert issuance and IP allocation is confirmed working; the actual "Mikrotik dials in and
gets this exact ifconfig-push IP" hop relies on stock OpenVPN/`client-config-dir` behavior, not custom code,
but hasn't been observed against a real device. This mirrors the v0.4.0 WhatsApp QR-delivery-vs-actual-scan
gap in this same file.

**Out-of-scope v0.6.2, explicitly deferred to later v0.6.x sub-versions** (see `docs/ROADMAP.md`): WireGuard
and L2TP/IPsec (v0.6.3), a Mikrotik-ready script generator (v0.6.3), multi-node `vpn_servers`
pool/health-check/failover (v0.6.4 — this sprint's single row was created by hand via `tinker`, not through
a REST endpoint; **no `VpnServerController` exists yet** because CRUD for it only becomes a real need once
more than one node exists), dynamic per-NAS FreeRADIUS virtual server + CoA (v0.6.5, unchanged from v0.6.1's
note), and force-disconnecting an already-connected VPN session on revoke (needs the OpenVPN management
interface). `nas.mikrotik_ip` is still not auto-filled by this sprint's provisioning flow — the sprint scope
was explicit that connecting `internal_ip` to `nas.mikrotik_ip` is a manual/separate step this sprint, not
automated until v0.6.3's script generator closes the loop.

## Multi-Protocol VPN & Script Generator (v0.6.3)

**Two architecture decisions were resolved explicitly with Agung before any migration/container was
written** (see `docs/ROADMAP.md` for the full text) — don't re-litigate either without new confirmation:
1. WireGuard and L2TP/IPsec each get their own container, separate from `openvpn` — matches this repo's
   single-responsibility container pattern (`freeradius-db` separate from `freeradius`, `whatsapp-gateway`
   separate from `boss-app`). WireGuard needs a kernel netlink interface type + `NET_ADMIN`; L2TP/IPsec
   needs strongSwan (IKE/IPsec) + xl2tpd + pppd + `/dev/ppp` — three genuinely distinct stacks.
2. The Script Generator's RADIUS tab uses FreeRADIUS's default port (1812/1813), not `nas.auth_port`/
   `acct_port`, until the dynamic per-NAS virtual server ships in v0.6.5 — see `MikrotikScriptGenerator::radiusScript()`'s
   own docblock for the "must regenerate after v0.6.5" note baked into the generated script's own comments.

**Decision 1's schema consequence**: `vpn_servers.protocol_support` (a json array from v0.6.2, implying one
row could represent several simultaneously-running protocols) was **replaced** with a plain `protocol`
column — one `vpn_servers` row now represents exactly one (host, protocol) pair, because `status`/
`current_clients` is fundamentally a per-daemon concept once protocols run in separate containers. A new
migration (`2026_08_04_120000_alter_vpn_servers_protocol_column.php`, NOT an edit to the already-tagged
v0.6.2 migration) backfills the existing row automatically. `App\Enums\VpnProtocol` (`OpenVpn`/`WireGuard`/
`L2tpIpsec`) is now cast on both `vpn_servers.protocol` and `vpn_accounts.protocol`.

**Built from `alpine:3.20` + the distro's own packages for both new containers, not community images** —
same reasoning as `docker/freeradius` and `docker/openvpn`: `wireguard-tools` 1.0.20210914 and `strongswan`
5.9.13 / `xl2tpd` 1.3.18 are Alpine's own current packages. **strongSwan chosen over libreswan** specifically
for L2TP/IPsec PSK: more frequent Alpine releases (5.9.13 vs libreswan's 5.0) and far more common as the
reference implementation for exactly this "L2TP-over-IPsec, PSK auth" shape (the one Mikrotik's
`l2tp-client use-ipsec=yes` expects).

**WireGuard provisioning is NOT the same shared-volume-plus-easyrsa pattern as OpenVPN (v0.6.2) — a
genuinely different mechanism, not a copy-paste** — `wg set`/netlink peer changes only affect the process's
own network namespace, so running them from `boss-app` would silently do nothing to the real `wg0` interface
(they're different containers, different namespaces). What DOES work from `boss-app`: keypair generation
(`wg genkey`/`wg pubkey`, pure crypto via the `Process` facade, no interface involved) — needed
`wireguard-tools` added to `docker/php/Dockerfile` too (found missing during real end-to-end verification,
not caught by mocked tests — see gaps below). What doesn't: applying the peer to the live interface. Solved
with a **reconcile loop** in the `wireguard` container's entrypoint — the same polling-restart idiom already
used by `boss-scheduler`/`boss-whatsapp-worker` (`while true; do ...; sleep 10; done`): `boss-app` writes one
`[Peer]` fragment file per NAS to the shared `vpn_wg_data` volume, and the loop merges them into a full
config and applies it with `wg syncconf` (reconciles without disrupting peers that didn't change, unlike
`wg setconf` which replaces the whole peer set). **Real, verified consequence**: revoking a WireGuard peer
takes up to ~10 seconds (the loop's poll interval) to actually disappear from the live interface — confirmed
by timing it directly (`wg show wg0` before/after a real revoke) — unlike OpenVPN's CRL, which is checked
live per connection attempt with no such window.

**The WireGuard private key is a genuine declared PHP property on `VpnAccount`
(`public ?string $wireguardPrivateKey`), not an Eloquent attribute** — deliberately bypasses Eloquent's
magic `__get`/`__set` entirely, so it can never accidentally get written to the database by a later
`->save()`/`->update()` call, and never survives a `->fresh()`/reload or a fetch of the same account later.
This is the *only* place the private key exists outside whatever the admin does with the generated script —
same "shown once" posture as OpenVPN's exported `.ovpn`, just made structurally explicit here because
WireGuard has no CA/PKI to defer key custody to. **Real bug found and fixed via this exact property**:
`VpnProvisioningService::provision()`'s original final `return $account->fresh();` silently discarded this
transient property (a fresh DB re-query can't possibly carry a non-column value) — caught by
`VpnProvisioningMultiProtocolTest`, fixed by returning the in-memory `$account` directly (every
`issue*Credentials()` method already mutates it in place via `->update()`, so nothing is stale).

**L2TP/IPsec auth is two layers, only one of which is per-NAS**: the IPsec/PSK layer uses ONE shared secret
for the whole node (`L2TP_IPSEC_PSK`, an infra-level env secret like `WHATSAPP_GATEWAY_HMAC_SECRET` —
NOT per-NAS), while the PPP layer underneath is per-NAS username/password via `chap-secrets` — finally
putting `vpn_accounts.password` (encrypted, present since v0.6.2 but unused until now) to actual use.
`chap-secrets` is **rewritten wholesale** from the DB's active `l2tp_ipsec` accounts on every
provision()/revoke() (not a line-by-line append/remove) — `xl2tpd` spawns a fresh `pppd` process per
incoming call rather than running one long-lived resident process, so a rewritten file takes effect on the
very next connection attempt with zero reload — verified for real (provisioned a real account, confirmed
the exact line appeared in the file from BOTH containers' mount points via the shared `vpn_l2tp_secrets`
volume, revoked it, confirmed the file went back to empty).

**Script isolation strategy mirrors the server-side hub-and-spoke approach, expressed as RouterOS commands
on the NAS**: OpenVPN and L2TP client scripts use `add-default-route=no` plus a dedicated `routing-mark`
table + rule so ONLY traffic to FreeRADIUS's internal IP crosses the tunnel — normal NAS production routing
is untouched. WireGuard doesn't need this: its own `allowed-address` field (set to FreeRADIUS's single IP,
not `0.0.0.0/0`) already scopes exactly what routes through the tunnel on the client side, protocol-native.
Every generated script is idempotent (removes any pre-existing same-named interface/route/rule before
adding fresh ones) — **not verified against a real Mikrotik device** (none available in this environment),
same caveat already on record for the v0.4.0 WhatsApp QR-scan gap.

**Generating a RADIUS script also rotates `nas.api_username`/`api_password`** — `VpnScriptService::generateRadiusScript()`
deliberately closes the loop with `NasService::testConnection()` (v0.6.1), which needs real, currently-valid
Mikrotik API credentials to succeed at all. Every call rotates the password; the previous one becomes
invalid the moment the newly generated script actually runs on the router (the script itself removes and
recreates the `boss-api` user).

**Three real gaps found and fixed while building/verifying this sprint** (same class as prior sprints' gaps
— caught by actually running things, not just reading code):
1. `wireguard-tools` (needed for `wg genkey`/`wg pubkey` from `boss-app`) was only installed in the
   `wireguard` container's own image — missing from `docker/php/Dockerfile` entirely. First real
   provisioning attempt failed with `wg: not found`. Fixed by adding the package to `docker/php/Dockerfile`
   in **its own layer, placed after** the slow `ext-sockets` compile — a deliberate fix to the exact
   Dockerfile-layering mistake made twice already (v0.6.1, v0.6.2): adding a new package to the *same*
   `apk add` layer as something upstream of a slow compiled step invalidates that step's cache too.
2. `Process::fake()`'s pattern-matching gotcha (documented in v0.6.2's own CLAUDE.md section — patterns
   need a leading `*` because `getCommandline()` quotes every argument) applied identically to the new
   `wg genkey`/`wg pubkey` fakes — caught immediately this time because the earlier gotcha was already
   fresh in mind, not rediscovered the hard way again.
3. A `/29` CIDR test assertion (`test_slash_29_yields_4_usable_addresses`, written in v0.6.2) asserted the
   wrong count — `App\Support\CidrRange`'s actual (correct) output is 5 usable addresses for a `/29`
   (8 total − network − broadcast − the reserved `.1` gateway = 5, matching the same formula already
   verified correct for `/24` = 253). The test's own arithmetic was wrong, not the implementation — caught
   when `VpnProvisioningMultiProtocolTest`'s pool-exhaustion test (built against the real 5-address count)
   disagreed with the older test's stale assumption of 4.

**Out-of-scope v0.6.3, explicitly deferred to later v0.6.x sub-versions** (see `docs/ROADMAP.md`): multi-node
`vpn_servers` pool/health-check/failover + a `VpnServerController` REST API (v0.6.4 — this sprint's
WireGuard/L2TP `vpn_servers` rows were created by hand via `tinker`, same as OpenVPN's row in v0.6.2), a
dynamic per-NAS FreeRADIUS virtual server + CoA + genuinely-unique RADIUS ports (v0.6.5 — the Script
Generator's RADIUS tab stays on FreeRADIUS's default port until then), force-disconnecting an
already-connected WireGuard/L2TP session on revoke (WireGuard: peer disappears within ~10s, not instantly;
L2TP: an in-progress PPP session isn't forcibly torn down), and a real Mikrotik device connection test (no
physical/virtual NAS available in this environment — everything up to script generation and server-side
peer/secret state is confirmed working for real).

**Gap closed in the same sprint, before tagging: NAS management UI (`App\Livewire\Network\NasIndex`,
`/nas`)** — v0.6.1 was deliberately API-only; the first real attempt to use the Script Generator surfaced an
empty "Pilih NAS" dropdown. **Diagnosed before assuming a bug**: the query itself was correct — there were
simply zero legitimate `nas` rows for the real demo tenant (`super_admin@boss.local`, tenant "ISP Demo").
The 4 rows that did exist were leftover pollution from this sprint's own `tinker`-based end-to-end
verification sessions (v0.6.1-v0.6.3), sitting under throwaway `Tenant::factory()`-created tenants that no
real login belongs to — a consequence of `tinker` writing to the real dev Postgres connection, not the
isolated sqlite test connection (the same class of mistake already made once with stray `VpnServer` rows
during v0.6.2 verification). Cleaned up; the real fix was building the missing UI, not a code change to the
dropdown query.

**`nas.mikrotik_ip` is now editable through this UI** — a deliberate loosening of `StoreNasRequest`'s
original v0.6.1 stance (which refused it entirely, stricter than the actual v0.6.1 instruction, which only
said "don't make it required"). The field auto-locks (disabled, read-only) once the NAS has any active
`vpn_accounts` row — signaling "this is meant to be VPN-managed now" — but this UI does **not** build the
actual `internal_ip` → `mikrotik_ip` write-through (still the same documented gap from v0.6.2/v0.6.3: nothing
anywhere copies a VPN account's `internal_ip` onto `nas.mikrotik_ip`). Locking the field only prevents an
admin from fighting a sync mechanism that doesn't exist yet, not a claim that one does.

**"Tes Koneksi" reuses `RouterOsGateway` (the same interface `NasService::testConnection()` uses), not
`NasService::testConnection()` itself** — the button must test whatever is *currently typed in the form*,
which may not be saved yet (and for a brand-new NAS, there's no persisted row at all for `testConnection()`
to `->update()` onto, since that method requires an already-existing row). `NasIndex::testConnection()`
builds a transient, unsaved `Nas` instance from the live form fields and pings through the same gateway
binding directly; for an NAS that *is* already saved (editing an existing row), `status`/`last_ping_at` are
still persisted onto the real row afterward — only the ping itself uses the possibly-unsaved form values.

**Gap closed in the same sprint, before tagging: 3 real bugs found via manual UI testing.**

1. **`MikrotikScriptGenerator` always used the RouterOS-7-only `/routing table`/`routing-table=` mechanism**
   regardless of the selected `$routerOsVersion`, even though the OpenVPN/L2TP script headers claimed
   "RouterOS-generic v6.x dan v7.x" — `/routing table` doesn't exist at all on RouterOS 6.x (a v7 routing-
   subsystem redesign; v6 uses `routing-mark=` directly on `/ip route add` paired with an
   `/ip firewall mangle ... action=mark-routing` rule). Fixed by branching on `$routerOsVersion` — v6 gets
   the mangle+routing-mark shape (matching the MixRadius reference pattern the sprint kickoff cited), v7
   keeps the existing `/routing table`+rule shape. `dst-address=` on `/ip route add` was independently
   verified to already be present in both branches — that specific worry turned out not to be a real bug.
   Every generated script (any protocol) now also removes all 4 PPP-based client interface types
   (ovpn-client/sstp-client/l2tp-client/pptp-client) plus WireGuard up front, not just the one being
   configured — found necessary because switching protocols on the same NAS left the previous one's
   interface orphaned.
2. **Root cause of a real reported failure (NAS "nas-11"), found by inspecting the live PKI volume, not
   guessed**: `docker/openvpn/entrypoint.sh`'s `chmod -R 0777 "$PKI_DIR"` only runs ONCE, at first boot.
   Every `easyrsa` invocation after that (from `boss-app`, running as `www-data` for a real HTTP request)
   rewrites `pki/index.txt`/`serial`/`index.txt.attr` with OpenSSL's own restrictive default permissions —
   the next call (by any user) can then hit `Permission denied` partway through, after already printing a
   wall of RSA/EC key-generation progress dots (`.....+++....`) that were, before this fix, dumped raw and
   unfiltered into the exception message shown to the API/UI caller. **This specific instance was traced to
   this session's own testing methodology**: manual verification via `docker compose exec` (which always
   runs as root, unlike real traffic) left root-owned 600 files that a subsequent real `www-data` request
   from the actual UI then couldn't write to — root-owned files can only be `chmod`'d by root, so this isn't
   fully self-healing across a root/www-data mismatch, only across consecutive `www-data` runs (the actual,
   consistent identity of every real production request). Fixed: `VpnProvisioningService` now (a) captures
   stdout/stderr separately and logs the full untouched output+exit code to the Laravel log for debugging,
   (b) strips the cosmetic progress-dot noise and surfaces just the last few meaningful lines (capped at 500
   chars) in the exception message, and (c) re-applies `chmod -R 0777` on the PKI dir after every single
   `easyrsa` invocation (success or failure), not just at container boot. **Verified for real, not just in
   mocked tests**: two consecutive OpenVPN provisions run explicitly as `www-data`
   (`docker compose exec --user www-data`) succeeded back-to-back with no manual chmod in between; the
   user's own actual `nas-11` NAS, which had failed before this fix, was confirmed to have a populated
   `cert_serial` and a `www-data`-owned `.crt` file on disk afterward.
3. **`vpn_accounts.internal_ip`'s plain global `unique()` constraint (v0.6.2) applied to REVOKED rows too**
   — once an address was ever assigned, even to a now-revoked account, it could never be reused, despite
   `vpn_ip_pool` correctly marking it `available` again. Found while testing the new "Cabut & Generate Ulang"
   button (a revoke immediately followed by a re-provision onto the same, just-freed pool entry). Fixed with
   a new migration (`2026_08_04_130000_...`, not an edit to the already-tagged v0.6.2 migration) replacing
   the plain unique index with a partial one scoped to `WHERE status = 'active'` — same technique already
   used for `whatsapp_sessions` (v0.4.0) for an analogous "unique among a subset of rows" requirement.

**"Cabut & Generate Ulang" button** (`VpnScriptGenerator::revokeAndRegenerate()`) — appears only when
generation was blocked by an existing active `vpn_account` for the selected NAS+protocol AND the acting user
is authorized to manage that NAS (checked independently of *why* generation failed, so it never appears for
the unrelated "WireGuard needs RouterOS 7" error). Gated behind `wire:confirm` — revoking discards the old
account's private key/session permanently and drops the NAS's current tunnel if it was ever connected.

**Gap closed in the same sprint, before tagging: 4 more real bugs, this time from Agung's own testing
against a real Mikrotik router** (the first time this module was exercised against actual hardware rather
than server-side-only verification) — see CHANGELOG.md's "Amendment ketiga" for the compact version, this is
the full detail:

1. **Clipboard copy button silently did nothing** — `navigator.clipboard.writeText()` was the only copy
   mechanism, but this server has no TLS yet (`APP_URL=http://45.123.142.242`), and the Clipboard API is
   simply unavailable outside a secure context (HTTPS, or `localhost` specifically) — not a bug in the call
   itself, the API object doesn't meaningfully exist there. Fixed with a `document.execCommand('copy')`
   fallback (hidden `<textarea>`, select, copy, remove), chosen at click-time via `window.isSecureContext`.
   **Verified for real, not just reasoned about**: a headless Playwright browser logged into this exact dev
   server over plain HTTP (via the `boss-nginx` container, not `localhost` — Chromium treats `localhost`
   as secure even over HTTP, which would have hidden this exact bug) confirmed `isSecureContext === false`
   and `navigator.clipboard === undefined` for real, then confirmed `document.execCommand('copy')` was
   actually invoked (hooked directly, not inferred) when the "Salin" button was clicked, with the
   "Tersalin!" feedback appearing afterward.
2. **strongSwan rejected real RouterOS 7 L2TP/IPsec clients with `NO-PROPOSAL-CHOSEN`** — the
   `docker/l2tp/ipsec.conf.template` `ike=`/`esp=` lines only offered `aes256/aes128/3des-sha1-modp1024`
   in **strict mode** (trailing `!`, meaning "reject anything not in this exact list"). A real RouterOS 7
   client's actual IPsec proposal (captured from a working MixRadius-style reference setup) includes
   AES-CBC-256/HMAC-SHA1, AES-CBC-128/HMAC-SHA1, and — notably — a NULL-encryption/HMAC-SHA1 fallback,
   which wasn't offered at all. Fixed: `esp=` now includes `null-sha1`, `ike=` adds `modp2048` alongside
   `modp1024`, and the trailing `!` is dropped from both lines so strongSwan also matches against its own
   built-in default proposals when RouterOS's exact offer doesn't line up byte-for-byte — deliberately less
   strict, per Agung's explicit instruction not to over-restrict to one cipher. **Honest verification
   limit**: confirmed from this environment only that the config parses cleanly and `ipsec statusall` shows
   the `L2TP-PSK` connection loaded with no fatal errors after a full container rebuild — there is no real
   or virtual Mikrotik device available here, so whether `NO-PROPOSAL-CHOSEN` is actually gone and a real
   RouterOS 7 IPsec SA + `l2tp-client` "connected" state is achieved **has not been confirmed** and needs
   Agung's own hardware retest.
3. **Long scripts pasted into RouterOS's interactive terminal could trigger an unrelated confirmation
   prompt mid-paste** — reported as a `[y/N]` "save changes"-style prompt appearing partway through pasting
   the OpenVPN script (the one embedding a PEM private key), after which the rest of the script never ran
   ("interrupted"). Research into RouterOS's `/certificate import` confirmed a *different*, adjacent gotcha
   (RouterOS 7.13+ silently fails, doesn't hang, to import a private key if `passphrase=` isn't given
   explicitly — already correctly handled: `MikrotikScriptGenerator::openVpnScript()` always passes
   `passphrase=""`), but the reported symptom is most consistent with a known class of terminal-paste issue:
   very large pasted blobs processed character-by-character by an interactive CLI can occasionally be
   misinterpreted mid-stream. Fixed by replacing "paste the whole script" with **fetch+import**: the UI now
   shows one short line —
   `/tool fetch url="..." mode=http dst-path="boss-vpn-setup.rsc";/import file-name="boss-vpn-setup.rsc";/file remove [find name="boss-vpn-setup.rsc"];`
   (a genuine non-interactive script execution, never touching the interactive-paste code path at all) —
   instead of the full script. New `App\Services\Network\ScriptDownloadTokenService`: `store()` puts the
   full script in cache (Redis) under a `Str::random(48)` token with a 10-minute TTL;
   `retrieveAndInvalidate()` uses `Cache::pull()` (atomic get-and-delete) so a token can be fetched exactly
   once, ever — the script contains private keys/PSKs/passwords, so this is deliberately NOT a permanent
   public URL, just a short-lived one-time handoff. New unauthenticated route
   `GET /vpn-script-generator/download/{token}.rsc` → `VpnScriptDownloadController::show()` — deliberately
   outside any `auth`/`sanctum` middleware, since RouterOS's `/tool fetch` sends no session cookie or API
   token; the token itself (high entropy + short TTL + single-use) is the security boundary instead,
   `throttle:30,1` is just abuse-rate hygiene on top. The `http`/`https` scheme in the one-liner is read
   from the live request (`request()->getSchemeAndHttpHost()`), never hardcoded — this server is still
   plain HTTP today (checked before writing any of this, not assumed), so hardcoding `https` would have
   produced a one-liner that silently can't connect; this now flips to `https` on its own the moment TLS is
   actually added, no code change needed. Applied identically to **all** script types (VPN and RADIUS both,
   via a shared `VpnScriptGenerator::publishDownloadableScript()` private method), not just OpenVPN, for UX
   consistency. The full script is still viewable in a collapsed `<details>` block below the one-liner for
   audit purposes — it's just no longer the primary copy target. **Verified for real, end-to-end**: a
   headless Playwright browser generated a real RADIUS script through the live UI and read the actual
   rendered one-liner (confirming `mode=http`, not a hardcoded `https`); a separate `curl` against that
   exact URL confirmed the downloaded content matches the real script, and a second `curl` against the same
   URL confirmed a 404 (single-use genuinely enforced, not just asserted in an isolated test). **Honest
   verification limit**: this proves the server side is correct — it does NOT prove the originally-reported
   `[y/N]` hang is resolved on a real router, since `/tool fetch`+`/import`'s actual non-interactive
   behavior can only be confirmed on real RouterOS hardware, which isn't available here. Needs Agung's own
   retest.
4. **"Cabut & Generate Ulang" button was invisible** — styled `bg-red-600 text-white`, which turned out to
   be the *only* filled-button danger style anywhere in the app; every other destructive action
   (`nas-index.blade.php`'s "Hapus", `customer-show.blade.php`'s "Hapus kontak", etc. — 6+ instances) uses a
   plain `text-red-600 hover:underline` text-link style instead. Two compounding causes: the Tailwind CSS
   bundle hadn't been rebuilt since any of v0.6.3's new Blade views were added (`app-BgK2W_ZW.css`, dated
   before the views existed — confirmed via `grep`, `bg-red-600` was genuinely absent from the compiled
   output, not a class-name typo), and even after rebuilding, a one-off filled-button pattern would still
   have been visually inconsistent with the rest of the app. Fixed by rebuilding the CSS bundle AND
   switching this button to the app's established `text-red-600 hover:underline` pattern, so both causes are
   closed at once — this also fixes the same latent staleness for `nas-index.blade.php` and any other
   v0.6.3 view compiled before this rebuild. **Verified for real**: Playwright read the actual
   `getComputedStyle(...).color` of the "Hapus" button (same class, on a real rendered `/nas` page) —
   `oklch(0.637 0.237 25.331)`, genuine Tailwind `red-600`, not white/transparent.

**Gap closed in the same sprint, before tagging: a real 500 regression introduced by fix #4's own testing
session** — clicking the now-visible "Cabut & Generate Ulang" button for a NAS with an active WireGuard
account threw a generic 500. **Root cause found from `storage/logs/laravel.log` itself, not guessed**:
`mkdir(): Permission denied` inside `VpnProvisioningService::issueWireGuardCredentials()`, writing a peer
fragment to `/vpn-wg-data/peers`. A direct `stat` from inside the container found `/etc/wireguard` (the
`vpn_wg_data` volume's mount point) itself sitting at `0700 root:root` — Alpine's `wireguard-tools` package
ships that directory locked down by default (it's designed to also hold a private key directly, in a
single-user setup), and that permission carried straight into the named Docker volume the first time it was
populated. `docker/wireguard/entrypoint.sh` only ever `chmod`'d the *children* (`peers/` → `0777`, the two
key files → `0644`) — it never widened `$WG_DIR` itself, so `www-data` couldn't even traverse into it to
reach the already-permissive `peers/` directory; `File::isDirectory()`/`makeDirectory()` failed with EACCES
before getting anywhere near it. This is the same *class* of shared-volume-permission bug as the OpenVPN
PKI/nas-11 fix earlier in this file, but a different root cause (a pre-existing restrictive default baked
into the base package, not permissions regressing after each operation) — checked `vpn_l2tp_secrets` for the
same class of issue while here and confirmed it's fine (`0755`, created fresh by a plain `mkdir -p` with no
package pre-seeding a restrictive mode). Fixed with one added line,
`chmod 0755 "$WG_DIR"`, in the entrypoint. **Verified for real, reproducing the exact reported scenario**:
rebuilt the `wireguard` container, then a real Playwright browser logged in, selected NAS `test-x86-bajastu`
(nas-11, which genuinely already had an active WireGuard account), selected WireGuard, clicked Generate,
clicked the now-appeared "Cabut & Generate Ulang" button, accepted the real `wire:confirm` dialog, and
confirmed the fetch+import one-liner rendered successfully with no 500 — cross-checked against the database
(old account flipped to `revoked`, a new one `active`) and the Laravel log (zero new error/warning entries
during that window).

## FreeRADIUS Dynamic Virtual Server & CoA (v0.6.5)

**Final sub-sprint of the v0.6.0 FreeRADIUS cluster.** Scope: real per-NAS FreeRADIUS listen sockets (not
the shared default port from v0.6.1-v0.6.4), a race-safe port allocator, the Script Generator RADIUS tab
switched to real ports, and a CoA/Disconnect service — all verified against `test-x86-bajastu`, which
turned out mid-sprint to be a **real production router with 427 active PPPoE customers**, not a lab
device (see the CoA section below for the safety implications this had).

**Dynamic virtual server mechanism**: `App\Services\Network\FreeradiusVirtualServerService::sync()`/
`remove()` write/delete two files per NAS (`listen/nas-{id}.conf`, `clients/nas-{id}.conf`) onto a new
shared volume (`freeradius_nas_config`, mounted at `/freeradius-nas-config` in both `boss-app` and
`freeradius`) — `NasService::create()`/`update()`/`delete()` call these automatically. FreeRADIUS's stock
`sites-enabled/default` and `clients.conf` are patched once, idempotently, by `docker/freeradius/
entrypoint.sh` with `$INCLUDE /freeradius-nas-config/listen/` and `$INCLUDE .../clients/` — a directory
`$INCLUDE`, not a single file, confirmed to be real supported FreeRADIUS syntax by testing it directly
against the running container (a test `listen{}` dropped into the directory, followed by a restart,
genuinely opened the new UDP port and a `radclient` round-tripped a real reply through it). Every NAS gets
its own `auth`+`acct` listen pair sharing ONE `clients {}` block scoped to `172.28.0.0/24` (boss-network as
a whole, **not** the NAS's own VPN tunnel `internal_ip`) — because every VPN node container MASQUERADEs NAS
traffic onto its own boss-network IP before it reaches FreeRADIUS (v0.6.2 hub-and-spoke), FreeRADIUS can't
tell NAS apart by source IP the way a normal RADIUS deployment would. **Isolation between NAS is by PORT,
not source IP** — this is the actual reason "unique RADIUS port per NAS" was locked in as this cluster's
architecture all the way back in v0.6.1, not just a cosmetic choice.

**Real infrastructure gaps found deploying this for real** (same class as every prior sprint's entries in
this file — found by actually running it, not by reading FreeRADIUS's docs):
1. **`SIGHUP` does NOT open new listen sockets.** Tested directly: added a test `listen{}` to a running
   container's config, sent `kill -HUP 1`, confirmed via the server's own log ("HUP - Re-reading
   configuration files") and `netstat` that modules/virtual-servers reloaded but the new port stayed
   closed. This means adding a NAS requires an actual `radiusd` **restart**, not a reload — `docker/
   freeradius/entrypoint.sh` now replaces the base image's plain `exec radiusd -f` with a supervisor: starts
   `radiusd -f` as a background child, polls a content-hash of the listen/clients directories every 3s, and
   restarts the child (not the whole container) on any change. Measured real restart latency: well under 1
   second (log timestamps: process exit and "Ready to process requests" landed in the same wall-clock
   second) — brief enough that other NAS mid-flight just see one dropped UDP packet and retransmit, no
   forced session drop.
2. **FreeRADIUS refuses to start with a world-writable `$INCLUDE`'d directory** ("Directory ... is globally
   writable. Refusing to start due to insecure configuration") — its own security hardening, not a bug.
   Unlike `vpn_pki`/`vpn_wg_data`/`vpn_l2tp_secrets` (all `0777`), `freeradius_nas_config` is `chgrp 82
   (www-data) + chmod 0770` instead — `radiusd` itself runs as root the whole time (no `user=`/`group=` in
   `radiusd.conf`), so root always has access regardless of group, and `www-data` (boss-app) is gid 82.
3. **Port range collision with FreeRADIUS's own stock `inner-tunnel` listener.** The allocator's first
   chosen starting port (18120) collided with `raddb/sites-enabled/inner-tunnel`'s own untouched default
   `listen { ipaddr = 127.0.0.1; port = 18120 }` (used for internal EAP testing) — radiusd refused to
   rebind ("Address in use") on the very first real `sync()`. Moved the range to start at 20000 instead
   (confirmed clear via `grep -rn "port = [0-9]" raddb/` across the whole tree — the only other hardcoded
   values are 1812/1813 and this one).
4. **A stray root-owned file can permanently block a real write.** `docker compose exec boss-app php artisan
   tinker` (used constantly for verification throughout this sprint, same as every prior one) always runs
   as root — any file it writes into the shared volume lands `root:root 0644`, which `www-data` can then
   never overwrite again. This is the exact same bug class as the OpenVPN PKI "nas-11" incident from v0.6.3,
   reproduced fresh here: a real NAS "Simpan" in the browser 500'd with `file_put_contents(): Permission
   denied` the first time this was tested end-to-end, from files my own tinker sessions had left behind.
   Fixed the same way as before — `entrypoint.sh`'s poll loop now re-applies `chgrp 82 + chmod 0770` on
   every single cycle (every ~3s), not just at container boot, so a stray root-owned file self-heals
   quickly instead of wedging the next real save permanently.
5. **A colliding/bad config can leave `radiusd` dead until the next unrelated change.** Discovered for
   real: an orphaned `nas-11.conf` (leftover from a raw `migrate:fresh` DB wipe that bypassed `NasService::
   delete()`'s cleanup — see below) claimed the same port a freshly-created NAS also got allocated. The
   restart triggered by the new NAS's config change made `radiusd` exit immediately on the port collision,
   and the supervisor loop had no logic to notice — the container was left with **zero** `radiusd` process
   running, while the Status-Server healthcheck kept reporting stale "healthy" for up to one more interval
   (masking the outage). Fixed: the loop now also checks `kill -0 $RADIUSD_PID` every cycle independent of
   config-change detection, and restarts if the child isn't alive — a crash/collision self-heals within one
   poll cycle once the bad file is fixed/removed, instead of staying down indefinitely.

**Port allocator (`App\Services\Network\NasPortAllocatorService`)**: a singleton counter row
(`nas_port_allocator_state`, id=1, `lockForUpdate()` inside a transaction — same portable-to-sqlite pattern
as `payment_gateway_settings`, deliberately not a Postgres-only advisory lock) hands out `(auth_port,
acct_port)` pairs stepped by 10 starting at 20000, never reclaimed. **`coa_port` is deliberately NOT part of
this allocation** — an initial design mistake caught and fixed *before* CoaService was ever built, by
checking a real router's `/radius/incoming/print` first rather than assuming symmetry with auth/acct: unlike
authentication-port/accounting-port, RouterOS's CoA listener port is a single **router-wide** setting, not
tied to a specific `/radius` (server) entry — there's no FreeRADIUS-side collision to avoid the way auth/acct
have (many NAS sharing one FreeRADIUS, indistinguishable by source IP), so forcing `coa_port` unique across
NAS would have been meaningless busywork. It stays a plain, non-unique, admin-editable column (default 3799,
RFC 5176) — see `NasPortAllocatorService`'s own docblock for the full reasoning.

**Script Generator RADIUS tab**: `MikrotikScriptGenerator::radiusScript()` now emits `authentication-port=
{$nas->auth_port}`/`accounting-port={$nas->acct_port}` for real, not the old shared 1812/1813. **Real bug
found pushing this to test-x86-bajastu for the first time**: the generated `/user group add ... policy=`
line included `!dude` — a RouterOS 6.x-era policy keyword removed in 7.x. RouterOS rejects the ENTIRE
`policy=` string the instant one token doesn't match a known keyword ("input does not match any value of
policy"), which read identically to a genuine permission error and was briefly misdiagnosed as one (a
*separate*, real permission issue — the API user's restricted `boss-api-readonly` group correctly can't
`/import` — happened to be encountered first and looked the same). Isolated by testing the exact same
fetch+import mechanism with a trivial script first, which succeeded once the user actually had full access,
proving the failure was in the script content, not the credential. Fixed by dropping `!dude` and adding
`!rest-api` (a real 7.x keyword, kept denied for consistency). **Verified for real, full round trip**: the
fixed script applied cleanly via `/tool fetch` + `/import`, the router's own `/radius/print` showed the new
entry with the NAS's real ports, and a genuine `radclient` `Access-Accept` (not just Reject) round-tripped
through that exact port using a real `radcheck` row.

**CoA/Disconnect (`App\Services\Network\CoaService`, `POST /nas/{nas}/disconnect`)**: sends RFC 5176
Disconnect-Request/CoA-Request — the OPPOSITE direction from auth/acct (BOSS App is the Dynamic
Authorization *Client*, the NAS's own RouterOS `/radius incoming` is the *server*). Targets the NAS's most
recent active OpenVPN/WireGuard `vpn_account.internal_ip` — L2TP/IPsec deliberately excluded (existing known
limitation, ESP never wraps its traffic). **Why the actual `radclient` call has to run inside the
`freeradius` container, not `boss-app`**: confirmed against the real router that RouterOS's `/radius
incoming` validates an incoming CoA packet against the `address=` of a matching `/radius` client entry —
every NAS's own `/radius add address=...` (the RADIUS script above) is configured with
`FREERADIUS_INTERNAL_IP` specifically, which is `freeradius`'s own real static IP, not `boss-app`'s. Since
`boss-app` has no Docker exec access to another container (same stance as every other cross-container
coordination in this codebase), `CoaService` hands off via the same shared-volume-plus-poll pattern already
used for NAS config (a `coa-queue/*.json` request file, picked up within ~3s by `entrypoint.sh`'s loop,
which invokes the new `coa-worker.sh` and writes a `*.result.json` back — `CoaService` polls for it, up to
15s, `jq` used on both ends for safe JSON handling since secrets/usernames could contain characters a
hand-rolled parser would mangle).

**Firewall exception (explicitly confirmed with Agung before implementing, since it changes a security
boundary locked in at v0.6.2/v0.6.3)**: one narrow rule added to `docker/openvpn/entrypoint.sh` and
`docker/wireguard/entrypoint.sh` — `iptables -A FORWARD -i eth0 -o tun0/wg0 -s $FREERADIUS_INTERNAL_IP -j
ACCEPT`, allowing NEW connections sourced ONLY from FreeRADIUS's own IP, outbound through the tunnel. No
MASQUERADE needed for this direction — since `freeradius`'s real IP already IS the address every NAS expects
its RADIUS server at, the packet needs no translation. The `freeradius` container also needs `NET_ADMIN` +
routes to each protocol's tunnel subnet (`docker-compose.yml`), resolved by container NAME (`openvpn`/
`wireguard`, not a hardcoded IP — those containers aren't pinned the way `freeradius`'s own IP is) and
refreshed every ~3s in the same supervisor loop for self-healing across container recreation.

**Verified for real, up to a clear, honest limit**: `tcpdump` on the OpenVPN container's `tun0` confirmed
Disconnect-Request packets genuinely transit end-to-end — correct source (`172.28.0.10`), correct
destination (the NAS's real `internal_ip` on a genuinely-connected tunnel, confirmed via the OpenVPN
server's own log: `MULTI_sva: pool returned IPv4=172.23.194.2`), correct port. The full queue/poll plumbing,
routing, and firewall exception are all confirmed working. **What is NOT confirmed**: whether RouterOS
actually *acts* on the Disconnect-Request (no ACK/NAK was observed for a deliberately-nonexistent test
username, and disconnecting one of the router's 427 real active customer sessions just to observe the
effect was explicitly decided against — Agung chose to defer full confirmation rather than risk a real
customer's connection, same call already made once before for the WhatsApp QR-scan gap and the L2TP
RouterOS retest). Tracked as a pending verification item, not a bug — needs either a real PPP session
through this exact tunnel or Agung's own controlled test.

**Known, accepted limitation for the v0.6.4 multi-node pool**: the reverse route only reaches the POOL
OWNER node (`vpn-node-1`) for each protocol's subnet — if a NAS has actually failed over to a sibling node
(v0.6.4 auto-switch) at the exact moment CoA is sent, delivery can fail even though the account row is
perfectly valid (WireGuard specifically has no way to send data to a peer that particular daemon has never
itself handshaked with). Not solved this sprint — flagged as backlog for a smarter multi-node-aware CoA
router, consistent with how every other multi-node edge case in this cluster has been handled (documented
gap, not silently pretended away).

**Real bug found and fixed in existing (pre-v0.6.5) code while verifying this sprint**: `App\Livewire\
Network\NasIndex::testConnection()` built its connectivity probe from the form's *currently-typed* password
(falling back to the stored one only if left blank — the established "masked field" convention), but on
success only ever persisted `status`/`last_ping_at`, never the password that actually worked. Consequence,
observed for real: testing with a different (correct) password than what was stored showed "online"
immediately, while the underlying `nas.api_password` silently stayed wrong — any *subsequent* use of the
stored credential (script generation, this same test again with a blank password field, a real reconnect)
would then fail again, looking exactly like the NAS "randomly went offline with nothing changed." This is
very likely the true root cause of an earlier session's "mysterious 154415 password" investigation, which
found no evidence of data loss but never explained the drift. Fixed: a successful test using a non-blank,
freshly-typed password now persists that `api_username`/`api_password` onto the NAS row too — proof it
works IS proof it's the real current credential.

**Operational note, not sprint scope but happened mid-sprint at Agung's explicit request**: `config('app.
timezone')` was hardcoded `'UTC'` in `config/app.php` despite root `.env`/`.env.example` already declaring
`APP_TIMEZONE=Asia/Jakarta` since some earlier point — classic "env declared but never actually wired"
class of bug, same as `APP_ENV`/`WHATSAPP_GATEWAY_URL` earlier in this file. Fixed to `env('APP_TIMEZONE',
'UTC')`. Because this changes what every stored timestamp means going forward (this app was pre-production
with no real customer data of its own — separate from `test-x86-bajastu`'s own real, unrelated PPPoE
customer base — so Agung explicitly authorized this), the fix was paired with a full `migrate:fresh` +
reseed (`RolesAndPermissionsSeeder`, `DemoUsersSeeder`, `WhatsappMessageTemplateSeeder`,
`PaymentGatewayChannelSeeder`, `VpnServersSeeder`) rather than trying to reconcile old UTC rows with new
Jakarta ones. **Real ordering bug caught during this reset**: `VpnServersSeeder` (v0.6.4) assumes node1's 3
rows already exist with the lowest `id` per protocol (`VpnServer::poolOwnerFor()` depends on this for
real — it's how the pool owner is chosen) — running the seeder before manually recreating node1 gave
node2/node3 the lowest ids instead, silently making a SIBLING node the pool owner. Caught by checking
`poolOwnerFor()`'s actual return value after reseeding, not assumed; fixed by clearing and recreating in the
correct order (node1 first, always).

**Amendment (found and fixed right after v0.6.5 was tagged, same sprint's own bug — not a new sprint)**:
Agung independently confirmed the exact same root cause suspected above — `generateRadiusScript()` really
was rotating `nas.api_username`/`api_password` on **every single call**, including a pure UI preview that
was never applied to the router, unconditionally. This is a distinct, worse-than-described violation: the
method wasn't just "read-only in spirit" — it actively wrote to the database as an undocumented side effect
of what looked like a getter. **Fixed properly, not patched**: `MikrotikScriptGenerator::radiusScript()` no
longer touches `/user`/`/user group` at all — it only emits the `/radius add` line now.
`VpnScriptService::generateRadiusScript()` is now verifiably read-only (regression test calls it 5x in a
row and asserts zero DB change; verified for real against `test-x86-bajastu` too — 5 consecutive calls,
`nas.api_password` unchanged, `testConnection()` still succeeded with no re-entry needed).

**Root confusion identified and fixed at the same time, per Agung's explicit design**: `nas.api_username`/
`api_password` used to hold the router OWNER's real, full-access admin credential, typed in manually — the
same column the buggy rotation above was corrupting, which is exactly why the blast radius of that bug was
so bad (a real admin login going stale, not just an internal service credential). Two credentials are now
explicitly separated:
- **Admin credential** — the NAS owner's real router login. Used exactly ONCE, in memory, for a single
  request — never persisted anywhere, never logged. Entered through its own dedicated modal ("Buat/Perbarui
  User API" in `/nas`, `App\Livewire\Network\NasIndex::provisionApiUser()`) and its own Form Request
  (`ProvisionNasApiUserRequest`) — deliberately never bound to the same fields as `nas.api_username`/
  `api_password`, so this exact class of mixup can't recur by accident.
- **API credential** (`nas.api_username`/`api_password`) — a dedicated, restricted-policy user BOSS App
  fully owns, created/updated by `App\Services\Network\NasApiUserProvisioningService::
  provisionWithAdminCredential()`. Username convention `boss-app-api-{nas_id}` (unique even if two NAS rows
  ever pointed at the same physical router); router-side group `boss-app-api`, policy `read,api,password,
  !local,!telnet,!ssh,!ftp,!reboot,!write,!policy,!test,!winbox,!web,!sniff,!sensitive,!romon,!rest-api` —
  note `password` IS allowed (a deliberately broader-than-pure-read-only tradeoff, confirmed explicitly with
  Agung) specifically so `NasApiUserProvisioningService::rotate()` can self-service future password
  rotations using the dedicated user's OWN current credential, without ever asking for the admin credential
  again. `rotate()` has no UI trigger yet this round (not asked for) but needs zero router-side changes to
  wire up later. Both `provisionWithAdminCredential()` and `rotate()` funnel through the SAME new
  `RouterOsGateway::provisionApiUser()` method (implemented via idempotent `/user/group/set-or-add` +
  `/user/set-or-add`, not remove+recreate — a remove+recreate would transiently invalidate a self-rotating
  user's own session mid-call).

**Verified for real, all 3 claims, against `test-x86-bajastu`**: `provisionWithAdminCredential()` called
with the already-full-access `boss-apps` user as the one-time admin credential → router's `/user/print`
confirmed a genuine new `boss-app-api-1` user in group `boss-app-api`; `/user/group/print` confirmed the
policy string landed exactly as designed (including the `!rest-api`/no-`!dude` fix); `nas.api_username`/
`api_password` in the database updated to that new credential; `NasService::testConnection()` immediately
succeeded using it with zero manual re-entry.

**Second amendment (found and fixed the same sprint, during real production RADIUS testing against
`test-x86-bajastu` — a genuine ~400-430 active PPPoE customer router, not a lab device)**: a real, ongoing
production incident traced through several wrong hypotheses before the actual root cause. `boss-app`'s
`/radius` entry was briefly placed FIRST in the router's fallback order to test it against real traffic; its
`/radius/monitor` counters showed an alarming ~61% timeout rate (`req=2889 acc=1119 rej=0 to=1770`), which
briefly looked like a genuine FreeRADIUS performance/capacity problem serious enough to risk delaying login
for every real customer on the router (RouterOS only falls over to the next `/radius` entry on
authentication TIMEOUT, not on reject — so a slow/timing-out first entry delays every single auth attempt
that hits it). **Immediate response, done first, no investigation**: the entry was disabled outright
(`disabled=true`, not just reordered) — the fastest, lowest-risk way to guarantee zero further impact on
real customers, verified by watching total `PPP Active` count stay stable (435→436) right after.

**Root-cause investigation, once safe**, ruled out FreeRADIUS performance entirely, in order:
1. `radius_db` is tiny (2 `radcheck` rows, 7 `radreply` rows, 13 `radpostauth` rows — only our own test
   accounts) — a slow query was never plausible once actually measured.
2. A direct `radclient` test against the per-NAS auth port, run from a source IP correctly inside the
   listener's own `clients{}` ACL (`172.28.0.0/24`), got **Access-Accept in under 1ms, 100/100 times** in a
   follow-up bulk benchmark. The auth path itself was never the problem.
3. Two of my own testing mistakes produced false leads before this was found: (a) testing from `127.0.0.1`
   inside the `freeradius` container itself got silently ignored (no reply at all) — not a bug, just the
   wrong source IP for that listener's ACL; (b) checking WireGuard tunnel health on the WRONG container
   (`wireguard`/node1, showing zero handshake ever) before realizing — from the router's own
   `current-endpoint-port` — that this NAS's v0.6.4 multi-node pool had actually placed it on
   `wireguard-node2`. On the correct node, the handshake was healthy and a `ping` sourced as `wireguard-
   node2`'s own address got 100% packet loss (RouterOS's iptables-equivalent only accepts inbound tunnel
   traffic sourced as `FREERADIUS_INTERNAL_IP` specifically, per the CoA firewall exception below) — but
   sourced correctly as `172.28.0.10` (`freeradius`'s own pinned IP), the same ping got a clean 6-13ms RTT,
   0% loss.
4. **The actual cause**: the router's `/radius` entry for `boss-app` had its `accounting-port` deliberately
   pointed at port 1 (nothing listening) earlier in this same investigation, specifically to make the router
   stop successfully collecting real customers' accounting data into `radacct` — but RouterOS broadcasts
   Accounting-Request to every matching `/radius` entry regardless of order or response (confirmed
   separately, earlier in this same investigation), so **every accounting Interim-Update from every one of
   the router's real active sessions was hitting a dead port and timing out, 100% of the time, for as long as
   the entry existed** — not just while it was first in order. This inflated `/radius/monitor`'s combined
   auth+accounting counters into what looked like a severe auth-path performance problem, when the auth path
   itself was never measurably slow.

**Fixed properly, not by re-enabling the black hole**: `docker/freeradius/entrypoint.sh` now also patches
`sites-enabled/default`'s single shared `accounting {}` section (idempotent, same `grep -q` guard pattern as
the existing `$INCLUDE` patches) to comment out `detail` (raw packet-to-disk logging) and `-sql` (the
`radacct` write) — FreeRADIUS still listens on the NAS's real accounting port and still sends a genuine,
fast `Accounting-Response` for every request (stock behavior once the `accounting {}` section completes
without an explicit reject), it just no longer persists anything customer-identifiable to disk or the
database, consistent with this sprint's established "don't collect data we don't need" posture (the same
reasoning that emptied `radacct` and deleted the raw detail files earlier in this investigation). Verified
directly: a raw `Accounting-Request` sent to the real port got a real `Accounting-Response` back, and
`radacct`'s row count stayed at 0 both before and after. The router's `/radius` entry's `accounting-port`
was corrected back to `20001` (the real, now-safe-to-use port) — done while the entry was still
`disabled=true`, so this had zero live effect at the time.

**Net result**: the `boss-app` `/radius` entry remains `disabled=true` (second/inactive position) as of this
writing — reordering it back to first position is a separate decision requiring its own explicit
confirmation, not an automatic next step now that the accounting black-hole is fixed. The original ~61%
timeout figure should NOT be read as "FreeRADIUS can only handle 39% of production auth load" — the
evidence now points to the auth path being fully healthy, with the timeout rate almost entirely explained by
the accounting-port=1 self-inflicted black hole (plus this session's own repeated manual `radclient`/tinker
testing traffic, which shares the same cumulative `/radius/monitor` counters).

**Third amendment — the real root cause, found via a from-scratch systematic Level-1-through-6 retest (still
v0.6.5, before tag)**: with the accounting black hole fixed, boss-app was re-enabled for a clean retest —
`radiusd -X` still showed **zero** `Received Access-Request` for `085166445368`, despite the router logging a
retry every ~90s. `tcpdump` at three points (WireGuard tunnel ingress, VPN node egress, `freeradius`'s own
interface) proved the real Access-Request genuinely arrived intact (matching destination MAC, valid UDP
checksum, RADIUS Code=1 confirmed byte-for-byte via hex dump) with zero kernel-level drops (`/proc/net/udp`,
`/proc/net/snmp`) — yet `radiusd` never logged receiving it, even in full debug mode. Root cause:
`radiusd.conf`'s stock default `require_message_authenticator = yes` (a BlastRADIUS mitigation) **silently
discards** any Access-Request lacking a Message-Authenticator attribute, before any request-level logging
happens at all. RouterOS's real PPP CHAP/MSCHAP Access-Requests do not include this attribute (confirmed from
the captured packet's own hex dump) — `radclient` (our test tool) always adds it automatically, which is why
every synthetic test this whole investigation succeeded while every real NAS attempt was silently dropped,
indistinguishable from an ordinary timeout to RouterOS (which only fails over to the next `/radius` entry on
timeout, never on an explicit reject) — this is also the real reason boss-app being first in order never
actually intervened in real customer traffic before now.

**Fixed per `radiusd.conf`'s own documented recommendation for this exact scenario**:
`require_message_authenticator = no` added inside `FreeradiusVirtualServerService`'s generated
`clients/nas-{id}.conf`, scoped to that one client block — never the global default. `"auto"` mode is
explicitly not an option here: the same documentation states auto-detection has no effect for a client
defined by a network/mask (this client is a `/24`, not a single IP).

**Executed with a deliberately layered-safe rollout, not fix-then-immediately-live**: boss-app disabled
first (PPP Active confirmed stable, 438 before/after) → fix applied → verified via a method that never
touched production traffic at all (a raw-socket PHP script constructing an Access-Request byte-for-byte
matching the real MikroTik packet shape — no Message-Authenticator, manual RFC 2865 PAP encryption — sent
directly to FreeRADIUS: correct password → real `Access-Accept`, wrong password → real `Access-Reject`,
proving the fix restores full auth logic, not a blanket bypass) → only then re-enabled at first position
again, monitored every 15s for a full 5 minutes (20 checks) with an armed auto-rollback (>5 drop from
baseline → immediate disable). Never triggered — PPP Active held at 437-438 the entire window,
`/radius/monitor`'s `rejects` climbed as expected (0→7, proof FreeRADIUS is now genuinely answering) while
`timeouts` never grew again.

**Verified for real, the first genuinely successful end-to-end result of this entire investigation**:
`085166445368` appeared in `/ppp/active/print` with `address=10.0.1.144` (a real IP from the `PPPOE-REMOTE`
pool), `uptime=5m3s`, `radius=true` — a real, stable PPPoE session authenticated through this FreeRADIUS
instance, not just a packet-level Access-Accept.

**Note for any future MikroTik NAS**: don't assume RouterOS always sends Message-Authenticator on PPP
CHAP/MSCHAP Access-Requests — some versions/configs don't. If a new NAS shows the same symptom (NAS keeps
retrying, `/radius/monitor` shows timeouts, but `radiusd -X` never once logs "Received Access-Request" for
it), check the raw packet bytes (`tcpdump`) for this attribute before assuming a performance or network
problem.

## Permanent test account — `085166445368` (do not delete, not test data to clean up)

`085166445368` (`radcheck`/`radreply` rows on `radius_db`, NAS `test-x86-bajastu`) is a **deliberate,
permanent QA fixture**, confirmed explicitly by Agung — not leftover test data from this investigation.
It's free/unbilled (Agung's own number, not a paying customer) and is meant to keep being used for RADIUS/VPN
testing across future sprints (later v0.6.x work, and any later version touching RADIUS/VPN/NAS). **Any
future cleanup script, audit, or Claude Code session that finds this account should skip it, not delete
it** — the stock `radcheck`/`radreply` schema (see `docker/freeradius/schema.sql`) has no
comment/description column to mark this on the row itself, so this note is the only record of that intent;
don't "clean it up" without checking here first. No `nas`/customer/subscription record exists for it in
`boss_db` — wiring `radcheck` accounts to real Laravel customer/billing records is out of scope for v0.6.5,
likely a future version's work, not something to backfill now just because this account exists.

## GenieACS Core & TR-069 CWMP proxying gotcha (v0.7.2)

**CPE devices dial `boss-nginx:7547`, which forwards to `genieacs-cwmp`** — see
`docker/nginx/stream.conf.d/genieacs-cwmp.conf`. This used to be a plain HTTP `proxy_pass` (a
`server { listen 7547; ... }` block under `docker/nginx/conf.d/`), which turned out to make Digest
auth (`cwmp.auth` config, e.g. `AUTH(Device.ManagementServer.Username, Device.ManagementServer.Password)`
or a fixed `AUTH("user","pass")`) **fail for every single device, unconditionally, regardless of
whether the credentials were correct** — found via a real, fully-instrumented investigation (`tcpdump`
capture of a real Huawei EG8141A5 ONT's TR-069 session, plus independently recomputing GenieACS's own
MD5 Digest-response algorithm from `bin/genieacs-cwmp` source to *prove* the captured credentials were
byte-correct before looking anywhere else).

**Root cause**: GenieACS binds a Digest challenge nonce to the specific inbound TCP socket object
(`As.get(e.httpRequest.socket)`, a `WeakMap` in `bin/genieacs-cwmp`) — not to the device, not to an
IP, not to any session ID in the request itself. A compliant CPE keeps ONE TCP connection open across
the challenge (401 + nonce) and the follow-up authenticated retry, which is exactly what standard
HTTP Digest auth (RFC 2617) assumes. But nginx's default `proxy_pass` (no `upstream {...keepalive...}`
configured) opens a **brand-new backend connection to `genieacs-cwmp` for every single proxied
request** — confirmed directly in the packet capture: the CPE's one TCP connection to nginx (source
port stayed constant) mapped to *two different* nginx→genieacs-cwmp backend connections (two different
source ports, the first one `FIN`'d immediately after the 401 response). The nonce issued on backend
connection #1 was never found via `As.get()` on backend connection #2 → immediate rejection, before
`AUTH()` is even evaluated. This is invisible from the credential side entirely — every device we
tested failed the exact same way regardless of vendor/OUI, and regardless of whether `cwmp.auth` was a
fixed pair or the dynamic `Device.ManagementServer.*` form.

**Fixed by switching to a raw TCP passthrough** (nginx `stream {}` module, not `http {}`) —
`docker/nginx/stream.conf.d/genieacs-cwmp.conf`:
```
server {
    listen 7547;
    proxy_pass genieacs-cwmp:7547;
}
```
`stream {}` must be a **top-level block in `nginx.conf`, sibling to `http {}`** — it cannot live inside
`docker/nginx/conf.d/*.conf`, because that directory is `include`'d from *inside* the `http {}` block.
`docker-compose.yml`'s `boss-nginx` service mounts a new `./docker/nginx/stream.conf.d` directory
alongside the existing `conf.d` mount for this reason. The old HTTP-level
`docker/nginx/conf.d/genieacs-cwmp.conf` was deleted outright (not just disabled) — two configs can't
both `listen 7547` in different contexts at once. **This does not violate the "boss-nginx is the only
public entry point for CPE traffic" decision** — nginx is still the sole listener on 7547 from the
internet's perspective; only the proxying *layer* changed from L7 (HTTP) to L4 (TCP), so a raw TCP
connection now maps 1:1 to one CPE session for its whole lifetime, matching what GenieACS's nonce
binding assumes. Confirmed the fix is real HTTP passthrough, not just an open port: a raw
`curl -X POST http://<host>:7547/` returns GenieACS's own native `400 Bad Request` / `"Invalid session"`
body, not an nginx error page.

**One side effect of the switch**: `access_log`/`error_log` directives from the old HTTP-level config
(`genieacs-cwmp.access.log`/`.error.log`) are gone — the `stream {}` block doesn't have one configured.
`genieacs-cwmp`'s own structured log (`docker compose logs genieacs-cwmp`) is the sole source of truth
for CWMP traffic now, and is more informative anyway (device ID + exact failure reason per line, vs.
nginx's bare status code).

**Verified for real, end-to-end**: after the fix, a real Huawei EG8141A5 ONT (`00259E-EG8141A5-...`)
rebooted by Agung completed Bootstrap Inform cleanly on its very first attempt post-fix — zero
"Authentication failure" lines in the log for that session (every attempt before the fix, across
several different real ONTs/OUIs, had logged at least one). Full parameter tree
(`InternetGatewayDevice.*`) was retrieved and the device landed in GenieACS's `devices` collection with
a genuine `_registered` timestamp. **A GenieACS-side successful connect is not the same as the device
appearing in BOSS App's own "Perangkat CPE" UI** — that side additionally needs `CpeBindingService` to
match the reported serial number against a real `work_order_devices` row from the Installation module
(v0.5.0); a test device with no real work order behind it will show up in GenieACS but stay absent from
BOSS App's UI by design, not a bug.

## GenieACS Vendor Parameter Mapping (v0.7.2)

**`cpe_parameter_maps`** (platform-level, keyed by `oui`+`product_class`+
`parameter_key` — never tenant-scoped, same posture as
`payment_gateway_channels`) maps a vendor/model's own TR-069 parameter path
to a real-world value via `App\Services\Network\ParameterConversionService`
(`raw`/`linear`/`sff8472_optical_log10`) — resolved for a real device by
`App\Services\Network\CpeParameterResolverService`, which matches
`_deviceId._OUI`/`_deviceId._ProductClass` from
`GenieAcsClientService::findDeviceById()` against the catalog. A row only
carries `verified_at`/`verified_against_device_id` once genuinely checked
against real hardware (via `POST /cpe-parameter-maps/{id}/verify` or the
Livewire "Tes Resolve" panel's "Tandai Terverifikasi" button) — editing a
row's definition (path/formula/params) demotes it back to unverified rather
than silently keeping a stale verification timestamp attached to now-
different data.

**`sff8472_optical_log10` formula, verified for real against a live ZTE
F663NV3.1** (`F86CE1-F663NV3a-ZICG296C2E7B`) — its optical DDM object
(`InternetGatewayDevice.WANDevice.1.X_CT-COM_GponInterfaceConfig`) exposes
standard SFF-8472 fields (`BiasCurrent`/`RXPower`/`TXPower`/
`SupplyVottage`/`TransceiverTemperature`). `raw` is a linear power reading
in `scale` mW units (e.g. `scale=0.0001` for 0.1 µW steps); the formula is
`dBm = 10*log10(raw*scale)`. Confirmed correct by converting **all four**
numeric DDM fields at once under the same scale family, not just RXPower in
isolation — `SupplyVottage`(32830)→3.283V, `BiasCurrent`(8150)→16.3mA,
`TransceiverTemperature`(14668)→57.3°C all landed on textbook-normal
real-world readings, and `TXPower`(17100)→2.33dBm sits squarely in normal
GPON TX range — `RXPower`(15)→**-28.24dBm** (real reading of a live weak-ish
link, not a clean round number — itself further evidence this is a genuine
measurement, not a coincidental formula match). **Raw value 0 is rejected**
(`InvalidArgumentException` in `ParameterConversionService`), not silently
converted to `-INF` — a 0 reading means "no optical signal at all", not "0
dBm".

**Full parameter tree discovery needs a `refreshObject` task, not just
"the device connected"**: `db.presets`/`db.provisions` are empty in this
GenieACS instance, so a device's stored tree only ever contains what it
volunteered in its own Bootstrap Inform (a handful of `DeviceInfo`/
`ManagementServer` fields) — nothing pulls the rest automatically. A
`refreshObject` task with `objectName:""` (root) queued via NBI
(`POST /devices/{id}/tasks?connection_request`) executes on the device's
*next* Inform if `connection_request` itself fails (very likely — see below)
— but can itself fault with `too_many_commits` (`MAX_COMMIT_ITERATIONS`,
default 32) on a device with a genuinely large tree, requiring the task to
retry across multiple Inform sessions before the full tree lands. This is
exactly how the ZTE optical DDM object above was actually discovered — took
2 retried sessions before `X_CT-COM_GponInterfaceConfig` showed up.

**Two discovery items, NOT delivered this sprint — real follow-up work,
not solved by more time on this same approach**:

1. **400+ existing modem backfill (separate initiative, not v0.7.x)**:
   `customers` has **no legacy-system customer ID column at all**
   (`legacy_id`/`old_system_id`/`external_ref`/etc. — checked directly
   against migrations, live schema, and `Customer::$fillable`, all three
   confirm nothing) — a new column is needed before ID-based matching to
   the old system can work. Separately, MAC address is **not** stored in a
   GenieACS device's parameter tree unless a preset explicitly pulls it (see
   above) — a MAC→SerialNumber cross-reference strategy built from
   GenieACS's own data only works for devices whose relevant preset is
   already in place, not simply "any device that's ever connected."
2. **Connection Request / on-demand refresh, needed for v0.7.3**:
   investigated in full — genuinely not reachable yet, but **not for the
   reason first suspected**. **Amendment**: the claim below that
   `test-x86-bajastu`'s WireGuard tunnel "never actually handshaked" was
   **wrong** — caused by checking the wrong pool node (`wireguard`/node-1
   in the `wg show` command, when this NAS's `vpn_accounts` row is actually
   assigned to `vpn-node-2`). Checked again on the correct node: a live,
   active handshake with real bidirectional traffic. The real blocker is
   `AllowedIPs` — WireGuard's own cryptokey routing (enforced before
   iptables even runs) locks both ends of the tunnel to `172.28.0.10/32`
   (FreeRADIUS) only; nothing else can cross it regardless of iptables
   rules. The hub-and-spoke firewall locked in since v0.6.2 also only
   allows that same one `/32` destination, by deliberate security design —
   not an oversight to patch, but this second layer too needs widening.
   **Also corrected**: there is no separate "ZTE network" — a direct
   RouterOS API check against `test-x86-bajastu` (its API listens on a
   custom port, `49198`, not the RouterOS default 8728 — the earlier
   "unreachable" read was from testing the wrong port) showed the Huawei
   ONT (`10.1.12.87`) and the ZTE ONT (`10.1.13.229`) are **both active
   DHCP leases from the exact same DHCP server** (`dhcp2`, interface
   `vlan9-TR069`, pool `10.1.0.0/20`) on this one router — one NAS, one
   management subnet, not two locations. v0.7.3 (implementation, see
   CHANGELOG) resolves this by widening `AllowedIPs` on both ends to
   include `10.1.0.0/20`, adding `nas.tr069_management_subnet`, and a new
   firewall exception scoped to that subnet — not a new tunnel/location.

## GenieACS Connection Request Routing (v0.7.3) — implementation done, end-to-end verification PENDING

**Amendment (v0.7.7, merged/tagged) — VERIFIED, this section's "PENDING"
title is now stale**: the retest this section calls for below was finally
run for real. Short version — it works; see "GenieACS Testing Refinements &
Status Sync Redesign (v0.7.7)" near the end of this file for the full
account, including a real false lead this same investigation produced and
had to walk back (don't re-add a route to a WireGuard node's tunnel
gateway IP without new evidence — see that section). The two specific
device IDs cited below (`6a7897028f1edd3ee0656c81`/
`6a789984a542ad1c34df1865`) turned out to be stale/non-existent — GenieACS
device `_id`s in this fleet all follow the `OUI-ProductClass-Serial`
format, never that hex-string shape; don't chase those exact IDs again.

**Honest status, don't upgrade this to "done" without rechecking first**: the
network plumbing below is implemented and unit/feature-tested, and three real
infrastructure bugs were found and fixed while building it — but the actual
retry of a real Connection Request against real hardware, after the third
(and most important) fix, **has not been run and confirmed successful**.
Agung made a deliberate call to move on to v0.7.4 before that retest — this
section is not a claim that GenieACS can actually reach a CPE behind a NAS
yet, only that the pieces believed necessary are in place. **Before any
v0.7.4 work depends on this**, run `nc -zv` from the `genieacs-nbi` container
against a real CPE's TR-069 management IP (`10.1.12.87:7547` and
`10.1.13.229:58000` for the two devices already used to investigate this),
and retry the two long-queued `refreshObject` tasks (Huawei
`6a7897028f1edd3ee0656c81`, ZTE `6a789984a542ad1c34df1865`) via
`POST /devices/{id}/tasks?connection_request` against `genieacs-nbi`.

**What v0.7.2's own "not yet reachable" investigation got wrong, corrected
here (see the v0.7.2 section above for the original, now-superseded claims)**:
the WireGuard tunnel was never down (checking the wrong pool node caused that
false read), and there's no separate "ZTE network" (one NAS, one DHCP-served
management subnet, both ONTs on it). The real blocker was always `AllowedIPs`
— WireGuard's own cryptokey routing, enforced before RouterOS's firewall/
routing tables are even consulted — locked to `172.28.0.10/32` (FreeRADIUS)
only on both ends of the tunnel.

**What this sprint built**: `nas.tr069_management_subnet` (nullable string,
e.g. `10.1.0.0/20` for `test-x86-bajastu`), static `boss-network` IPs for
`genieacs-cwmp`/`genieacs-nbi` (`GENIEACS_CWMP_INTERNAL_IP`/
`GENIEACS_NBI_INTERNAL_IP`, same "must not drift on container recreation"
reasoning as `FREERADIUS_INTERNAL_IP`), a widened WireGuard `AllowedIPs` on
both server and router, and a new firewall exception in the shared
`docker/wireguard/entrypoint.sh` (applies identically on whichever pool node
a NAS's account currently lives on) scoped to `genieacs-cwmp`/`genieacs-nbi`'s
own pinned source IPs + the NAS's `tr069_management_subnet` as destination —
never a wider `boss-network`-to-anywhere allow.

**Three real bugs found and fixed while verifying this against
`test-x86-bajastu` for real, in the order they surfaced**:

1. **A revoked-and-reissued WireGuard keypair was mistaken for a dead
   tunnel.** "Cabut & Generate Ulang" (the only WireGuard code path that can
   ever produce a new keypair — see the v0.6.3 section above for why plain
   re-generation is blocked by design, the private key is never persisted)
   was used to apply the widened `AllowedIPs`, which necessarily revokes the
   old `vpn_accounts` row and issues a new one with a different public key.
   Checking `wg show` on the server confirmed: the OLD key had zero matching
   peer left (correctly revoked), the NEW key had zero handshake yet (script
   not applied to the router at that point) — both true simultaneously, and
   neither means "the tunnel mechanism is broken." Once the new script was
   actually applied, the new key's handshake came up fine and stayed live
   across repeated checks (traffic counters climbing each time, not stuck at
   a stale byte count).

2. **The first cut's reverse route was a single route to the NAS's WHOLE
   `tr069_management_subnet` — found dead on the real router.** That subnet
   IS the NAS's own local LAN, so RouterOS's connected route to it always
   wins over anything pointed at the tunnel; the static route was accepted
   with no error but never actually used for anything. Fixed by generalizing
   `MikrotikScriptGenerator::wireGuardScript()`'s route generation from a
   single hardcoded FreeRADIUS `/32` route into `$reverseRouteTargets`
   (`label => ip`), one `/ip route` + one `allowed-address` entry per
   internal service that actually *initiates* a connection toward a CPE
   behind the NAS (FreeRADIUS was already such a service since v0.6.2;
   GenieACS NBI/CWMP are the new ones for Connection Request) — never a
   whole-subnet route again. `VpnScriptService::reverseRouteTargets()` only
   adds the GenieACS entries when the NAS actually has
   `tr069_management_subnet` set; most NAS still only get FreeRADIUS.

3. **`MASQUERADE` vs `allowed-address` mismatch — found by inspecting the
   live `wireguard-node3` container's own `wg0`/`iptables` state directly**
   (`ip addr show wg0`, `iptables -t nat -L POSTROUTING -n -v`), not by more
   router-side testing. `docker/wireguard/entrypoint.sh`'s
   `TR069_MANAGEMENT_SUBNET` `MASQUERADE` rule
   (`POSTROUTING -o wg0 -d $TR069_MANAGEMENT_SUBNET -j MASQUERADE`) rewrites
   GenieACS's real container IP to the VPN node's OWN tunnel gateway address
   (confirmed live: `wireguard-node3`'s `wg0` has `172.23.195.1/24` — the
   reserved `.1`, see `App\Support\CidrRange::gatewayAddress()`, added this
   sprint) before the packet ever reaches the router. WireGuard's cryptokey
   routing checks a decrypted packet's SOURCE address against the peer's own
   `allowed-address` and drops anything that doesn't match — since neither
   FreeRADIUS's IP nor the (now-removed) whole management subnet ever
   covered `172.23.195.1`, the forward leg of a Connection Request would be
   silently dropped by WireGuard itself, before RouterOS's own
   firewall/routing tables ever see it, no matter how many `/ip route` lines
   exist. Fixed with a new `$vpnNodeTunnelIp` parameter on
   `wireGuardScript()`, added to `allowed-address` (not to the route list —
   the router only needs to ACCEPT packets sourced from it, never send
   anything TO it).

**This third fix is the one that has NOT been retested end-to-end** — it was
applied to `MikrotikScriptGenerator`/`VpnScriptService` and covered by new
unit/feature tests (all passing, full 350-test regression suite clean), but
the actual router-side `allowed-address` change it implies has not been
confirmed to make a real Connection Request succeed against
`test-x86-bajastu`'s real Huawei/ZTE ONTs. Don't assume it works just because
the code compiles and the tests pass — the tests prove the script now
*contains* the right lines, not that RouterOS/WireGuard actually behave the
way this section assumes once those lines are applied for real.

**Out of scope this sprint, unchanged from v0.7.2's framing**: the actual
remote-action features this routing exists to enable (reboot, SSID push)
were never this sprint's scope — this sprint is purely the network
prerequisite. **Amendment (v0.7.4)**: contrary to the last sentence above,
remote-action work started anyway, deliberately, without waiting for the
verification above — see the v0.7.4 section immediately below for why that
was a safe, correct call rather than skipping this sprint's own advice.

## GenieACS Remote Actions (v0.7.4)

Built deliberately in "not instant" mode, explicitly WITHOUT waiting for
v0.7.3's still-pending end-to-end verification above — see
`App\Services\Network\CpeActionService`'s own docblock for the full
reasoning. In short: every action writes a `cpe_action_logs` row (status
`queued`) before attempting anything, `GenieAcsClientService::sendTask()`
always tries GenieACS's `connection_request` too (harmless failure, free
win if v0.7.3 happens to already work for a given device), and a
`connection_request` failure is never treated as this module's own
failure — only a genuine enqueue failure (bad/missing `genieacs_device_id`,
no matching `cpe_parameter_maps` row, GenieACS itself rejecting the
request) is. Consequence worth remembering: **once v0.7.3's TODO is
actually confirmed, v0.7.4 becomes instant with zero code changes** — the
mechanism has been live since this sprint's first commit, it just hasn't
had a working Connection Request to actually ride on yet.

**TR-069 password fields read back empty by design — don't mistake this for
a missing mapping.** Confirmed on the same real ZTE F663NV3.1 already used
throughout the v0.7.x cluster: `WLANConfiguration.1.KeyPassphrase` and
`WLANConfiguration.1.PreSharedKey.1.PreSharedKey` are both present,
`_writable: true`, and both read as an empty string — while
`WLANConfiguration.1.SSID` on the exact same device reads a real value
(`'RUMAHVIA'`). This is standard CPE security behavior (many vendors never
echo a passphrase back on `GetParameterValues`), not evidence the path is
wrong or undiscovered. Because of this, `cpe_parameter_maps`'
`wifi_password` row for this device is deliberately left unverified
(`verified_at` null) — there's no real non-empty value to confirm against,
and no `setParameterValues` write against this exact path has been
confirmed to actually change a device's WiFi password yet. Flip it to
verified only once a real write is confirmed, not just because the path
looks structurally correct.

**Never store a real password in `cpe_action_logs.parameters`** —
`CpeActionService::setWifiCredentials()` stores `password_changed: true` +
`new_password_fingerprint` (a plain `hash('sha256', $password)`,
deliberately unsalted) instead. This is an audit fingerprint ("did this
change to the same value as an earlier entry?"), not a credential store —
the real credential only ever lives on the device/GenieACS, which is also
exactly why there's no meaningful "old_password" to log even if we wanted
to: the field above proves BOSS App never had it to begin with.

## GenieACS Connected Clients (v0.7.6)

**TR-069 instance numbers under a dynamic object are not stable or
sequential across devices — never treat `{n}` in a path like
`LANDevice.{i}.Hosts.Host.{n}` as an identity, only as a transient index.**
Confirmed on two real devices before `cpe_connected_hosts` was designed: a
live ZTE F663NV3.1 reported host instances `7/10/11/67/68`, a live Huawei
EG8141A5 reported `1/2` — vendor-assigned, arbitrary, no guarantee of
staying the same for the same physical client across polls. `mac_address`
is the only safe key for anything that needs to track "the same device
over time" from a dynamic TR-069 object — `App\Services\Network\
CpeConnectedHostsService::syncFromGenieAcs()` iterates whatever keys
`Hosts.Host` happens to have, keyed on `MACAddress`, never on the loop
index. Worth checking for the same gotcha before building any future
feature that walks another dynamic TR-069 array (WLANConfiguration
instances, WANConnectionDevice instances, etc.) — this is a real device
behavior, not specific to Hosts.

## GenieACS Testing Refinements & Status Sync Redesign (v0.7.7)

Merged to `develop`/`main` and tagged `v0.7.7` on 2026-08-20 (commit
`a90c3b4` on branch `v0.7.x-testing-refinements`, on top of the earlier
"wip" commit `fa6b0ca`; merge commits `a3bd380`/`b603053`). See
`docs/ROADMAP.md`'s own "v0.7.7 — GenieACS Testing Refinements" section for
the sprint-level summary; this section is the technical-gotcha detail for
future debugging, same split as every other `## GenieACS ...` section
above.

**v0.7.3 Connection Request is now genuinely verified, after a real false
lead along the way.** `nc -zv` from `genieacs-nbi` toward two specific CPE
IPs (the ones cited in the v0.7.3 section above) kept timing out — looked
like proof of a routing gap. It wasn't: those two IPs were stale (~2-week-old
DHCP leases nothing holds anymore), confirmed absent from all 220 devices'
*current* `ConnectionRequestURL` values pulled fresh from GenieACS. Retested
against real, currently-reported URLs: 5/5 ZTE F663NV3a succeeded
immediately; 8/8 Huawei EG8141A5 succeeded within 3 retries (first attempt
to a given IP occasionally timed out — likely ARP-cache-miss latency on the
router's own local segment, not a tunnel problem; every retry to an
already-tried IP succeeded instantly). A route to a WireGuard node's own
tunnel gateway IP (`$vpnNodeTunnelIp` in `MikrotikScriptGenerator::
wireGuardScript()`) was briefly added, reasoning by analogy from an
already-proven fact (allowed-address doesn't populate RouterOS's routing
table) — reverted same day once real testing showed Connection Request
succeeds with zero router-side changes beyond the existing allowed-address
entry. **Lesson: always confirm a CPE's CURRENT `ConnectionRequestURL` from
GenieACS itself before treating a timeout against a remembered IP as
evidence of anything** — see the method's own docblock for the full,
corrected account.

**`connection_request` via `GenieAcsClientService::sendTask()` is
asynchronous, not synchronous — its HTTP 200/202 status is NOT a reliable
same-request online/offline signal.** genieacs-nbi's own internal wait
(`CONNECTION_REQUEST_TIMEOUT`, default 2000ms, NOT overridable via a
request query param — confirmed by decompiling `bin/genieacs-nbi`'s own
config-key table) is far shorter than real observed CPE response latency.
Measured across 8 real devices, using the CPE's own `informEvent` containing
`"6 CONNECTION REQUEST"` as the only reliable proof the push (not a
coincidental periodic timer) caused the Inform: delays ranged **0.7s to
60.0s**. Worse: a device can be demonstrably online (steady periodic
Informs every 60s, confirmed over 3+ minutes) while its `connection_request`
**never once succeeds** — likely a per-device `cwmp.connectionRequestAuth`
credential mismatch, not a network problem. Treating `connection_request`
success as the sole online signal would misclassify such a device as
offline forever.

**Real genieacs-nbi bug: `getParameterValues` with an EMPTY
`parameterNames` array crashes the worker outright** —
`Error: Missing 'parameterNames' property`, confirmed live via
`docker compose logs genieacs-nbi`. The worker auto-respawns (PM2-style,
"Worker died" → "Worker listening" within seconds) so it's self-healing,
but every affected request fails until then. Never send an empty
`parameterNames` array to `sendTask()` — always a real, non-empty parameter
path (e.g. `{root}.DeviceInfo.SerialNumber`, a required TR-069 field
present on every vendor).

**`App\Services\Network\CpeDeviceStatusSyncService` was rebuilt from
scratch** — the real goal all along was boss-app checking CPE reachability
over its OWN tunnel path (boss-app → WireGuard → CPE), never delegating to
a customer's own router API (a dependency this product, meant to be sold to
multiple ISPs, can't scale on). Confirmed directly (read-only `ip route`/
`nc` checks from inside the real `boss-app` container) that boss-app itself
has **zero** route/firewall access to a NAS's TR-069 management subnet —
only `genieacs-cwmp`/`genieacs-nbi` have the v0.7.3 firewall exception.
Widening that to `boss-app` (new `NET_ADMIN` capability, new iptables rule,
new persistent route) was explicitly rejected in favor of reusing
`genieacs-nbi`'s already-working path instead — no new attack surface, same
mechanism `CpeActionService` already uses. Given the async-timing finding
above, the final design is a **hybrid, two-phase check**, not a simple
probe-and-wait:
1. A device whose GenieACS-reported `_lastInform` is already fresher than
   5 minutes is marked online directly — no probe sent at all. This means a
   device with steady periodic Informs is never at the mercy of a broken
   `connection_request` path for it specifically (closes the "online but
   connection_request never succeeds" gap above).
2. Only genuinely stale devices get an active probe: fire
   `connection_request` (via a real, non-empty `getParameterValues` target)
   for all of them, `Sleep::for(90)->seconds()` (Laravel's `Sleep` facade —
   `Sleep::fake()` in tests, no real 90s wait), then re-check `_lastInform`
   once more; a device whose `_lastInform` advanced past the moment the
   probe was sent is online, otherwise offline. 90s covers the 60s worst
   case measured above with margin.

`RouterOsGateway::pingHost()` (v0.6.1) is **not removed** — it's simply no
longer called from this service. Verified for real against the live fleet
(185 devices with a `genieacs_device_id`): online count went 119 → 159 in
one run (the old router-ping approach was under-counting real online
devices), and `nas.last_ping_at` for the vantage-point NAS stayed
completely unchanged across the run — zero ping traffic left the server,
confirming the architectural goal.

**Real-customer end-to-end verification of v0.7.4/v0.7.5, done deliberately
against production data (Agung's explicit call, with a documented revert
plan)**: customer Natofik (`085291591491`, `cpe_devices` serial
`ZICG298E1389`, ZTE F663NV3a) — original SSID `"DESA"` read and recorded
first. Two real gotchas hit along the way, both now fixed/documented so a
future rebind doesn't repeat them:
- **`cpe:auto-match-legacy-devices` (currently sped up to a 30s poll
  interval, see `docker-compose.yml`'s `boss-scheduler` comment — normally
  slower) races a deliberate unbind.** Deleting a `cpe_devices` row for a
  device that still exists in GenieACS with a matching serial gets
  auto-recreated within seconds by this scheduled job. A clean
  unbind-then-rebind (e.g. to exercise `CpeBindingService::
  bindFromWorkOrder()` fresh, avoiding its `genieacs_device_id` unique-
  constraint collision with an already-bound legacy row) must delete AND
  immediately rebind in the same script execution — not two separate steps
  with any real gap between them.
- **`work_orders.subscription_id` is NOT NULL** — there is no way to create
  a bare `WorkOrder` without a real `Subscription` row, even via direct
  Eloquent (`createFromSubscription()` really is the only path the schema
  allows). A customer with no subscription (this one, a legacy-MixRadius
  import) needs one created first. Made safe for a real customer by setting
  `status = SubscriptionStatus::Cancelled` (not `Active`) and
  `monthly_amount = 0` — confirmed directly from `GenerateDueInvoices`'s own
  query (`->where('status', SubscriptionStatus::Active->value)`) that only
  `Active` subscriptions are ever billed, so this combination is safe
  against the invoice-generation cron by two independent means, not just
  one.

Result: `cpe_action_logs` showed the exact expected auto-provisioning
signature (`performed_by: null`, `parameters.triggered_by:
"auto_provisioning_binding"`, `status: delivered`,
`new_password_fingerprint` — never the plaintext), SSID genuinely changed
on the device (confirmed via the next real periodic Inform, not just the
task's own "delivered" status), then reverted via the real "Ganti WiFi"
manual UI/API path (`performed_by` a real admin user id, no
`triggered_by` key — a visibly different signature from the automatic
path, useful for anyone reading the action-log history later) — also
confirmed via a real periodic Inform. All test-only scaffolding
(`Subscription`/`WorkOrder`/`WorkOrderDevice`) was deleted afterward;
`cpe_devices`'s binding to Natofik was deliberately kept (that's the
correct, wanted end state), and `cpe_action_logs` rows were deliberately
NOT deleted (permanent audit trail, not test scaffolding). **The test
password (`TestQA12345`) was never reverted** — TR-069 genuinely can't read
back a password to restore it, this is a known, accepted limitation, not an
oversight; Natofik needs to be told via CS/WhatsApp to set their own new
WiFi password.

**`docs/API.md`'s WorkOrder REST API is real and Sanctum-token-reachable
today, but has no technician-scoped auth yet** — checked while researching
what a future WhatsApp-bot-driven technician flow (see `v0.12.0` in
`docs/ROADMAP.md`) would need. Every `/api/v1/work-orders*` route sits
behind `auth:sanctum` + `reseller.context` (derived automatically from the
caller's own `reseller_users` membership, no extra header needed) — so an
external service CAN call it today with a valid token. But
`WorkOrderPolicy` only grants access via `work_orders.manage`/`.view`
(admin-wide) or an active `reseller_users` membership — the existing
`teknisi` Spatie role gets neither automatically (same gap already noted in
the Installation v0.5.0 section above: "a technician's own scoped access...
is new scope for a later sprint, not assumed here", still true). There's
also no "look up WorkOrder by device serial number" endpoint —
`index()` only filters by `status`. Both are real gaps to close as part of
`v0.12.0`, not something to design/build yet.

## Network Navigation Restructure & OLT Credential Registry (v0.8.1)

**Built as a same-branch addendum to the still-open `v0.8.1-librenms-install`
sprint** — the LibreNMS install/device-onboarding work itself (own MariaDB,
own token auth, 4 real devices) is further along in that same branch but not
yet documented here, pending the multi-hour resource-usage monitoring gate
its own DoD requires; a full `## LibreNMS ...` CLAUDE.md section lands once
that report is written, not before. This section covers only the addendum:
sidebar nav restructure + a new OLT credential registry, both merged into
the same unmerged/untagged branch, not split out.

**Sidebar nav restructure**: `resources/views/components/sidebar.blade.php`'s
`network` cluster gained an optional `children` key per top-level link — a
link with `children` renders as a chevron-toggle group (`x-data="{ subOpen:
... }"`, open/closed state persisted per-group in `localStorage` under
`sidebar-subgroup-{id}`) instead of a plain `<a>`; a link with no `children`
key still renders the old way, so this is additive, not a rewrite of every
nav item. Applied to: **NAS** (child: Script Generator), **OLT** (new
sibling top-level item, deliberately NOT nested under NAS — OLT hardware is
independent of any one NAS, an OLT registry entry merely references a NAS
as its access path), **Perangkat CPE** (child: Cek Status Device, listed
first in the group).

**New OLT Credential Registry** (`olt_manufacturers` → `olt_models` →
`olt_devices`, page `/olt-devices`, component `App\Livewire\Network\
OltDeviceIndex`): manufacturer/model master data (a model carries
`supported_pon_type` — `Gpon`/`Epon`/`GponEpon`, `App\Enums\OltPonType`) is
addable inline via quick-add modals on the same page, no separate CRUD
screens. `olt_devices` is tenant+reseller scoped (`BelongsToTenant`,
`BelongsToResellerScope` — same nullable-`reseller_id` "direct row" pattern
already used by `whatsapp_sessions`/`customers.reseller_id`/`nas`), and
requires both a `nas_id` FK and an `olt_model_id` FK, both
`restrictOnDelete()` (an OLT registry row is meaningless without an access
path or a model). `OltDevicePolicy` mirrors `NasPolicy` exactly:
`olt_devices.view`/`.manage` (super_admin, seeded in
`RolesAndPermissionsSeeder::seedOltDevicePermissions()`) for full access, or
an active `reseller_users` membership for a reseller's own OLTs.

**Credential encryption reuses `Nas`'s existing pattern exactly — no new
encryption mechanism was written for this module, per explicit instruction**.
`access_protocol` (`App\Enums\OltAccessProtocol`: `Telnet`/`Ssh` only —
**not** `Snmp`, see next paragraph) gates which of two CLI-admin credential
groups apply (telnet or ssh username+port+password); every secret-bearing
column (`telnet_password`, `ssh_password`, `snmp_ro_community`,
`snmp_rw_community`) is a plain `text` column with Eloquent's `'encrypted'`
cast, and all four are listed in `OltDevice::$hidden` so they never
serialize even for an authorized admin. `OltDeviceDatatableController`
additionally whitelists its JSON output columns explicitly
(`->only([...])`) — a second, independent layer against ever leaking a
credential through the list view, the same belt-and-suspenders posture as
`NasResource`'s `has_api_password`/`has_radius_secret`-boolean-only shape.

**SNMP is deliberately NOT one of the `access_protocol` choices (addendum
#2, same v0.8.1 branch) — a real UX bug from the first cut of this form is
why.** SNMP fields used to live inside the same per-protocol conditional
block as Telnet/SSH, mutually exclusive with them — so switching Access
Protocol from Telnet to SNMP (or vice versa) silently wiped whatever had
just been typed into the other block, because both shared the same "only
render the block matching the current selection" Blade logic. Root
modeling mistake: SNMP is a monitoring protocol independent of how an
admin logs into the OLT's CLI, not a third alternative to Telnet/SSH.
Fixed by giving SNMP its own always-on, unconditional form section
(`App\Livewire\Network\OltDeviceIndex`'s SNMP fields are no longer inside
any `if ($accessProtocol === ...)` branch, in the Blade view or in
`save()`'s validation/data-assignment), positioned above the Access
Protocol section — `snmp_version`/`snmp_port`/`snmp_ro_community` are
required on every OLT regardless of which CLI protocol it uses;
`snmp_rw_community` stays optional. `snmp_ro_community`'s "required" rule
only actually applies on **create** (`$this->editingOltDeviceId === null`)
— on edit it follows the same blank-means-unchanged masked-secret
convention as `telnet_password`/`ssh_password`, so re-saving an existing
OLT never forces re-typing a community string that's already stored.

**`create()` auto-generates both SNMP community strings the moment the
form opens** (`Str::password(16)`, Laravel's own secure-random-password
helper — no hand-rolled generator), pre-filling `snmpRoCommunity`/
`snmpRwCommunity` so they're never blank by default, matching the
SmartOLT-style reference UI Agung pointed to. Both stay fully editable — a
technician who already configured a specific community string manually on
the physical OLT can type over the generated one — and each has its own
"Regenerate" button (`regenerateSnmpRoCommunity()`/
`regenerateSnmpRwCommunity()`) to redraw a fresh random value on demand.
The community input fields render as plain `type="text"`, not
`type="password"` — unlike telnet/ssh passwords (which the admin already
knows and is typing in from memory), a generated community string is
useless if the technician can't read/copy it to go configure the same
value manually on the OLT itself (see the scope note below — this form
only **stores** the value, it never pushes it to the device). **No
existing `olt_devices` rows needed migrating when this changed** — checked
directly (`OltDevice::where('access_protocol', 'snmp')->count()`) before
making the change; the table was still empty, still awaiting Agung's real
credential entry.

**Explicitly out of scope, unchanged from the original addendum's
framing**: this module never pushes the SNMP community (or anything else)
to the OLT over Telnet/SSH — a technician must already have configured (or
will separately configure) the matching community string directly on the
device's own management interface. This form's only job is to record what
LibreNMS should poll with; automatic config-push is future OMCI-adjacent
scope, not attempted here.

**Test Connection pings the OLT's IP FROM the selected NAS, never directly
from boss-app** — `OltDeviceService::testConnection()` calls the exact same
`RouterOsGateway::pingHost()` interface `NasService::testConnection()`
already uses; no new router-connection code was written for this module.
Reasoning is identical to the `CpeDeviceStatusSyncService` redesign in the
v0.7.7 section above: under BOSS App's multi-tenant SaaS model, boss-app
itself has no network path to an ISP's internal OLT management LAN — only
that ISP's own NAS, reachable over the already-provisioned VPN tunnel, does.
`OltDeviceService::isPrivateIp()`
(`filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE)`) rejects
any public IP at save time — an OLT's management IP is by definition on a
private ISP-internal LAN, never directly internet-routable, so a public IP
here is always a data-entry mistake, not a hypothetical worth tolerating.
**No SSH/Telnet login-level test this sprint, ping-only** — Test Connection
proves L3 reachability from the NAS to the OLT, nothing about whether the
entered credentials actually authenticate; deliberately out of scope per
the sprint brief, noted here so it isn't mistaken for a stronger guarantee.

**Save is hard-blocked until Test Connection has passed for the exact
current `(nas_id, ip_address)` pair** — `OltDeviceIndex` tracks
`testPassedForKey = "{$nasId}|{$ipAddress}"`, set only by a successful
`testConnection()` call; the `updated(string $property)` lifecycle hook
invalidates it the instant either field changes afterward (re-typing the
IP, or switching the NAS dropdown), so a passed test can never be silently
carried over to a different pair by editing one field after the other.
Editing an existing row re-derives this gate from the persisted
`last_connection_test_result`/`last_connection_test_message` **only when
the persisted result is a success** — a persisted `failed` (or no test ever
recorded) leaves the gate closed and requires a fresh Test Connection
rather than half-trusting stale failure data. **Real bug caught by this
exact edge case while testing**: an early version of `edit()` set
`testPassedForKey` unconditionally for any row being edited, which crashed
`save()` (null array-offset access on `testConnectionResult`) the moment a
row with no in-memory test result was saved without re-testing — fixed by
only trusting a genuinely-persisted `success`, covered by
`OltDeviceIndexLivewireTest::
test_changing_ip_after_a_passed_test_invalidates_it_and_re_blocks_save` and
its sibling cases.

**3 real OLTs (ZTE C300, HSGQ-G02ID/GPON, HSGQ-E04ID/EPON) are entered by
Agung directly through this new UI, not seeded or guessed** — same
"don't guess real credentials" posture as every other real-hardware secret
in this codebase (Xendit keys, the WhatsApp HMAC secret, NAS/VPN PSKs). All
3 are now entered and Test-Connection-verified (ZTE C300 as
`c300.kaliwungu.bajastu.id`, HSGQ-E04ID as `HSGQ-E04ID-CILED`, HSGQ-G02ID
as `HSGQ-G02ID-BUMIREJA` — all via NAS `test-x86-bajastu`). **Attempting to
actually onboard them into LibreNMS surfaced a real, unrelated network
routing gap — see "LibreNMS OLT Onboarding — blocked on network routing,
not SNMP" below — this is NOT a Test Connection bug and NOT specific to
any one OLT/vendor.**

**Addendum #3 (same branch, 3 more real bugs found by Agung testing the
form in the browser) — a real Livewire+DataTables DOM-morph conflict, a
missing delete path for master data, and a plaintext-password toggle.**

1. **The OLT list silently stayed empty after every successful save, with
   zero console errors** — confirmed for real with a headless Playwright
   run against this dev server (login → edit an OLT → save → inspect the
   live `#olt-devices-table` DOM without any manual page reload): the
   `<tbody>` reverted to the pristine, empty, server-rendered Blade markup
   every time. Root cause: `OltDeviceIndex`'s create/edit form and its
   DataTables-driven list live in the SAME Livewire component, so every
   `save()`/`delete()` (which touches many public properties, not just the
   ones that changed) triggers a full Livewire DOM morph over the WHOLE
   component tree — including the `<table>` DataTables had already
   transformed client-side (wrapped in its own `.dataTables_wrapper`,
   populated with AJAX-fetched rows). Livewire's morph doesn't know
   DataTables did that; it reconciles the live DOM back toward the
   server's freshly-rendered HTML, which is always the pristine empty
   `<table><tbody></tbody></table>` from the Blade source — wiping
   DataTables' rows/wrapper regardless of whether `table.ajax.reload()`
   also fires via the existing `olt-device-saved` dispatch/`x-on:...window`
   listener (see the "New OLT Credential Registry" paragraph above — that
   plumbing was already correct, it just wasn't enough on its own). Fixed
   with `wire:ignore` on the list's outer `<div>` (Livewire's own
   documented answer for "a third-party JS library owns this subtree,
   never morph it") — confirmed fixed with the same Playwright script
   re-run after the fix: the renamed value appeared in the live table with
   no manual reload. This is a real, general gotcha for this codebase: **any
   future page combining a reactive Livewire form with a DataTables list in
   the SAME component needs `wire:ignore` on the DataTables container from
   the start** — `CpeDeviceIndex` (the only other DataTables+Livewire page)
   never hit this because it has no state-changing form/save() at all, not
   because it solved the problem.
2. **Manufacturer/Model master data had no delete path** — the existing
   "+" quick-add modals were extended into "manage" modals (same modal,
   now also lists existing rows with a `wire:confirm`-gated "Hapus" link,
   the exact same `text-red-600 hover:underline` + `wire:confirm="..."`
   pattern already used by `customer-show.blade.php`'s contact delete —
   reused, not reinvented). Referential integrity: `deleteModel()` checks
   `OltDevice::withoutGlobalScopes()->where('olt_model_id', $id)->count()`
   deliberately WITHOUT tenant/reseller scoping — `olt_models`/
   `olt_manufacturers` are platform-level master data (same posture as
   `payment_gateway_channels`), so "is this still in use" must mean across
   every tenant, never just what the acting admin's own session can see.
   `deleteManufacturer()` takes a deliberately simpler/stricter rule
   instead of relying on `olt_models`' DB-level `cascadeOnDelete()` (see
   that migration) chained through `olt_devices`' `restrictOnDelete()`: a
   manufacturer can only be deleted while it has ZERO models under it,
   full stop — no silent cascade-delete of models the user never explicitly
   asked to remove, matching the sprint's own "create+delete only, no more
   complex master-data CRUD" scope limit. Deleting a manufacturer/model
   that's currently selected in the open form clears that selection rather
   than leaving it pointing at a now-nonexistent id.
3. **`telnet_password`/`ssh_password` had no show/hide toggle** (unlike the
   SNMP community fields, which are plain `type="text"` by design — see
   above). Checked first for an existing toggle component to reuse (login
   form, other credential forms) — none exists anywhere in this app, so a
   small inline Alpine `x-data="{ showPw: false }"` + `:type="showPw ?
   'text' : 'password'"` toggle was added directly on both fields (a
   "Lihat"/"Sembunyikan" button, same `border-gray-300 hover:bg-gray-50`
   styling as the SNMP "Regenerate" buttons) rather than building a new
   reusable component for what is, so far, exactly two fields.

## LibreNMS OLT Onboarding — RESOLVED (v0.8.1)

**Status: DONE — all 3 OLTs onboarded, real polling data confirmed
(uptime, real GPON port names, `last_polled` advancing).** Getting here
took THREE separate, independently-real root causes stacked on top of
each other, found and fixed in this order — a genuine lesson in not
declaring victory after fixing just the first plausible-looking cause:

1. **Network routing** (fragment+reconcile / the OSPF experiment before
   it) — `librenms`/`librenms-dispatcher` had zero path to `10.168.100.
   0/24` at all. Fixed by the fragment+reconcile mechanism (see that
   section above) — proven correct via `ip route` content on the
   containers.
2. **Router FORWARD-chain firewall** — even with routing fixed, ICMP/TCP
   replies never made it back. A live, deliberately time-boxed test with
   Agung disabling the entire FORWARD filter chain proved this
   conclusively: ping to all 3 OLTs and a GenieACS Connection Request
   both succeeded for the FIRST TIME in this entire investigation the
   moment the firewall was off, then reverted immediately after
   confirming (never left disabled — a genuine root-cause proof, not a
   workaround). Strong suspect going in: FORWARD rule `drop connection-
   state=invalid` (8.7M+ hits, actively firing) — the router's own
   connection tracker apparently failing to correlate reply traffic back
   to its originating request for this specific path. **The actual
   permanent fix for this rule is NOT done yet** — Agung re-enabled the
   full firewall as-is after the diagnostic window; a real fix (an
   explicit accept for this traffic ahead of rule #8, or a connection-
   tracking-level correction) is still open, tracked separately from the
   SNMP-specific finding below.
3. **SNMP credentials — the actual final blocker, unrelated to either of
   the above.** Even with routing AND firewall no longer in question,
   `snmpget`/`snmpwalk` against all 3 OLTs kept failing. Root cause: the
   `olt_devices` registry (v0.8.1 addendum, "Network Navigation
   Restructure & OLT Credential Registry" section above) held
   **auto-generated random SNMP communities that were never actually
   applied to the real hardware** — these 3 OLTs had been configured with
   real, human-chosen community strings (`tokia121314`, confirmed
   identical across all three, port 161 — not the `2161` the registry
   had stored) LONG before this registry/form existed. Confirmed by
   Agung reading the two HSGQ units' own web UI directly; the ZTE C300's
   value was hypothesized (same ISP convention, same community, default
   port) and confirmed correct empirically — `snmpget`/`snmpwalk`
   succeeded immediately against all 3 once `olt_devices.snmp_ro_
   community`/`snmp_port` were corrected to match, returning genuine
   device data (`sysDescr`, real uptime, `sysName`, GPON port names).

**UX gap this surfaced, worth fixing but not yet actioned (observation
only, per explicit instruction not to change the form this round)**:
`OltDeviceIndex::create()` auto-generates `snmpRoCommunity`/
`snmpRwCommunity` via `Str::password(16)` the MOMENT the form opens (see
that section's own docblock above) — a genuinely good default for a
brand-new OLT whose community BOSS App gets to define first. But for an
OLT that's been running for months/years with an admin-chosen community
already configured on the physical device (exactly this case, 3-for-3
real OLTs), the auto-generated value fills the field **before** the admin
has a chance to type the real one in — nothing in the form visually
distinguishes "this is a fresh random suggestion, please confirm or
replace it" from "this is what I want" the way the password fields'
masked-blank-means-unchanged convention does on edit. All 3 real OLTs in
this registry were saved with the auto-generated value silently accepted
as-is, undetected until SNMP simply never worked. A future improvement
(not built here) might: default to blank instead of auto-generating on
create, or add explicit copy near the field clarifying it's a
suggestion, not a requirement.

---

**Original diagnosis record below (network-routing phase only) — left
intact as-is, superseded by the fuller 3-layer account above.**

**Status: NOT done, real infrastructure gap found, NOT worked around
without asking first.** The router (`test-x86-bajastu`, `ro-x86-
kaliwungu.bajastu.id`) was onboarded into LibreNMS earlier this sprint and
is genuinely polling real data (uptime, interfaces — confirmed via the
LibreNMS API's own `devices` response, `last_polled`/`uptime` fields
populated and advancing). Attempting the same for the 3 OLTs in the new
Credential Registry failed for all 3 — not because of anything wrong with
the registry or their credentials, but because **the `librenms` container
has zero network path to the OLTs' management subnet (`10.168.100.0/24`)
at all**, confirmed layer by layer, the same way the v0.7.3 GenieACS
Connection Request gap was originally diagnosed:

1. `docker compose exec librenms ip route` — only a route to
   `172.28.0.0/24` (boss-network) + a default gateway. Nothing toward
   `10.168.100.0/24`.
2. Direct proof: `snmpget -v2c -c <real-ro-community> 10.168.100.34:2161
   sysDescr.0` from inside the `librenms` container timed out — "No
   Response". Not a community/version/port mistake — the packet has
   nowhere to go.
3. `POST /api/v0/devices` against the real LibreNMS API (no `force_add`,
   so LibreNMS's own server-side reachability check ran for real) was
   asked to add the ZTE C300 with its real stored SNMP credentials and
   cleanly refused: `{"status":"error","message":"Could not ping
   10.168.100.34 (10.168.100.34)"}` — LibreNMS itself agrees, no device
   row was created (deliberately NOT retried with `force_add: true`,
   which would have created a permanently-unreachable "added" row —
   exactly the misleading state this investigation was trying to avoid).
4. Traced one hop further: `docker compose exec wireguard-node3 ip route`
   (the actual VPN tunnel node this NAS's active `vpn_accounts` row is
   currently on, per `VpnServer::poolOwnerFor()`-style lookup — protocol
   `wireguard`, `vpn_server_id=7`, `vpn-node-3`) shows a route to
   `10.1.0.0/20` (the TR-069/CPE subnet, provisioned for GenieACS in
   v0.7.3) but **no route to `10.168.100.0/24` at all** — this subnet was
   simply never provisioned for anyone, at the very first hop, let alone
   for LibreNMS specifically.

**Why `OltDeviceIndex`'s own Test Connection showing "Berhasil" for all 3
OLTs does NOT contradict this** — that check runs the ping FROM the NAS
itself (`RouterOsGateway::pingHost()`, a RouterOS API call the router
executes against its own attached LAN), never from boss-app's or
LibreNMS's own network position. The router can reach its own local
10.168.100.0/24 management VLAN trivially; that says nothing about
whether `librenms` (a container on `boss-network`, several hops and one
WireGuard tunnel away) can.

**Amendment — the router-side "sudah di-apply" claim didn't hold up under
verification, and the fix design changed as a result.** Before touching any
container config, the actual live router state was read directly via
RouterOS API (`/interface/wireguard/peers/print`, `/ip/route/print`) —
`allowed-address` and the reverse-route table were byte-for-byte identical
to the pre-existing v0.7.3 state (4 `/32`s, nothing OLT/LibreNMS-related).
The claimed manual Winbox edit had not actually landed. This, combined with
Agung independently flagging that a prior manual widening attempt left
stale entries behind (no exact-match idempotent cleanup for a model that
was about to change shape anyway), led to a locked architecture decision
(confirmed explicitly, not a unilateral choice): **replace the whole
one-`/32`-per-service model with a single reserved `/27` block**, migrating
FreeRADIUS/GenieACS-CWMP/GenieACS-NBI onto it at the same time as LibreNMS
— see "Infra Tunnel IP Block (v0.8.1)" below for the full design and
current implementation status.

**Status as of that redesign — Tahap 1-4 done and tested, Tahap 5 (the
actual router script) blocked on a real production-safety question, not
yet generated:**
- Tahap 1 (IP block choice) and Tahap 2 (docker-compose.yml/.env pinning)
  are done — see "Infra Tunnel IP Block" below for the address map.
- Tahap 3 (`MikrotikScriptGenerator::wireGuardScript()`/`VpnScriptService`
  rewritten for the block model, fully idempotent regen) is done, tested
  (full suite green), Pint-clean.
- Tahap 4 (`docker/wireguard/entrypoint.sh` + a new
  `docker/librenms/route-init.sh` for the OLT subnet route/firewall) is
  done, incremental — the existing `TR069_MANAGEMENT_SUBNET`/GenieACS
  block was verified untouched.
- Tahap 5 (generate the actual script for Agung to apply) surfaced a real
  blocker before any script could be produced: `test-x86-bajastu` already
  has an ACTIVE WireGuard `vpn_accounts` row for this NAS, and
  `VpnScriptService::generateVpnScript()` can only emit a fresh script for
  a `justProvisioned` account — WireGuard's private key is never persisted
  (see the v0.6.3 section above), so there is no way to re-emit a script
  for the CURRENTLY LIVE keypair. The only path to a new script is "Cabut &
  Generate Ulang" (revoke + reprovision), which necessarily drops and
  re-establishes this NAS's live tunnel — not a side-effect-free regen.
  **Not executed without explicit confirmation** — this is a materially
  bigger production action than "print a new script" and needed to be
  surfaced before proceeding, not discovered mid-cutover.
- A SECOND pre-existing drift was found while preparing `OLT_MANAGEMENT_GATEWAY`
  (needed regardless of the block redesign, for `docker/librenms/
  route-init.sh` and `docker/genieacs/entrypoint.sh`'s already-existing
  `TR069_MANAGEMENT_GATEWAY` mechanism): `.env`'s `TR069_MANAGEMENT_GATEWAY`
  is currently `172.28.0.11` (node1), set 2026-08-19 after an observed
  auto-switch — but a fresh live check (RouterOS API,
  `/interface/wireguard/peers/print`) done while writing this shows the
  router's REAL current peer at `endpoint-port=51822` (`vpn-node-3`,
  `172.28.0.5`), 27s-old handshake — i.e. it has since switched back to
  node3 and `TR069_MANAGEMENT_GATEWAY` was never updated to match. This
  means the GenieACS Connection Request path may ALREADY be broken right
  now, independent of any v0.8.1 work — flagged, not silently fixed (fixing
  it means recreating `genieacs-cwmp`/`genieacs-nbi`, explicitly off-limits
  without asking first this sprint). `OLT_MANAGEMENT_GATEWAY` was set from
  the fresh, correct value (`172.28.0.5`), not copied from the stale
  `TR069_MANAGEMENT_GATEWAY`.

All 3 OLTs stay registered in `olt_devices` (BOSS App's own credential
registry — correct, unaffected by any of this) but **not yet added to
LibreNMS at all** — zero misleading "added but unreachable" rows exist.

**Resource usage — still the 1-device baseline, not yet a real 4-device
comparison**: since no OLT was ever actually added to LibreNMS, `docker
stats` for `librenms`/`librenms-dispatcher`/`librenms-db`/`librenms-redis`
still reflects the router-only baseline: **~291MiB combined RAM**
(librenms 99.9MiB + dispatcher 69.2MiB + db 118.3MiB + redis 3.5MiB),
consistent with the earlier ~288MB single-device figure — i.e. no
meaningful drift, as expected, since nothing new is actually polling.
`librenms_data` volume 522.9MB, `librenms_db_data` 238.2MB. **The
4-device resource comparison this sprint's DoD wants is deferred until the
routing gap above is resolved and the 3 OLTs are actually polling** — this
number should NOT be read as "LibreNMS handles 4 devices for ~291MB", it's
still genuinely 1 device.

## Infra Tunnel IP Block (v0.8.1)

**Replaces the v0.6.2-v0.8.0 "one `/32` per service, hand-added to the
router" model with a single reserved `/27` block.** Through v0.7.7, every
service allowed to reach through a NAS's WireGuard tunnel (FreeRADIUS,
GenieACS CWMP/NBI) got its own individual `/32` in the router's
`allowed-address` — this worked but meant the router had to be manually
re-touched for every new module. LibreNMS's OLT onboarding was the change
that surfaced this as a real problem (a 5th manual router edit, on top of
3 that already existed) — see "LibreNMS OLT Onboarding" above for the full
diagnosis trail.

**DELIBERATE, CONSCIOUS security trade-off — confirmed explicitly with
Agung, not scope drift.** The v0.6.2 hub-and-spoke design intentionally
locked `allowed-address` to specific `/32`s ("FreeRADIUS selalu diakses di
SATU IP internal tetap dari sisi Mikrotik" — a locked architecture decision
at the time). This widens that to block-level (any of the 32 addresses in
the reserved range is now a trusted tunnel source) in exchange for a
genuinely modular product — an ISP can buy just GenieACS, or just
LibreNMS, without a router re-touch per combination, matching how this
product is meant to be sold.

**Block chosen: `172.28.0.224/27` (`.224`-`.255`)** — NOT the mathematically
"free" option of reusing `172.28.0.0/27` (which would have covered the 5
old/current addresses without any migration), deliberately rejected: that
range is already occupied by 23 unrelated non-tunnel containers
(boss-postgresql, mongo, boss-app, boss-worker, the VPN node containers
themselves, etc.) — widening `allowed-address` to include it would have
trusted all of them as tunnel sources, a real security regression far
beyond the agreed trade-off. `172.28.0.224/27` was empty (confirmed via
`docker network inspect` before picking it) and happens to end at `.255`,
the `/24`'s own broadcast address — doubly protected against ever being
assigned to a container (both by convention and by Docker itself refusing
it). `.224` (block base) is likewise left unused by convention.

| IP | Service | Env var |
|---|---|---|
| `172.28.0.224` | *(reserved — block base)* | — |
| `172.28.0.225` | FreeRADIUS | `FREERADIUS_INTERNAL_IP` (was `.10`) |
| `172.28.0.226` | GenieACS CWMP | `GENIEACS_CWMP_INTERNAL_IP` (was `.30`) |
| `172.28.0.227` | GenieACS NBI | `GENIEACS_NBI_INTERNAL_IP` (was `.31`) |
| `172.28.0.228` | LibreNMS (web/API) | `LIBRENMS_INTERNAL_IP` (new) |
| `172.28.0.229` | LibreNMS Dispatcher (the actual SNMP poller) | `LIBRENMS_DISPATCHER_INTERNAL_IP` (new) |
| `172.28.0.230`-`.254` | *(reserved, 25 free slots for future modules)* | — |
| `172.28.0.255` | *(reserved — `/24` broadcast, Docker-enforced)* | — |

`INFRA_TUNNEL_BLOCK_CIDR=172.28.0.224/27` is the new single source of
truth (`config('services.vpn.infra_block_cidr')`) — a brand-new module
just needs a free IP inside this block; the router's `allowed-address`
never needs touching again for it.

**`MikrotikScriptGenerator::wireGuardScript()` rewritten (v0.8.1)** — takes
`string $infraBlockCidr` instead of the old `array $reverseRouteTargets`.
`allowed-address` is now `{$infraBlockCidr}[,{$vpnNodeTunnelIp}/32]` (the
VPN node's own tunnel-gateway `/32`, used for the v0.7.3 MASQUERADE
mechanism, stays a separate parameter — it's a `172.23.x.x` tunnel address,
not a `boss-network` one, so it can't be folded into the block). The
per-service reverse-route loop collapsed into exactly ONE route
(`dst-address={block} gateway=boss-vpn-wireguard comment="boss-vpn-infra-block-route"`).
**Idempotent regen**: preceded by
`/ip route remove [find comment~"boss-vpn-.*-route"]` — a WILDCARD match
(not the old per-comment exact match), specifically so re-pasting the
script also sweeps up the 3 old per-service routes
(`boss-vpn-freeradius-route`/`-genieacs-nbi-route`/`-genieacs-cwmp-route`)
from a NAS still on the pre-v0.8.1 scheme, in the same run that adds the
new block route — no separate manual Winbox cleanup needed. **Behavioral
simplification that comes with this**: the old per-NAS conditional
inclusion of GenieACS NBI/CWMP (only added if `nas.tr069_management_subnet`
was set) is GONE — the block itself is now unconditional for every
WireGuard NAS; "which service is allowed to reach which NAS" moved fully
to the application layer (e.g. `tr069_management_subnet` still gates
whether GenieACS itself ever attempts a Connection Request), not the
VPN-layer allowlist.

**Gotcha found applying the FIRST real /27-block script to
test-x86-bajastu: `/ip address` had the exact same staleness bug the route
fix above already solved, just not yet applied to it** —
`/ip address remove [find interface="boss-vpn-wireguard"]` used to run
AFTER the interface-cleanup block earlier in the same script had already
destroyed and recreated the wireguard interface. RouterOS binds an
`/ip address` entry's `interface` property to the interface's internal
object id, not its display-name string — once that specific object is
deleted, the address entry shows as attached to interface `"unknown"`,
and a by-interface `find` silently matches nothing against it. Net effect,
confirmed from a real Winbox screenshot: an orphaned duplicate
"BOSS App - WAN VPN address NAS nas-1" entry, interface `unknown`, left
behind on every single regen. **General rule this establishes for this
whole class of generated script**: any `remove [find ...]` that targets an
object by referencing ANOTHER object this same script also removes/
recreates (interface name, in this case) is unreliable the moment that
other object gets recreated — comment-based `find` is the robust pattern,
since the comment string is a property of the object being removed itself,
not a reference to something else that might have changed underneath it.
Fixed the same way as the route: `/ip address remove [find comment~"BOSS
App - WAN VPN address"]`. Every other `[find ...]` removal in this
generator (`interfaceCleanupBlock()`'s 4 PPP-interface + wireguard removes,
`routingIsolationBlock()`'s mangle/route/table/rule removes,
`autoSwitchBlock()`'s script/scheduler removes, `openVpnScript()`'s
certificate/file cleanup, `radiusScript()`'s `/radius` remove) was audited
for the same pattern — none of the others reference an object this script
also recreates, so none had this bug.

**P0 found immediately after applying that same fix: the fix itself broke
the generated script** — the `.rsc` Agung downloaded and tried to `/import`
twice failed with "interrupted / expected end of command", and the file's
own content (confirmed from what he sent back) showed the interface
cleanup block duplicated, with un-`#`-prefixed prose sitting between the
two copies. Root cause: the comment explaining the fix above wrote
`` {$cleanup} `` **inside a `#`-prefixed prose sentence** ("...recreated by
{$cleanup} above...") intending it as plain English text — but this is
still inside the SAME PHP heredoc as the rest of the script, and a heredoc
interpolates every `{$variable}` exactly like a double-quoted string,
comment or not. `$cleanup` holds the ENTIRE multi-line
`interfaceCleanupBlock()` output (6 real, un-prefixed RouterOS commands) —
interpolating it mid-sentence spliced that whole block into the comment,
and the tail of the original sentence ("above (different internal object,
same...") ended up appended onto the LAST injected command's line,
producing exactly the malformed line RouterOS choked on. **Verified this
was the sole cause, not the download endpoint**: `VpnScriptDownloadController`/
`ScriptDownloadTokenService` were checked first and confirmed to be a pure
pass-through (`Cache::pull()` returns byte-for-byte what `store()` was
given, no concatenation anywhere) — reproducing the SAME generator call
directly (bypassing HTTP entirely) showed the identical corruption, proving
it originated in `MikrotikScriptGenerator::wireGuardScript()` itself.
Fixed by rewording the comment to avoid referencing `$cleanup` (or any
other variable holding multi-line script content) inside prose — audited
every other `{$variable}` interpolated inside a `#`-comment line across the
whole class and confirmed all the others (`$account->username`,
`$routerOsVersion`, `$nas->name`, `$nas->auth_port`/`acct_port`,
`$minPort`/`$maxPort`) are plain scalars, safe to interpolate anywhere.
**General rule for this whole class of generated script**: never
interpolate a variable that holds multi-line generated script content
(`$cleanup`, `$autoSwitch`, `$routing`, or anything else built from a
private helper returning a script fragment) inside a `#`-comment sentence
— only ever place it on its own line as an intentional full-block
insertion. `MikrotikScriptGeneratorTest`/`VpnScriptDownloadTest` both gained
a holistic validator (every non-continuation line must start with `#`/`:`/
`/`, no known cleanup line may appear more than once) run against all 4
script types AND across several simulated regenerations in a row, plus one
test going through the real HTTP download endpoint end-to-end — this class
of bug produced output where every individual `assertStringContainsString()`
still passed (the expected text was present, just ALSO duplicated), so a
holistic check was necessary to actually catch it, not just spot-checks.
**Confirmed no production impact**: read the router's live RADIUS monitor
directly (`/radius/monitor` for the `boss-radius` entry, `address=
172.28.0.10`) — `requests=0` across the board, meaning all 442 real active
PPPoE sessions on `test-x86-bajastu` are still authenticating through the
NAS's other (legacy "added by mixradius") RADIUS entry, not through BOSS
App's tunnel at all — so the broken WireGuard tunnel from the failed
imports never affected a real customer.
incrementally, `TR069_MANAGEMENT_SUBNET`/GenieACS untouched.** New,
independent optional pair `OLT_MANAGEMENT_SUBNET`/`OLT_MANAGEMENT_GATEWAY`
(same "one subnet only, static/manual" limitation as
`TR069_MANAGEMENT_SUBNET`/`TR069_MANAGEMENT_GATEWAY` — a SEPARATE pair, not
a reuse, since a NAS can have both a TR-069 subnet AND a different OLT
subnet at once, as `test-x86-bajastu` does). When set: `ip route replace
$OLT_MANAGEMENT_SUBNET dev wg0` + a FORWARD ACCEPT scoped to
`-s $INFRA_TUNNEL_BLOCK_CIDR -d $OLT_MANAGEMENT_SUBNET` (source is the
WHOLE block, not 2 individual `/32`s the way the GenieACS exception is —
the actual point of this redesign: a future block member doesn't need its
own entrypoint.sh edit either) + a matching MASQUERADE rule (same
mechanism as the TR069 one, rewriting the real source to this node's own
wg0 gateway before crossing the tunnel). **Known limitation inherited from
the same MASQUERADE mechanism**: the rewritten source is only accepted by
the router when `$vpnNodeTunnelIp` is in `allowed-address`, which currently
only happens when `nas.tr069_management_subnet` is set — a future NAS with
an OLT subnet and NO TR-069 subnet would need a real
`nas.olt_management_subnet` column to fix properly (not built this sprint,
out of scope for an incremental container-side change).

**`docker/librenms/route-init.sh` — new, for an official image with no
custom Dockerfile of BOSS App's own.** `librenms`/`librenms-dispatcher` use
`librenms/librenms:latest` directly (no `build:` in `docker-compose.yml`),
unlike `genieacs-cwmp`/`genieacs-nbi` (which already have their own
`docker/genieacs/entrypoint.sh` doing the exact same
`TR069_MANAGEMENT_GATEWAY` route trick). This new script is bind-mounted
in and set as `entrypoint: ["/route-init.sh"]` on both services — it adds
`ip route replace $OLT_MANAGEMENT_SUBNET via $OLT_MANAGEMENT_GATEWAY` (when
both are set) then `exec /init`, wrapping rather than replacing the
official image's own init. Both containers need it: `librenms-dispatcher`
for ongoing polling, `librenms` itself because `POST /api/v0/devices`'
add-time reachability check runs synchronously in that container, not
delegated to the dispatcher (confirmed for real — see "LibreNMS OLT
Onboarding" above).

**Cutover execution (Langkah 3-4), 3 more real bugs found and fixed doing
this for real, none of them in the code reviewed/tested up to that
point:**

1. **`wireguard` (node1) was never actually pinned, despite this file's
   own prior comment insisting it was.** Recreating `freeradius` alone
   wasn't enough — `wireguard`/`wireguard-node2`/`wireguard-node3`'s OWN
   iptables FORWARD/MASQUERADE rules are baked in at THEIR OWN entrypoint
   run, keyed off `FREERADIUS_INTERNAL_IP` at THAT container's boot time —
   so all 3 needed recreating too for the new `.225` to actually work.
   Recreating `wireguard` (node1) for this then immediately exposed a
   second, independent, longstanding bug: node1 had **no `ipv4_address`
   pinned at all** — `container_name: wireguard` only fixes the
   container's name, not its network IP — so it silently grabbed
   `172.28.0.4` (node2's own address) on recreate, breaking node2's own
   recreate right after ("Address already in use"). Fixed by adding
   `WIREGUARD_NODE1_INTERNAL_IP=172.28.0.11` + pinning it in
   `docker-compose.yml`, same pattern node2/node3 already had since
   v0.7.3. **A second, separate lesson from this same step**:
   `docker/wireguard/entrypoint.sh` is `COPY`'d into the image at
   **build** time (unlike `docker/librenms/route-init.sh`, which is
   bind-mounted) — a plain `docker compose up -d` recreate silently keeps
   running the OLD entrypoint.sh from before any of this sprint's code
   existed. `--build` is required for wireguard/node2/node3 specifically
   whenever `docker/wireguard/entrypoint.sh` itself changes, not just a
   recreate.

2. **`AllowedIPs` (the WireGuard peer fragment written by
   `VpnProvisioningService::provision()`) never got the OLT subnet added —
   only the router-side `/ip route`/iptables did.** AllowedIPs is
   WireGuard's OWN cryptokey-routing filter, checked by the KERNEL before
   a packet is even handed to iptables — a route/firewall rule alone is
   necessary but not sufficient. Confirmed for real: `ping -I wg0
   10.168.100.34` from `wireguard-node3` failed outright with `sendto:
   Required key not available` (a kernel-level WireGuard error, not a
   timeout) until this was fixed. Fixed by widening
   `VpnProvisioningService`'s `$allowedIps` construction with a new
   `services.vpn.olt_management_subnet` config key (from
   `OLT_MANAGEMENT_SUBNET`), same unconditional-per-account pattern as the
   existing `tr069_management_subnet` widening (no `nas.
   olt_management_subnet` column exists, so — same accepted
   single-global-subnet limitation as `OLT_MANAGEMENT_SUBNET`/
   `OLT_MANAGEMENT_GATEWAY` everywhere else). Required one more
   revoke-and-regenerate cycle for `test-x86-bajastu` specifically to
   apply (the already-active account's peer fragment predated the fix) —
   in hindsight this specific fix could have been applied by directly
   editing the existing peer fragment file in place (no new keypair/router
   script needed, since the fix is pure server-side AllowedIPs content) —
   noted here so a similar future fix doesn't default to a full
   revoke-regenerate when a direct fragment edit would be far less
   disruptive.

3. **Real production drift caught and corrected, not silently
   "fixed"**: `TR069_MANAGEMENT_GATEWAY` had been stale (pointing to node1
   from an observed 2026-08-19 auto-switch) while the router's WireGuard
   tunnel had since genuinely settled back onto a DIFFERENT node — the
   auto-switch scheduler was ACTIVELY cycling through all 3 nodes every
   ~90s throughout the early part of this cutover specifically BECAUSE its
   own health-check ping target (`FREERADIUS_INTERNAL_IP`) was
   unreachable until wireguard-node1/2/3 were rebuilt (see bug #1 above)
   — confirmed by watching the live endpoint-port bounce
   51822→51820→51821→51822 across repeated checks, then verifying it held
   steady across 3 separate checks afterward. `TR069_MANAGEMENT_GATEWAY`/
   `OLT_MANAGEMENT_GATEWAY` were both corrected to match the node it
   genuinely settled on (confirmed via 3 stable reads, not a single
   snapshot) before `genieacs-cwmp`/`genieacs-nbi`/`librenms`/
   `librenms-dispatcher` were recreated against it.

**Open item, NOT resolved as of this writing — a real router-side
forwarding gap distinct from anything above, found during Langkah 4
verification.** After all 3 fixes above, FreeRADIUS reachability through
the tunnel is fully proven end-to-end (a real RADIUS Access-Accept via
`172.28.0.225`, from a router-initiated `/ping`). But server-INITIATED
traffic toward the router's own LAN (both the OLT SNMP path AND a GenieACS
Connection Request retest to several real, currently-fresh CPE) transmits
successfully — confirmed via the router's own PER-PEER `rx` byte counter
increasing by the exact expected amount for a 5-packet ping burst — but
gets **zero reply**, for every destination tried (OLT IPs and multiple
different CPE IPs, several device models). The router's own direct
`/ping` to the SAME destinations from its own CLI succeeds instantly
(0% loss), ruling out the CPE/OLT devices themselves being down.
`/tool sniffer`-based capture and `/ip firewall connection print`
consistently show NOTHING for this traffic despite the peer-level `rx`
counter proving arrival — inconclusive with the read-only tooling
available this session (no terminal/SSH access to the router, no tcpdump
inside `wireguard-node3` to independently confirm departure timing).
`/ip firewall filter` was checked in full (82 rules) — nothing explicitly
scoped to the WireGuard interface or the tunnel's address ranges. This
affects the SAME mechanism (MASQUERADE-to-`172.23.195.1`, present and
unchanged in shape from what v0.7.7 already verified working) for BOTH
the pre-existing TR-069 path and the new OLT path identically, so it does
not look like a defect introduced by the addresses this sprint changed —
but root cause has not been pinned down further. LibreNMS OLT onboarding
therefore remains blocked on this, separate from (and downstream of) all
3 fixes above, which are independently confirmed correct.

## WireGuard /30 Per-NAS Tunnel Blocks (v0.8.1)

**Replaces the single shared tunnel gateway (`172.23.195.1`, one address
for every WireGuard NAS) with a dedicated `/30` block per NAS.** Diagnosed
by Agung (networking) while the "traffic arrives at the router but isn't
forwarded onward" gap above was still unresolved: the shared-gateway `/32`
scheme means the router only ever learns about the tunnel through
WireGuard's own `AllowedIPs`-driven implicit routing, never a real
connected route the way a normal point-to-point link would have. This is
a plausible contributing factor to that gap — **not yet conclusively
proven to fix it**, verification is Langkah 3/4 of this same work, still
pending as of this section being written.

**Scope: WireGuard only.** OpenVPN (`ifconfig-push`/ccd, already
genuinely per-client point-to-point) and L2TP/IPsec (PPP's own dynamic
negotiation) are untouched — both still use the original flat
`vpn_ip_pool` mechanism exactly as before. Each protocol already has its
own `subnet_cidr` (`172.23.194.0/24` OpenVPN, `172.23.195.0/24` WireGuard,
`172.23.196.0/24` L2TP, plus OpenVPN's node2/node3 sibling subnets) so
there was never any cross-protocol collision risk to begin with.

**Allocation formula**: block #n = `172.23.195.0 + (n × 4)` →
gateway = `base + 1`, router = `base + 2` (network/broadcast, `base + 0`/
`base + 3`, deliberately unused — the same "some addresses wasted for a
real point-to-point topology" trade-off any `/30` link has). Block #0 —
`172.23.195.0/30` (gateway `.1`, router `.2`) — happens to reproduce
`test-x86-bajastu`'s existing addresses exactly, since it's the first (and
so far only) NAS to ever hold a WireGuard block. `App\Support\CidrRange::
wireguardNasBlock()` is the pure-arithmetic implementation (same "no DB
dependency" posture as `usableHostAddresses()`/`gatewayAddress()` already
in that class).

**Allocation is STICKY, not a release-and-reuse pool like `vpn_ip_pool`**
— a NAS keeps the SAME block forever, across every revoke/reprovision
cycle of its WireGuard account (this system revokes/reprovisions the same
NAS's account routinely — a FCFS-pool-of-blocks model would make the
router-side block assignment churn unpredictably every regen, exactly the
kind of instability this redesign exists to remove). New table
`vpn_wireguard_nas_blocks` (`nas_id` unique — one row per NAS, forever;
`(vpn_server_id, block_index)` unique). `App\Models\VpnWireguardNasBlock::
allocateFor()` — lookup-or-create: an existing assignment is always
reused; a genuinely new NAS gets `MAX(block_index)+1`, computed under a
lock on the POOL OWNER's own `vpn_servers` row (not on the aggregate
query itself — see the deployment-bug note below), an adaptation of the
`lockForUpdate()` pattern already trusted for `vpn_ip_pool`/`OdpPort`
(`WorkOrderService`), not a new locking style. Order is **FCFS by
whichever NAS asks first** (chronological, matching how
`vpn_ip_pool`'s own row-id FCFS already worked), explicitly NOT `nas.id`
order — confirmed with a test provisioning 3 NAS out of `id` order and
checking the block indices land in REQUEST order, not `id` order.
`VpnProvisioningService::provision()` branches on `protocol ===
VpnProtocol::WireGuard` right where `internal_ip` gets sourced — WireGuard
takes `$block->router_ip` instead of touching `vpn_ip_pool` at all;
`revoke()`/`rollbackFailedProvisioning()` needed NO code changes (their
`vpn_ip_pool`-release queries simply match zero rows for a WireGuard
account now, a harmless no-op) — deliberately does NOT touch the block row
either, consistent with "sticky forever."

**`/ip address` mask changed from `/32` to `/30` — a DELIBERATE REVERSAL
of the v0.7.3 decision, not a regression.** v0.7.3 locked `/32` specifically
because the shared subnet (`WG_SUBNET_CIDR`, one `/24` for every NAS) meant
a wider mask would have made RouterOS auto-add a connected route for the
WHOLE shared range — defeating the explicit reverse-route isolation this
whole class of script exists to enforce. That reasoning stops applying the
moment each NAS gets its OWN dedicated `/30` — a connected route for a
`/30` only ever covers the 2 addresses that `/30` legitimately has (this
NAS's own gateway + its own router address), exactly as narrow as the old
`/32` was, just backed by a real connected route now instead of relying
purely on `AllowedIPs`. **Do not "fix" this back to `/32` without reading
this section first** — `MikrotikScriptGenerator`'s own comment on this
exact line repeats this same warning for anyone reading the code directly
without CLAUDE.md open.

**Replication to all 3 WireGuard nodes reuses the EXISTING reconcile-loop
pattern (peers), not a new mechanism.** Through v0.8.0, gateway replication
was "free" — `WG_SUBNET_NETWORK_ADDR` (always `172.23.195.1`) came from the
SAME root `.env` via `env_file:`, so all 3 nodes' `entrypoint.sh` ran the
identical static `ip address add` at boot. With one gateway per NAS, this
can no longer be a single static value — `VpnProvisioningService::
issueWireGuardCredentials()` now ALSO writes a per-NAS address fragment
(`$WG_DIR/addresses/nas-{id}.conf`, containing just `{gateway}/30`) to the
same shared `vpn_wg_data` volume already used for peer fragments;
`docker/wireguard/entrypoint.sh`'s existing ~10s reconcile loop (previously
only `wg syncconf` for peers) now ALSO applies every known fragment to wg0
— idempotently, since (unlike `wg syncconf`) a plain `ip address add`
errors on a duplicate: each fragment's exact "IP/mask" token is checked
against `ip -4 address show dev wg0` first via `grep -qw`, skipped if
already present. **Verified in complete isolation, not just read** — a
throwaway `alpine:3.20` container with a dummy interface confirmed 3
cycles behave exactly as designed: first cycle applies 2 fresh addresses,
second cycle (same fragments) applies nothing, third cycle (a NEW fragment
added mid-flight, simulating a brand-new NAS being provisioned while nodes
are already running) applies only the new one — no restart needed, matching
the "no manual per-node step" requirement. The static `ip address add
"${WG_SUBNET_NETWORK_ADDR}/24"` line at container boot is GONE — wg0 starts
with no address at all on a fresh container start, until the reconcile
loop's first cycle (~10s) applies whatever NAS blocks already exist.
`WG_SUBNET_NETWORK_ADDR` itself is left in `.env`/`.env.example`, just no
longer read — removing it outright risked looking like a real config
regression to anyone diffing against an older deployment.

**MASQUERADE ambiguity — a real gap found auditing this redesign, fixed
for the ONE currently-supported NAS, explicitly NOT generalized.** The 3
existing `MASQUERADE` rules (FreeRADIUS/TR069/OLT reverse routing) never
specified `--to-source` — safe through v0.8.0 because wg0 only ever had
ONE address, so "whatever the outgoing interface's address is" was
unambiguous. The moment wg0 can carry several NAS gateways at once, plain
`-j MASQUERADE` becomes genuinely ambiguous about which one to rewrite to.
Fixed with a new optional `WG_NAS_GATEWAY_IP` env var (same "one NAS only,
static/manual, documented limitation" posture as `TR069_MANAGEMENT_GATEWAY`/
`OLT_MANAGEMENT_GATEWAY`) — when set, all 3 `MASQUERADE` rules gain
`--to-source "$WG_NAS_GATEWAY_IP"`; when unset, they silently fall back to
the old ambiguous default (a transitional safety net for a deployment that
hasn't set the new var yet, not a real fix for the general case). **A
genuinely multi-NAS-aware version — matching each packet's destination
subnet to the CORRECT NAS's own gateway automatically, not one pinned
global value — is NOT built here.** Tracked as a real, explicit gap (same
class as `TR069_MANAGEMENT_SUBNET`/`OLT_MANAGEMENT_SUBNET`'s existing
"one subnet system-wide" limitation), not silently hardcoded without a
trace — both the `.env.example` comment and the entrypoint.sh comment on
this exact array spell out what a real fix would need.

**Deployment status (Langkah 3, this same v0.8.1 branch): containers
redeployed, `test-x86-bajastu`'s account moved to block #0, backend fully
verified — the actual router-side script has deliberately NOT been applied
yet.** Two real bugs surfaced only once this hit the real containers/real
Postgres, neither caught by the pre-deployment test suite (601/601 green
at the time, SQLite doesn't share either restriction below):

1. **`-j MASQUERADE --to-source ...` is invalid iptables syntax** —
   `--to-source` is an SNAT-target-only option; combining it with the
   `MASQUERADE` target crash-looped both `wireguard` and `wireguard-node2`
   immediately after `docker compose up -d --build`
   (`iptables v1.8.10 (nf_tables): unknown option "--to-source"`, fatal
   under `set -euo pipefail`). Fixed by switching the TARGET itself
   (`-j MASQUERADE` vs `-j SNAT --to-source "$WG_NAS_GATEWAY_IP"`) rather
   than appending an option to the wrong target — verified first in an
   isolated throwaway `alpine:3.20` container before redeploying to the
   already-crash-looping real containers, which then recovered cleanly.
2. **`SELECT MAX(block_index) ... FOR UPDATE` is rejected outright by
   PostgreSQL** (`SQLSTATE[0A000]: Feature not supported: 7 ERROR: FOR
   UPDATE is not allowed with aggregate functions`) — there's no single row
   to lock when the result is an aggregate, and SQLite (the test driver)
   simply doesn't enforce this restriction, so all 601 tests passed against
   the now-broken version. This caused a real, if brief, incident:
   `test-x86-bajastu`'s old WireGuard account had already been revoked (as
   a separate call, not in the same transaction as the failed provision)
   before the provision call threw, leaving the NAS with zero active
   WireGuard account for a short window. Fixed in
   `VpnWireguardNasBlock::allocateFor()` by locking the POOL OWNER's own
   `vpn_servers` row instead of the aggregate query — that row always
   exists, so it works as a mutex regardless of how many (if any) block
   rows currently exist under it; the `MAX()` query itself then runs
   unlocked but safely serialized by the parent lock, portable to both
   drivers. Account restored immediately after the fix (new id, same block
   #0 / `172.23.195.1`-`.2` addresses, since it's still the same NAS).

**Verified post-fix, for real**: all 3 wireguard node containers show
`172.23.195.1/30` on `wg0` (reconciled correctly from the single address
fragment via the existing ~10s loop), and the restored account's peer
entry (`AllowedIPs = 172.23.195.2/32, 10.1.0.0/20, 10.168.100.0/24`) is
present on all 3 nodes too — full regression suite re-run clean (601/601)
after the fix. **What is NOT yet done**: the new keypair has zero
handshake on any node (expected — the router hasn't been given the new
script yet). Per Agung's explicit instruction, the actual script
generation/application for this step is being done by Agung himself
through the real `/nas` → NAS → Script Generator UI (both the WireGuard
and RADIUS tabs) — deliberately NOT generated by Claude Code via API/CLI
for this step, so the real user-facing path (including the earlier `.rsc`
corruption fix) gets validated through actual UI use, not just another
round of automated testing. Backend readiness for that was confirmed via
`Livewire::test(VpnScriptGenerator::class)` mounting cleanly and accepting
NAS #1 on both tabs with zero errors — without invoking either tab's
actual generate action, so the first real generation is still Agung's.

**P0 found the moment Agung actually clicked "Cabut" in the browser — the
SAME bug class as the OpenVPN PKI (`nas-11`, v0.6.3) and
`freeradius_nas_config` (v0.6.5) incidents, not a new one.** Real 500,
confirmed from `storage/logs/laravel.log`:
`file_put_contents(/vpn-wg-data/addresses/nas-1.conf): Failed to open
stream: Permission denied`, thrown from
`VpnProvisioningService::issueWireGuardCredentials()`
(`app/Services/Network/VpnProvisioningService.php:387`), called via
`VpnScriptGenerator::revokeAndRegenerate()`. Root cause, confirmed by
inspecting the file directly, not guessed: `nas-1.conf` in
`/vpn-wg-data/addresses/` was `root:root 0644` — written by this session's
own `docker compose exec` (no `--user` flag → uid 0 by default) tinker
call earlier in this same Langkah 3, restoring the NAS's account after the
`FOR UPDATE`+`MAX()` incident above. That write happened AFTER the
container's one-time boot-time `chmod -R 0777 "$PEERS_DIR" "$ADDRESSES_DIR"`
had already run, so the file never got the permissive mode — a real
`www-data` web request (Agung's own browser session) can then never
overwrite it again. **Not a new class of bug — this codebase has hit
"a root-run manual/testing session leaves a root-owned file behind that
later blocks a real www-data request" twice before**; the fix is the same
established pattern both times: re-apply the permissive chmod on every
reconcile-loop cycle (~10s), not just at container boot, so a stray
root-owned fragment self-heals quickly instead of wedging the next real
save permanently. Added to `docker/wireguard/entrypoint.sh`'s existing
reconcile loop (`chmod -R 0777 "$PEERS_DIR" "$ADDRESSES_DIR"` at the top of
the `while true` loop, right where the freeradius supervisor loop already
does the equivalent `chgrp`/`chmod` every cycle for `freeradius_nas_config`).
Immediate unblock (no container rebuild needed) was also applied directly
— `chmod -R 0777` on the two live volumes — so Agung's very next attempt
in the browser isn't blocked while the container-image fix is still
pending its own rebuild+rolling-recreate.

**Honest note on test coverage for this specific bug**: a
`Livewire::test(VpnScriptGenerator::class)->call('revokeAndRegenerate')`
test **already existed** before this incident
(`test_revoke_and_regenerate_replaces_the_old_wireguard_account_and_produces_a_fresh_script`
in `VpnScriptGeneratorLivewireTest.php`) and calls the real action, not
just mount — so the gap was never "no test calls Cabut for real." The gap
is structural: PHPUnit runs the whole request in ONE OS process/UID, so
there is no cross-UID permission conflict for it to ever reproduce — the
exact same reason the OpenVPN PKI and `freeradius_nas_config` incidents
were never caught by their own test suites either, only by real deployment
use. No new PHPUnit test was added for this reason; the regression
protection is the entrypoint.sh periodic-chmod fix itself, verified the
same way the prior two incidents were — through real container behavior,
not a unit test.

**Migration for the currently-live NAS (`test-x86-bajastu`) — Langkah 3,
DONE.** (Superseding the "deliberately NOT done" note this paragraph used
to carry — the migration described above has since actually happened, see
the "P0" and IP-migration entries below for what was found doing it.)
Agung applied the new `/30` script via the real `/nas` → Script Generator
UI himself (not generated by Claude Code, per the explicit instruction for
this step); the account was revoked/reprovisioned, and the router now
shows `172.23.195.2/30` with a live handshake, confirmed via RouterOS API.

**Real 500 found and fixed the moment Agung actually clicked "Cabut" in
the browser — see the "P0" entry above this one for the full stack
trace/root cause/fix** (stray root-owned address fragment file, same bug
class as the OpenVPN PKI/`freeradius_nas_config` incidents).

**IP migration to the reserved `/27` block (`.225`-`.229`) for
FreeRADIUS/GenieACS-CWMP/GenieACS-NBI/LibreNMS/LibreNMS-dispatcher — also
already done, confirmed by direct `docker inspect` + real ping, not
assumed.** All 5 containers already carry their correct target IPs and are
reachable both from `boss-app` and directly from the host (0% packet
loss); only `.224` (the deliberately unused reserved block-base address)
fails to ping, which is expected, not a bug.

**Real bug found doing the post-migration layered verification (FreeRADIUS
reachability via the tunnel, from the router's own side) — a genuine,
if currently-dormant, gap, NOT the same class as anything above.** A real
RouterOS `/ping` from `test-x86-bajastu` to FreeRADIUS's new IP
(`172.28.0.225`) times out 100% of the time, even though: `boss-app` can
ping `.225` fine, `wireguard-node3` (the node this NAS's tunnel is
CURRENTLY handshaked on) can ping `.225` fine directly, and the router's
own WireGuard peer/route/allowed-address state is all correct
(`172.28.0.224/27,172.23.195.1/32` in `allowed-address`, an active route
to the block, a live handshake with real rx/tx counters). Root cause,
confirmed by reading `freeradius` container's own routing table directly:
`docker/freeradius/entrypoint.sh`'s `refresh_coa_routes()` (added in
v0.6.5 for CoA/Disconnect) resolves the WireGuard tunnel subnet's next-hop
by the container NAME `wireguard` — i.e. **always node1**
(`172.28.0.11`), never whichever node is actually the CURRENT live one.
This NAS's session has auto-switched to node3
(`172.28.0.5`/`wireguard-node3`) — so any reply FreeRADIUS sends back
toward the tunnel (an ICMP echo-reply, or — just as much — a real RADIUS
Access-Accept) gets routed to node1, which has no live peer/handshake for
this NAS, and is silently lost. **The v0.6.5 CoA docblock already flags
this exact "failed-over to a sibling node" scenario as a known gap** — but
frames it narrowly as a CoA/Disconnect limitation ("CoaService therefore
only reliably reaches a NAS that hasn't (recently) auto-switched"); this
investigation found its real blast radius is broader — it's FreeRADIUS's
**only** route back into the WireGuard tunnel subnet at all, so it governs
every reply through that tunnel, not just CoA-initiated ones. **Unlike**
`TR069_MANAGEMENT_GATEWAY`/`OLT_MANAGEMENT_GATEWAY` (which went stale
because a correct-at-the-time env var value was never updated after a
later auto-switch), this route isn't driven by any env var at all — it's
structurally hardcoded to node1 by design, so there's no "just update the
value" fix available; a real fix needs either a mechanism to keep this
route pointed at whichever node is CURRENTLY live (mirroring how
`VpnCheckNodeHealth`/the auto-switch scheduler already tracks this), or
accepting the same limitation CoA already has, just documented more
broadly. **No live production impact confirmed** — `/radius/monitor` for
`boss-radius` (the NAS's `172.28.0.225` entry) shows `requests: 0` (all
441 currently-active real PPPoE sessions are authenticating via the
router's other, `mixradius`-added entry, which is unaffected by any of
this) — this is a real, confirmed infrastructure gap, not a customer-
facing outage, as of this writing. **Layered verification (GenieACS
Connection Request retest, OLT `snmpget`, LibreNMS onboarding) was
deliberately NOT attempted after this was found** — the instruction for
this task was to stop the chain on the first failure, and this is that
first failure.

## OSPF Dynamic Routing (v0.8.1) — DISABLED, kept as reference/future upgrade path

**STATUS: built, verified working end-to-end for real, then deliberately
rolled back and disabled — not because it didn't work, but because it
was more operational complexity than this deployment's current scale
(1 real NAS, 3 nodes, 1 physical Docker host) justifies.** Replaced by
the much simpler **fragment+reconcile** mechanism (see its own section
below) — BOSS App itself, which already knows which node owns which
NAS from its own DB, writes small per-NAS route files directly; consumer
containers just read-and-apply on a polling loop, the same idiom already
used elsewhere in this codebase (WireGuard peer/address fragments,
`freeradius_nas_config`) — no routing protocol, no extra daemon, no new
failure surface.

**Why this was a genuine, working implementation, not an abandoned
half-measure** — worth recording precisely so a future decision to
revisit this isn't second-guessing a broken prototype: full-mesh OSPF
adjacency (MD5-authenticated) was confirmed FULL across all 3 WireGuard
nodes; `handshake-watcher.sh`'s hybrid liveness check (handshake age OR
byte-delta) was verified correct both against the real live tunnel and
via a deterministic test harness; and — most importantly — a REAL
auto-switch event happened live during testing (the NAS's tunnel moved
from node3 → node1 → node2 across the session) and sibling nodes
correctly re-learned the new routes via OSPF within seconds each time,
with zero manual intervention. The mechanism did exactly what it was
built to do.

**Why disabled anyway — the actual trade-off, not a technical failure**:
- **Operational complexity for the current scale.** 8 sidecar containers
  (one per target), 2 config-file formats per daemon, a hand-rolled
  supervision model (no `frrinit.sh`/`watchfrr`), and real FRR-specific
  footguns discovered along the way (`mgmtd`/`staticd` silently required
  for live `vtysh` static-route changes but not for config-file-loaded
  OSPF settings; `ip prefix-list` non-idempotent while `ip route` is;
  kernel (`K`) vs connected (`C`) vs static (`S`) route-type distinctions
  mattering for `redistribute` eligibility in ways that aren't obvious
  without hitting them) — real, learnable, but genuine ongoing
  maintenance burden for a team running a single-host deployment.
- **A structural gotcha with the sidecar pattern itself**: a
  `network_mode: "service:X"` sidecar does NOT automatically reattach
  when `X` itself is recreated — it becomes silently orphaned, pointing
  at a netns that no longer exists. Confirmed for real during Tahap B
  (recreating `librenms-dispatcher` orphaned `frr-librenms-dispatcher`
  without any error, silently losing its learned routes) — every future
  container touch would need BOTH the target and its sidecar recreated
  together, forever, or this bites again. A permanent, easy-to-forget
  operational tax.
- **`.env`-driven config-hash recreates kept cascading further than
  intended**, repeatedly, throughout both Tahap A and Tahap B — adding
  `OSPF_*` keys to the shared `.env` meant `docker compose up -d
  <one-sidecar>` routinely recreated far more of the dependency chain
  than requested (including, twice, the WireGuard node genuinely holding
  the live NAS tunnel at that moment) — self-healed both times (~14s,
  ~30s) with no lasting harm, but a real, repeated risk surface that
  fragment+reconcile doesn't share (it never touches container identity/
  networking, only writes files an already-running polling loop reads).
- **The one thing OSPF actually buys — automatic adjacency/topology
  discovery across a growing number of routers — isn't needed yet.**
  This deployment is 1 physical Docker host; "topology" is simply
  "boss-network" needing to be told, containers may as well use a
  substantially simpler distribution mechanism (the fragment+reconcile
  loop) since discovery isn't the actual problem being solved.

**When to reconsider this** (per Agung's own framing, recorded so a
future sprint doesn't need to re-derive it from scratch): if/when these
node containers ever actually move to separate PHYSICAL servers (not
just separate containers on one host) — at that point, real routing
protocol convergence (sub-second failover across genuinely different
network segments, not just container recreates on one bridge) becomes a
real requirement fragment+reconcile's simple polling loop can't cleanly
solve, and this OSPF implementation — Dockerfile, config templates,
`handshake-watcher.sh`, and this whole section's hard-won debugging
history — is sitting here ready to be re-enabled rather than rebuilt
from zero.

**What's still in the repo, deliberately not deleted**: `docker/frr/`
(Dockerfile, entrypoint.sh, `frr.conf.node.template`/`frr.conf.
consumer.template`, `handshake-watcher.sh`, both test scripts) — all
unchanged, all still buildable, just not referenced by any active
`docker-compose.yml` service. The 8 sidecar service definitions in
`docker-compose.yml` are commented out in place (not deleted) with a
`[v0.8.1 OSPF DISABLED — see CLAUDE.md]` marker on each block, so
re-enabling is "uncomment + rebuild", not "rewrite". `OSPF_ROUTING_
ENABLED`/`OSPF_AUTH_KEY`/`OSPF_HELLO_INTERVAL`/`OSPF_DEAD_INTERVAL`
remain in `.env`/`.env.example` (flag defaults `false`, harmless to
leave set).

---

**The rest of this section (below) is the original design/build record
from when OSPF was the active mechanism — left intact as-is for anyone
re-enabling it later, not updated to reflect the rollback.**

**Replaces the static, manually-updated route mechanisms** (`docker/
freeradius/entrypoint.sh`'s `refresh_coa_routes()` WireGuard half,
`docker/genieacs/entrypoint.sh`'s `TR069_MANAGEMENT_GATEWAY` route,
`docker/librenms/route-init.sh`'s `OLT_MANAGEMENT_GATEWAY` route — all
hardcoded or manually-updated to "whichever node currently holds a NAS's
account", confirmed to actually go stale/wrong in production, see the
"LibreNMS OLT Onboarding"/"Infra Tunnel IP Block" sections above) with
real dynamic routing — **FRRouting (FRR) OSPFv2**, one shared sidecar
image deployed 8 times via `network_mode: "service:<target>"` (3
WireGuard nodes + freeradius + genieacs-cwmp + genieacs-nbi + librenms +
librenms-dispatcher). A sidecar has NO network of its own — it fully
shares its target's netns, so zebra's kernel route installs apply
directly to whatever process actually routes packets for that container,
no cross-container route-copying mechanism needed.

**Confirmed via direct `apk search`/`apk add` before choosing this**:
every one of the 8 target containers' base images (`freeradius/
freeradius-server:3.2.10-alpine`, `node:22-alpine`, `alpine:3.20`, and —
checked directly, not assumed — even the official `librenms/librenms:
latest` with no Dockerfile of our own) is Alpine, and `frr` (10.0-r2)
installs cleanly via `apk` on all of them. One shared image
(`docker/frr/Dockerfile`) rather than baking FRR into 3 different
Dockerfiles plus a runtime-install hack for the one official image.

**Resource footprint, measured for real before committing to all 8** (13
minutes, 13 samples, one sidecar in isolation): **8.4 MiB RAM flat, 0.32-
0.40% CPU** with Hello genuinely active every 1s. Extrapolated to 8:
~67MB RAM (linear — LSDB for an 8-router topology is trivially small),
<1% CPU per container even full-mesh. ~30% of the existing LibreNMS
stack's own ~225MB footprint, but spread as lightweight sidecars on
already-running containers, not a new heavy process.

**Route redistribution — asymmetric by design, not symmetric OSPF**:
- **3 WireGuard nodes** (`FRR_ROLE=node`): `redistribute static
  route-map OSPF-WG-ONLY` (prefix-list scoped to `172.23.0.0/16`,
  defense-in-depth against ever leaking an unrelated future static
  route). Deliberately NOT `redistribute connected` — the existing
  reconcile loop in `docker/wireguard/entrypoint.sh` keeps applying
  every known NAS's `/30` address to ALL 3 nodes unconditionally
  (unchanged, still the sticky/permanent design from the WireGuard /30
  section above) — a plain `redistribute connected` would announce all 3
  nodes as valid paths to the same NAS simultaneously, real handshake or
  not. `docker/frr/handshake-watcher.sh` is the actual gate: it adds/
  removes a `/32` static route for each NAS's *router* address (derived
  by pure arithmetic from the gateway address VpnProvisioningService
  already writes — `router = gateway + 1`, no DB access needed) based on
  a **hybrid liveness check** (see below), and only THAT static route
  gets redistributed.
- **5 consumers** (`FRR_ROLE=consumer`): form full OSPF adjacency
  (Hello/DBD/LSA) and learn routes normally, but carry **no
  `redistribute` statement of any kind** — never announce anything back.
  This is deliberately NOT FRR's `passive-interface` (that means "don't
  even form adjacency, just stub-announce the connected subnet" — the
  opposite of what's needed here).

**Hybrid liveness check for handshake-watcher.sh — replaced a
handshake-age-only design after it was found to flap on a genuinely
healthy tunnel, confirmed empirically, not theoretically.** The original
design used `wg show wg0 latest-handshakes` age alone against a 30s
threshold — real testing (Tahap A) showed a live, actively-passing-
traffic tunnel sitting at 51s-115s+ handshake age the whole time, proven
by watching the SAME handshake timestamp stay frozen across 4 checks
spanning 45 seconds while `rx`/`tx` kept climbing in that same window.
Root cause: `persistent-keepalive` (25s) only refreshes the NAT mapping —
it does **not** trigger a WireGuard Noise-protocol rekey, which only
happens ~120s by default (`REKEY_AFTER_TIME`) or on its own traffic-
triggered schedule. A flat 30s threshold against this signal would make
the redistributed route flap in and out for the majority of every ~120s
cycle, even for a perfectly healthy tunnel. Fixed (Agung's explicit
decision) with a **hybrid**: a route stays installed if EITHER (1)
handshake age < **150s** (bumped from 30s — just above the natural
rekey interval + margin), OR (2) the peer's `rx` byte counter grew since
the watcher's last cycle (5s ago) — this second condition is what
actually saves a healthy-but-handshake-stale tunnel almost immediately,
since `persistent-keepalive` alone bumps `rx` every ~25s regardless of
rekey timing. The route is only withdrawn when **both** fail — first
watcher cycle for a peer (no prior `rx` sample yet) defaults to
"present" rather than risk a spurious withdrawal before two samples
exist. **Verified deterministically** via
`docker/frr/test-handshake-watcher-hybrid.sh` (fakes `wg`/`vtysh`, no
dependency on live WireGuard rekey timing, which turned out to be too
unpredictable to reliably exercise "handshake stale but traffic active"
against the real tunnel on demand) — both scenarios pass: stale-handshake-
but-growing-rx keeps the route, stale-handshake-and-flat-rx withdraws it
and keeps it withdrawn across repeated cycles, not just a one-off blip.
Also confirmed against the real live NAS tunnel: the route was genuinely
installed (`S>* 172.23.195.2/32 ... directly connected, wg0`) and
genuinely redistributed into OSPF and learned by a sibling node
(`O>* 172.23.195.2/32 [110/20] via 172.28.0.5, eth0`).

**Real bug found deploying to the live tunnel node for the first time:
FRR 10.x needs `mgmtd` + `staticd` running for live `vtysh` static-route
commands, not just `zebra` + `ospfd`.** `handshake-watcher.sh`'s `ip
route .../32 wg0` calls were silently failing every single cycle —
config-FILE loading (`ospfd -f ospfd.conf`, what sets up `router ospf`/
`network`/authentication) worked fine without these two daemons, so
adjacency formed and looked entirely healthy, masking the failure
completely; the real error (`mgmtd is not running`) only surfaces when
running `vtysh` interactively — the watcher's own `>/dev/null 2>&1`
swallowed it silently on every cycle. Root cause: FRR's newer central
config-transaction daemon (`mgmtd`) brokers live `vtysh` static-route
changes to `staticd` (which owns static routes in modern FRR, not zebra
directly) — this has nothing to do with OSPF-specific config, which
still loads the old way via a daemon's own `-f` config file. Fixed by
starting `mgmtd`+`staticd` (node role only — consumers never issue a
live `ip route` command, so they don't need either). **Capability list
also had to grow past the original design**: `zebra` itself refused to
start at all (`privs_init: initial cap_set_proc failed`) until
`SYS_ADMIN` was added alongside `NET_ADMIN`/`NET_BROADCAST`/`NET_RAW` —
found during the resource-test phase, before any of the 8 were deployed
for real.

**Entrypoint deliberately does NOT go through `frrinit.sh`/`watchfrr`**
(FRR's own distro-init-style launcher, tried first) — its `daemon_start`/
`daemon_prep` machinery assumes a real init system (PID-file
conventions, `install(1)`-based directory bootstrap tuned for openrc/
systemd) that adds real complexity/failure modes in a bare container with
no clear win. `docker/frr/entrypoint.sh` instead launches each needed
daemon directly (zebra background → mgmtd+staticd background, node role
only → handshake-watcher background, node role only → ospfd foreground as
PID1), with crash recovery delegated to Docker's own `restart: unless-
stopped` on the sidecar container itself rather than watchfrr's finer-
grained per-daemon restart logic (all the daemons in one sidecar need to
restart together anyway — ospfd's zapi connection to zebra doesn't
survive zebra dying alone).

**Two separate config files per daemon, not one shared "integrated"
file** — a real, if harmless, bug found on first deploy: pointing BOTH
`zebra -f` and `ospfd -f` at the SAME file made zebra log a "No such
command" warning for every single OSPF-specific line (`router ospf`,
`network ... area`, `ip ospf hello-interval`, etc. — all ospfd-only
commands zebra doesn't understand). Not fatal (zebra just skips unknown
lines and starts fine regardless) but architecturally wrong and noisy.
Fixed: zebra gets its own minimal generated file (just `hostname`/`log
stdout`), the full templated config (`frr.conf.node.template`/
`frr.conf.consumer.template`, `__HELLO__`/`__DEAD__`/`__AUTH_KEY__`/
`__ROUTER_ID__` substituted by entrypoint.sh at container start — router-
id derived from the sidecar's own shared-netns eth0 IP, deterministic
since every target already has a pinned static IP) goes to ospfd alone.
True FRR "integrated" single-file config would need `vtysh`'s own
boot-time distribution mechanism, not two daemons independently pointed
at the same raw file.

**Security**: OSPF MD5 authentication (`ip ospf authentication
message-digest` + `ip ospf message-digest-key 1 md5 ${OSPF_AUTH_KEY}`)
on every one of the 8 sidecars' `eth0` — one shared key across the whole
domain (`OSPF_AUTH_KEY` in `.env`, infra-level secret like
`WHATSAPP_GATEWAY_HMAC_SECRET`/`L2TP_IPSEC_PSK`, generated with `openssl
rand -hex 8` — FRR's MD5 key field is capped at 16 characters, a 32-char
key was tried first and silently accepted by this FRR version but not
relied upon since the RFC 2328 limit is 16). Confirmed working, not just
configured: adjacency between node1/node2 would never have formed FULL
at all if the key didn't match on both sides — a real, working proof
point, not just a config-parses-cleanly check.

**Migration discipline — no static+OSPF parallel-run period, unlike the
original design doc's plan.** Agung's explicit revision: the moment a
given container's FRR sidecar has verified adjacency + correct learned
routes, `OSPF_ROUTING_ENABLED=true` is set and the old static route
mechanism is cut over for THAT container immediately — no 24-48h
observation window with both running side by side (the original design
doc's plan). Each of the 3 modified entrypoint scripts
(`freeradius`/`genieacs`/`librenms route-init`) checks this ONE shared
flag; `OSPF_ROUTING_ENABLED` is global in `.env`, not per-container, but
only actually takes effect on a container once THAT container is
recreated, so the cutover is still genuinely one-at-a-time in practice.
`freeradius`'s WireGuard-subnet route is gated by this flag; its OpenVPN-
subnet route in the SAME function is deliberately left unconditional —
OSPF is WireGuard-only scope this sprint, gating the OpenVPN route too
would silently break CoA for any OpenVPN NAS the moment the flag flips.
Verified with a standalone deterministic shell harness (`docker/frr/
test-ospf-routing-enabled-conditional.sh`, fakes `ip`/`getent`, no real
container needed) rather than a PHPUnit test — these are plain POSIX
shell entrypoint.sh files with zero PHP involved, so there's nothing for
`php artisan test` to exercise; the shell harness proves whether the real
route-modifying commands (`ip route replace ...`) are invoked under
`OSPF_ROUTING_ENABLED=true` vs `false`/unset, for all 3 modified scripts.

**Real infrastructure side effect found deploying Tahap A (3 WireGuard
nodes), not caused by anything wrong in the FRR work itself**: adding new
`OSPF_*` keys to `.env` changed the effective config hash of every OTHER
service that reads it via `env_file: - .env` — `docker compose up -d
frr-wireguard-node2` (etc.) walked the full `depends_on` chain and
recreated `wireguard`, `wireguard-node2`, `wireguard-node3`, and
`freeradius` too, not just the requested sidecar, none of which were
intentionally targeted for recreation at that step. Real consequence:
`wireguard-node3` — the node `test-x86-bajastu`'s live tunnel was
actually on — got recreated as a side effect, dropping the tunnel
momentarily; it **self-healed within ~14 seconds** (fresh handshake,
`endpoint-port` stayed 51822/node3, no auto-switch triggered, rx/tx
resumed climbing normally) — confirmed via direct RouterOS API read
immediately after, not assumed safe. No lasting harm, but a real,
documented gotcha: **once `.env` gets a genuinely new key added,
`docker compose up -d <anything>` can silently recreate far more than
the one service named on the command line**, if enough of the dependency
chain shares that same `env_file`. Tahap B's containers don't need any
further `.env` additions, so this specific trigger shouldn't recur for
the rest of this rollout — flagged here so a future sprint touching
`.env` again knows to expect it.

## Fragment+Reconcile Routing (v0.8.1) — replaces OSPF, currently ACTIVE

**Status: implemented, deployed to all 5 consumer containers, verified
working for real** (adjacency/routing correctness proven via `ip route`
content on every container plus real packet-transit evidence — see
below). Pivoted to from the OSPF experiment (see that section above for
why OSPF was rolled back) — same underlying problem (5 consumer
containers need to reach a WireGuard NAS's tunnel subnet + TR-069/OLT
management subnets, dynamically following whichever pool node currently
holds that NAS's live account), radically simpler mechanism.

**Design**: BOSS App itself is the source of truth — it already has (or
can ask for) everything needed: which NAS accounts are active
(`vpn_accounts`), which subnets matter for a given NAS
(`VpnWireguardNasBlock.router_ip`, `nas.tr069_management_subnet`,
`config('services.vpn.olt_management_subnet')` gated on whether the NAS
actually has an `OltDevice` registered), and — the one piece that isn't
in any DB column — which pool node a NAS's WireGuard tunnel is
*currently* connected to, asked directly via `RouterOsGateway::
currentWireguardEndpointPort()` (a new interface method,
`/interface/wireguard/peers/print`'s `current-endpoint-port`, matched by
the peer's own `"... NAS {$account->username}"` comment — the same
comment `MikrotikScriptGenerator::wireGuardScript()` already writes).
That's the ONLY reliable source for "current node" — auto-switch (v0.6.4)
happens entirely client-side on the router, invisible to boss-app any
other way.

**`App\Console\Commands\VpnSyncRouteFragments`** (scheduled
`->everyMinute()`, same cadence as `VpnCheckNodeHealth` — a different
question: that one tracks whether a node CONTAINER is alive, this one
tracks which node a NAS's tunnel is CURRENTLY on) writes one file per
active WireGuard NAS to the shared `vpn_wg_data` volume
(`services.vpn.routes_dir`, default `/vpn-wg-data/routes/nas-{id}.conf`),
one `<subnet> via <node_ip>` line per relevant subnet. A NAS whose
current node can't be determined (router unreachable, no matching peer)
gets its fragment DELETED rather than left stale — a wrong/old route is
worse than no route (a consumer would silently keep trying a dead node).
A revoked account's fragment is cleaned up the same way. `services.vpn.
wireguard_node_ips` (new config key, `51820/1/2 => WIREGUARD_NODE1/2/3_
INTERNAL_IP`) is the port→boss-network-IP lookup table, keyed by the same
listen ports `vpn_servers.port` already stores DB-side.

**The 5 consumer containers** (`freeradius`, `genieacs-cwmp`,
`genieacs-nbi`, `librenms`, `librenms-dispatcher`) each mount
`vpn_wg_data:ro` and run an identical small reconcile loop (backgrounded
in their own `entrypoint.sh`/`route-init.sh`, same polling idiom already
used for peer/address fragments in `docker/wireguard/entrypoint.sh`,
never invented fresh): every 5s, read every `routes/*.conf` file, `ip
route replace <subnet> via <gateway>` per line. No routing protocol, no
extra daemon, no sidecar — genuinely just files + a loop. This REPLACED,
not layered onto, the old per-container mechanisms: `docker/freeradius/
entrypoint.sh`'s `refresh_coa_routes()` (renamed
`refresh_openvpn_coa_route()` — its WireGuard half is gone, OpenVPN half
untouched, out of scope), and `TR069_MANAGEMENT_GATEWAY`/
`OLT_MANAGEMENT_GATEWAY` env-var-driven static routes in `docker/
genieacs/entrypoint.sh`/`docker/librenms/route-init.sh` (both env vars
removed from these 4 containers' own `environment:` blocks entirely —
they're still read by `docker/wireguard/entrypoint.sh` for a completely
different, still-current purpose: the WG NODE's own AllowedIPs widening/
firewall exceptions, untouched by this change).

**Verified for real, container by container, not just "the code looks
right"**:
- `librenms-dispatcher`/`librenms`: fragment written correctly, route
  applied (`10.1.0.0/20`/`10.168.100.0/24`/NAS `/32` all present, correct
  node). SNMP to the 3 OLTs still times out at this point in the work —
  the SAME pre-existing, already-documented "arrives, no reply" gap (see
  "LibreNMS OLT Onboarding" above), explicitly Bagian 3 scope, not a
  regression from this change. (Later resolved as TWO stacked causes,
  firewall then SNMP credentials — see that section's own final account,
  written after this one.)
- `genieacs-nbi`/`genieacs-cwmp`: route applied correctly. Connection
  Request's own immediate response (`connection_request_ok: false`) was
  NOT trusted as proof either way (known async-signal limitation, see
  v0.7.7 section) — instead verified via real packet-transit evidence:
  the NAS's peer `rx` counter on the live node measurably increased
  right after triggering a CR. **Separate, pre-existing anomaly found
  and explicitly NOT chased down (out of scope)**: all 20 sampled CPE
  devices stopped sending periodic Informs at nearly the identical
  timestamp roughly a day before this work — real, but CPEs reach
  `genieacs-cwmp` via `boss-nginx`'s public port 7547, entirely outside
  the WireGuard tunnel this change touches, so it can't be this
  mechanism's doing; flagged for separate investigation, not treated as
  a Bagian 2 failure.
- `freeradius`: route applied correctly. The pre-existing `pingHost()`
  (router-INITIATED ping toward FreeRADIUS) test still fails — expected,
  that direction was never in this fix's scope (it depends on each node's
  OWN reverse-path FORWARD/NAT setup, not on FreeRADIUS's own routing
  table). What this fix actually targets — FreeRADIUS-INITIATED traffic
  toward a NAS (the real CoA/Disconnect direction) — was verified
  directly instead: `docker exec freeradius ping 172.23.195.2` (the live
  NAS's own router address) got a genuine 0%-loss, ~6ms round trip
  through the correct, currently-live node. A real CoA disconnect against
  the live production NAS was deliberately NOT triggered to verify this
  further (same standing caution as the v0.6.5 CoA section above — don't
  risk a real customer session just to observe the effect).

**Real bugs found deploying this, same "found by actually running it"
discipline as everywhere else in this file**:
1. **A stray function-rename left one call site behind**, crash-looping
   `freeradius` on first deploy (`refresh_coa_routes: not found`, exit
   127) — `refresh_coa_routes()` was renamed to `refresh_openvpn_coa_
   route()` at its definition and its boot-time call, but a SECOND call
   site inside the container's own periodic (~3s) self-healing loop
   (chmod/chgrp refresh) still referenced the old name. Fixed by updating
   that second call site too — a reminder that `grep`-ing for every call
   site, not just the definition, matters when renaming a shell function.
2. **Long-running containers silently missing `.env` keys added mid-
   session** — the exact same bug class documented multiple times
   already in this file (APP_ENV, WHATSAPP_GATEWAY_HMAC_SECRET,
   TR069_MANAGEMENT_GATEWAY), hit again here: `boss-app`/`boss-worker`/
   `boss-whatsapp-worker`/`boss-scheduler` had never been recreated since
   `WIREGUARD_NODE1/2/3_INTERNAL_IP` were added to `.env` earlier in this
   same v0.8.1 work — `services.vpn.wireguard_node_ips` silently resolved
   to all-`null`, which meant `VpnSyncRouteFragments` (running for real
   every minute via `boss-scheduler`) treated the live NAS as
   "undetectable" and DELETED its own fragment on every single tick —
   diagnosed by directly comparing `printenv | grep WIREGUARD_NODE` output
   between `boss-app` (missing just `NODE1`, added slightly later in the
   session) and `boss-scheduler` (missing all three, started earliest).
   Fixed by recreating all 4 PHP containers; confirmed stable across ~15
   real scheduler ticks (~2 minutes) afterward, not just asserted fixed.
3. **Recreating `boss-app` caused a real, if brief, P0: `boss-nginx`
   502'd on every request.** `boss-nginx`'s own `app.conf` uses a static
   `fastcgi_pass boss-app:9000` — plain nginx (not nginx-plus) resolves a
   hostname used this way ONCE, at worker startup, and does NOT
   automatically pick up a new IP when the container behind that Docker
   DNS name changes — `boss-app`'s recreate (fixing gap #2 above) gave it
   a new internal IP (`172.28.0.10`, was `172.28.0.17`), but `boss-nginx`
   (untouched, `Up 10 days`, no reason to suspect it independently) kept
   sending FastCGI requests to the OLD, now-dead IP
   (`connect() failed (111: Connection refused) ... upstream: "fastcgi://
   172.28.0.17:9000"`). `boss-app`'s own php-fpm was confirmed healthy
   the whole time (listening on :9000, `php artisan test` running fine
   inside it) — this was purely nginx's stale upstream resolution, not an
   application-level failure. Fixed with `docker compose restart
   boss-nginx` (forces fresh DNS resolution on worker restart) —
   confirmed via a real `curl` returning 200 immediately after, and no
   new stale-IP errors in the access/error logs afterward. **General
   rule for this whole codebase, not just this incident**: recreating
   `boss-app` (or any container another container's nginx/proxy config
   references by static hostname:port) should be followed by restarting
   the proxying container too, not assumed safe on its own — this had
   never come up before because `boss-app` had never actually been
   recreated mid-session until this v0.8.1 work.

## Architecture

**Containers** (`docker-compose.yml`): `boss-nginx` (reverse proxy, port 80/443) → `boss-app` (PHP-FPM,
serves requests) + `boss-worker` (`queue:work`, default queue) + `boss-whatsapp-worker` (`queue:work` on
dynamic `whatsapp-*` queues, v0.4.0) + `boss-scheduler` (loops `schedule:run` every 60s) →
`boss-postgresql` + `boss-redis` + `whatsapp-gateway` (Node.js Baileys service, v0.4.0, internal-only, no
host port) + `freeradius` (FreeRADIUS 3.2, v0.6.1, internal-only, no host port, static
`boss-network` IP `172.28.0.10`) + `freeradius-db` (Postgres 16, `radius_db` — a SEPARATE Postgres instance
from `boss-postgresql`, per BOSS-009) + `openvpn` (OpenVPN 2.6, v0.6.2), `wireguard` (WireGuard 1.0, v0.6.3),
`l2tp` (strongSwan 5.9 + xl2tpd 1.3, v0.6.3) — the four VPN protocol containers besides `boss-nginx` are the
ones with published host ports (UDP 1194/51820/500+4500+1701 respectively — real Mikrotik NAS devices dial
in from outside `boss-network` entirely, BOSS-010 exception by design; no other container exposes a host
port). All share the `boss-network` bridge network (fixed IPAM subnet `172.28.0.0/24` since v0.6.2);
`boss-app`'s `app/` directory is bind-mounted read-write, nginx mounts it read-only. `boss-app` also shares
named volumes with each VPN container (`vpn_pki`/`vpn_ccd` with `openvpn`, `vpn_wg_data` with `wireguard`,
`vpn_l2tp_secrets` with `l2tp`) — see "VPN Server Node #1 (v0.6.2)" and "Multi-Protocol VPN & Script
Generator (v0.6.3)" above for why.

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
