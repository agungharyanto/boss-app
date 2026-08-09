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
prerequisite. Real GenieACS Remote Actions work should not start until the
verification above is actually done.

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
