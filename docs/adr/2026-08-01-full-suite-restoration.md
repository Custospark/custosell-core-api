# ADR-022: Full-suite restoration to 608 green - module-gating test setup, storefront-buyer contract, HR test split

**Date:** 2026-08-01
**Status:** Accepted

## Context

The full suite (`php artisan test`) had **110 failures** across ~21 classes, in three buckets:

1. **~20 sweep shape regressions** - endpoints wrapped to `{data: ...}` in ADR-021's sweep, but their tests (Subscription/Shift/Stock/Invoice/Referral) were never re-run afterward.
2. **~78 pre-existing 403s** - routes gated by `EnsureActiveSubscription` + `EnsureModuleAccess` (and `EnsureBusinessActive`) but test setUps never created a subscription, so every request returned 403.
3. **~10 value mismatches** - LedgerServiceTest double-posting, SupplyChainTest/StorefrontTest stale expectations, InvoiceCreateSaleLinkTest send 404.

## Decision

### Bucket 2 - subscription setup in test setUps

Added `ensureSubscription(...)` to 10 feature setUps that lacked it, using the plan the route gate requires:

- `enterprise` plan for HR / documents / forecasting / company-assets routes (their modules are enterprise-gated).
- default (essential) for everything else (sales, inventory, tax, purchase orders, user mgmt).

Classes: `UserTest`, `HrModuleTest`, `ForecastingModuleTest`, `ProductServiceSalesTest`, `CompanyAssetsTest`, `EfrisFiscalizationTest`, `CustomerContactResolveTest`, `CustomerDocumentEmailTest`, `TaxTest`, `SupplyChainReceiveAndPartyTest`.

### Bucket 1 - sweep shape corrections in tests

Updated remaining flat assertions to `{data: ...}` in `ShiftTest`, `StockMovementTest`, `SubscriptionBillingTest`, `ReferralBillingTest`, `SubscriptionTest`, `InvoiceCreateSaleLinkTest`, `TaxTest`, `ProductServiceSalesTest`, `SupplyChainTest`, `SupplyChainReceiveAndPartyTest`, `UserTest`.

Two endpoints were discovered to still be **flat by design** and their tests corrected to match:

- `POST /invoices/{id}/email` returns the raw `DocumentEmailService::sendInvoice()` result (`email_sent_count` at top level), not a wrapped resource.
- `POST /auth/register` embeds `new UserResource($user)` in a `{user, token}` array, which serializes flat (`user.business_id`, not `user.data.business_id`).

### Storefront-buyer contract (product logic)

`UserService::register` created a personal workspace + subscription for `storefront_buyer` accounts (shared `$isPersonalType` branch). The frontend `StorefrontAuthPanel` copy is explicit - *"Shop as a customer - no business setup"* - so storefront buyers are now account-only:

- `register` no longer creates a workspace business for `storefront_buyer` (only `account_type === 'personal'` does).
- `UserResource::resolveModules()` returns `[]` for personal accounts **without** a business (storefront buyers). Regular personal accounts with a workspace keep `['account','guide','discover', ...plan features]`.
- Frontend `getAccessibleModules` unconditionally seeds `['account','guide','discover']` client-side, so storefront buyers keep shell access to Account / Guide / Discover & My Orders regardless of the backend `modules` field - no FE change required.

Also exposed `default_vat_rate` on the user resource business section (`UserResource`), which the FE already reads in `businessAuthSync.ts` (`business.default_vat_rate`).

### Bucket 3 - value-mismatch fixes

- **LedgerServiceTest**: `JournalEntryService::createAndPostEntry` already calls `postEntryToLedger` internally, so the test's extra `postEntryToLedger` call double-posted every line (2x amounts: 4000 vs 2000, 10000 vs 5000, 6000 vs 3000). Removed the redundant calls.
- **BusinessAccountDeletionTest**: the revoked-token assertion was hitting Laravel's in-process auth-guard cache - a subsequent request re-resolved the *cached* user instead of re-validating the (deleted) token. `$this->app['auth']->forgetGuards()` between requests makes it re-resolve; token deletion itself was already correct.
- **PipelineBoardProgressService**: Refactor 4 moved `resolvePeriod()` into `PipelineBoardProgressPeriodService`; `HrPerformanceService` still called it on the facade. Added a `resolvePeriod()` forwarder on the facade (this was an active regression - 500s on `/hr/talent/performance`).

### File-size-500 - HrModuleTest split

`HrModuleTest.php` was 1048 lines. Per the non-negotiable file-size rule it was split (never gutted) into 4 classes, each reusing the same setUp + `authJson` helper (which calls `forgetGuards()` between requests):

- `HrModuleTest` (500) - org, employees, accounts, clock-in/out, leave request, compensation, permissions.
- `HrPerformanceTest` - talent/performance roster, snapshot, by-user, seed-review.
- `HrPayrollTest` - pay-run post/settle/remit/void, leave-type CRUD, structure/compensation delete, draft pay-run update/delete.
- `HrPayrollAffordabilityTest` - affordability cash-vs-burn, hire scenario, 403/422 guards.

## Tests

- `composer vera:fast` - passed (php -l + 6 logic rules, incl. file-size-500).
- `php artisan test` - **608 passed, 1 skipped, 0 failed** (was 110 failures).

## Consequences

- The 403 bucket confirms the module/subscription gating is working as intended; the tests now model real subscribers.
- Storefront buyers no longer accumulate phantom personal workspaces/subscriptions; their register response reports no business and no modules.
- HR performance endpoints no longer 500 (facade forwarder).
- Future test setUps must create a subscription before exercising gated routes - the `ensureSubscription` helper in `Tests\TestCase` is the canonical way.

## Related

- ADR-019/020 - pipeline progress refactor (Refactor 4 split).
- ADR-021 - response-shape `{data: ...}` sweep; this ADR closes the resulting test debt.
