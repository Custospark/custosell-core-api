# ADR - Chart of accounts auto-seeded on account creation

- **Date:** 2026-08-11
- **Status:** Accepted
- **Stack:** Backend (no DB migration - reuses the existing `DefaultAccountingTemplateSeeder` template).

## Context

A new personal or business account had an **empty chart of accounts** until the platform ran the full `DefaultAccountingTemplateSeeder` (a manual `db:seed` step over `Business::all()`). New users opening the Accounting module saw no accounts, and services that rely on the default template codes (`config/accounting.php`: `cash` 1101, `sales_revenue` 4100, `salaries_payable` 2110, …) could not resolve accounts for a freshly registered business.

## Decision

1. **Seed on registration, in the same transaction that creates the business.**
   - `App\Services\UserService::register` (personal workspace flow) calls `ChartOfAccountService::seedDefaultTemplate($business->id)` immediately after `Business::create(...)`.
   - `App\Services\BusinessService::register` calls the same right after the business row is created.
   - Both are inside the registration `DB::transaction`, so a chart-seeding failure rolls the whole registration back.

2. **Expose single-business seeding.**
   - `DefaultAccountingTemplateSeeder::runForBusiness(int $businessId)` (new public method) seeds only that business and returns the number of accounts created.
   - `ChartOfAccountService::seedDefaultTemplate(int $businessId)` (previously dead/buggy - it called `run()`, which seeded **every** business) now calls `runForBusiness`.
   - Seeding is idempotent per `(business_id, code)` - safe to re-run.

3. **`db:seed` runs the backfill command, once.**
   - `DefaultAccountingTemplateSeeder::run()` no longer duplicates the loop; it invokes `accounting:seed-chart-of-accounts` (via `$this->command->call(...)`, falling back to `Artisan::call`). So `php artisan db:seed` seeds the chart of accounts for every business that does not yet have one - accounts that already have a COA are skipped and reported as up-to-date.
   - The command `accounting:seed-chart-of-accounts {--business=}` is also available standalone (legacy backfill); it reports how many accounts each business gained and how many were already up-to-date.

## Why not the `Business::created` model event

Document cabinets use a `created` model hook (`DocumentCabinetService`), but the COA template carries a **unique `(business_id, code)`** constraint. Hooking the model event would auto-seed 82 accounts on every `Business::factory()->create()` in tests, colliding with the many test suites that hand-create their own chart rows (e.g. `AccountingTest`, `RatioServiceTest`, `FinancialStatementServiceTest`) - a wide test ripple. Seeding at the two registration entry points covers exactly the user-facing requirement (new personal + business accounts) with no test breakage, and the legacy command covers pre-existing businesses.

## Consequences

- New personal and business accounts get a full default chart immediately; the Accounting module and the `config/accounting.php` codes work out of the box.
- `storefront_buyer` (shopping) accounts create no workspace, so no chart is seeded for them.
- Idempotent seeding means re-running `db:seed` or the new command never duplicates accounts; businesses that already have a COA are skipped.
- Test coverage: `AuthTest::test_personal_registration_seeds_chart_of_accounts` and `BusinessTest::test_business_registration_seeds_chart_of_accounts`.

## References

- `app/Services/UserService.php` / `app/Services/BusinessService.php` - registration seeding.
- `app/Services/ChartOfAccountService.php::seedDefaultTemplate`.
- `database/seeders/DefaultAccountingTemplateSeeder.php::runForBusiness`.
- `app/Console/Commands/SeedChartOfAccounts.php`.
- `tests/Feature/AuthTest.php`, `tests/Feature/BusinessTest.php` - regression tests.
