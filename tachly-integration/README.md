# TachlyIntegration

First-party automation layer for [Gäld](https://gaeld.ch) Community Edition
(AGPL-3.0), closing the `/api/v1` gaps Tachly needs to provision a club's
Gäld Organization without a human in the web UI or `php artisan tinker`:
org creation, chart-of-accounts extension, bank-account creation, and
org-scoped API token bootstrap/rotation.

It is not a fork of `Scanix/Gaeld` — it's a small directory of new files
layered into the image at build time (see `../Dockerfile`), landing inside
Gäld's own `App\Domains\` namespace convention so it autoloads via the
existing `composer.json` PSR-4 rule with zero Composer changes. The only
upstream file touched at all is `bootstrap/providers.php`, and even that is
a full-file replacement (see `bootstrap/providers.php` in this directory),
not a patch — so the diff against upstream is always one plain, reviewable
file.

## Why this exists

The Gäld evaluation (`../docs/gaeld-migration-evaluation.md`) found the
public API is read-mostly for exactly the things a per-club provisioning
flow needs to write. Rather than wait on the vendor (a request for these is
already drafted at `../docs/api-feature-request-draft.md`), this package
implements them directly against Gäld's own internal Eloquent
models/services — the same ones the web UI itself calls.

## AGPL

Gäld CE is AGPL-3.0-or-later. This package is a modification bundled into a
network service (`gaeld.tachly.app`) that indirectly serves outside users
through Tachly — squarely within §13's source-availability trigger. **This
directory is published as its own public repository** rather than kept
private, both to satisfy that obligation cleanly and because several pieces
(account/bank-account creation, first-token bootstrap) are close enough to
upstreamable that filing them as real PRs against `Scanix/Gaeld` is
plausible.

## What it adds

- `app/Domains/TachlyIntegration/TachlyIntegrationServiceProvider.php` —
  registers everything below; loads its own migrations and routes so no
  other upstream file needs touching.
- `Http/Controllers/ProvisionController.php` + `Services/ClubProvisioningService.php`
  — `POST /internal/tachly/organizations` (idempotent — safe to retry with
  the same `tachly_club_id`), `POST /internal/tachly/organizations/{id}/rotate-token`,
  `POST /internal/tachly/organizations/{id}/send-login-link` (triggers Gäld's own
  password-reset email to the org's owner user, so a club admin can get a real,
  working Gäld login for the web-UI-only screens — reports, reconciliation. The
  owner's password is a throwaway random string until this is called; Tachly never
  sees or stores whatever password they end up choosing), and
  `DELETE /internal/tachly/organizations/{id}` (ops-only — permanently,
  irreversibly hard-deletes the org and everything under it via cascading FKs;
  no Tachly UI calls this. Deliberately a real `forceDelete()`, not the soft-delete
  Gäld's own `DeleteOrganizationAction` uses elsewhere — a soft-deleted org is
  still found by this same package's `provision()` lookup and would be silently
  reused instead of provisioning fresh).
- `Http/Middleware/VerifyInternalSharedSecret.php` — gates the whole
  `/internal/tachly/*` group with a shared secret (`TACHLY_INTERNAL_SHARED_SECRET`
  env var), never the public `/api/v1` auth path.
- `Http/Middleware/BlockPublicRegistration.php` — appended to the `web`
  middleware group in `boot()`; blocks upstream's own `/register` **and**
  `/setup` (GET+POST, by route name). This instance is Tachly-managed only.
  Community Edition leaves `/register` wide open unless `FEATURE_SAAS` is on
  (which this deployment deliberately isn't), and `/setup` — which creates
  the first user *and* Organization and logs them straight in — is gated
  only by `Organization::exists()`, meaning it opens up the moment the org
  count hits zero (e.g. right after `deprovision()` clears test data).
- `Console/Commands/ProvisionClub.php` — the same provisioning logic as an
  `artisan tachly:provision-club` command, for ops/disaster-recovery from
  the Coolify web terminal when Tachly's server can't be reached.
- `Console/Commands/VerifyCompat.php` — run `artisan tachly:verify-compat`
  once after every `GAELD_VERSION` bump, before the new image reaches
  `gaeld.tachly.app`. Asserts every upstream class/method/column/provider
  this package depends on still exists with the expected shape. **This does
  not replace a real second-org provisioning test after a version bump** —
  it only catches "the shape changed", not "the behavior changed".
- `database/migrations/` — adds a nullable, unique `tachly_club_id` column
  to `organizations` (the external key linking a Gäld org back to its
  Tachly club).
- `Services/TachlyInvoicePdfRenderer.php` + `Support/TachlyInvoicePdfStyle.php`
  (TAC-228) — Tachly-branded invoice PDFs (navy `#0F172A` / sky-blue `#5BB8E8`,
  Tachly logo instead of `$organization->logo_path`). Bound over the upstream
  `InvoicePdfRenderer` in the service provider's `register()` — every invoice
  PDF this instance generates, for every org, renders with Tachly's brand
  (correct here: this instance exists specifically for Tachly clubs). Not a
  thin override: `InvoicePdfRenderer`'s `$locale`/`t()` are `private`, and every
  `render*` method references `InvoicePdfStyle`'s color constants inline, so
  this is a full reimplementation, not a one-method patch — see the class's
  own doc comment. Column widths/positions/fold-marks are kept identical to
  upstream (Swiss letter-fold + QR-bill layout, not branding). Reaches this
  invoice PDF endpoint via the **public** `GET /api/v1/invoices/{id}/pdf` —
  confirmed live in the actual Gäld source that this is *not* web-UI-only
  (an earlier eval doc said otherwise; that was stale) — so no new internal
  route was needed for this.

## A non-obvious wiring detail

`PersonalAccessToken` stamps `organization_id` from the bound
`CurrentOrganization` service **at insert time** (the column is `NOT NULL`,
and the real web flow gets this from `EnsureApiOrganization`/
`EnsureHasOrganization` middleware). This internal route group has no such
middleware, so `ClubProvisioningService::mintOrgToken()` binds
`CurrentOrganization` explicitly before calling `createToken()` — omitting
this fails with a `NOT NULL` constraint violation on `personal_access_tokens.organization_id`.
Found by testing against a real throwaway stack before this ever touched
`gaeld.tachly.app` — see the M2 verification steps in the productionization
plan.

## Testing

Never test against `gaeld.tachly.app` — it holds GlaStar Flyers' real,
reconciled 2026 books. Build and run a throwaway local stack instead
(`docker compose -p <scratch-name> up --build`, with a host port published
for `web` via a local override file), provision two synthetic
organizations, and confirm zero cross-org data leakage across accounts,
bank accounts, and journal entries before trusting any change here.
