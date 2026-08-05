# Owner business seeder (Enterprise, Dec 2030)

- **Date:** 2026-08-05
- **Status:** Accepted
- **Stack:** Backend (Laravel seeder)

## Decision

Add `PromoteOwnerBusinessSeeder` that guarantees the Custosell owner business is
attached to `oscar@custospark.com`, on the **Enterprise** plan, with
`next_billing_date` through **2030-12-31**. It replaces the legacy
`info@custospark.com` owner account and mirrors the TestBusinessSeeder pattern.

## Behavior

- **Environments:** runs only in `production` and `local` (skipped elsewhere).
- **Idempotent:** safe to re-run via `php artisan db:seed` or `--class=PromoteOwnerBusinessSeeder`.
- **Account resolution (in priority order):**
  1. Existing user `oscar@custospark.com` → reused.
  2. Legacy user `info@custospark.com` → email renamed to `oscar@custospark.com`.
  3. Neither exists → creates the owner user (password `Password123`, business
     account, full modules incl. estimates_full/hr_full, owner role).
- **Business resolution (in priority order):**
  1. Owner's current business (`users.business_id`).
  2. Business the owner already owns (`businesses.owner_id`).
  3. Business whose `email` is `info@custospark.com` → adopted + email updated.
  4. None → creates a "Custospark" business (UGX, active) + default document cabinets.
- **Subscription:** upserted to Enterprise (yearly, ACTIVE, trial used, onboarding
  fee paid), `next_billing_date = 2030-12-31`.
- **Role:** owner granted `platform-admin` (matches test-business parity).

## Files

- `database/seeders/PromoteOwnerBusinessSeeder.php` — new seeder.
- `database/seeders/DatabaseSeeder.php` — registered for `production`/`local`.

## Failure states

- Enterprise plan missing → seeder aborts with a clear message (run PlanSeeder first).
- `oscar@custospark.com` taken by a non-owner account → reused as-is (no ownership
  takeover); the business still gets Enterprise through Dec 2030.
- Re-runs never duplicate: user/business/subscription all use update/create paths.
