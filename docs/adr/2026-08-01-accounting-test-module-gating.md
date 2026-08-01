# ADR-019: AccountingTest + ReportPeriodRangeTest fixed — plan-gated modules, date rotation, response wrapping

**Date:** 2026-08-01
**Status:** Accepted

## Context

`AccountingTest` (20) and `ReportPeriodRangeTest` (3) failed pre-existing. Root causes, once the missing `subscription.active` setup was added (ADR-018 pattern):

1. **Module plan gating** — accounting routes are also guarded by `module:accounting`. `ModuleAccessService::planAllowsModule` requires `plan->features['accounting'] === true`. The default `ensureSubscription` plan (`essential`) does not include accounting, so every call still returned 403. Only `enterprise` (and `personal`) include it.
2. **Date rotation** — `AccountingTest` seeded a static **July 2026** period and hard-coded `2026-07-15/16` journal dates while the suite now runs in **August 2026**. `current_period_returns_period` (which calls the `whereDate`-fixed `getCurrentPeriod`, ADR-018) and entries dated outside the seeded period would fail. The fixed-assets chart also lacked account **1203**, so `can_create_fixed_asset` crashed with `ErrorException` (null `->id`).
3. **Unwrapped single-resource responses** — `ChartOfAccountController::store` and `AccountingPeriodController::store/close/reopen` returned `response()->json(new Resource)`. This bypasses Laravel's `ResourceResponse` auto-wrap, so the body had **no `data` key** (`data.code` / `data.is_closed` were null). Every other single-resource response in the codebase (e.g. `JournalEntryController`) wraps in `['data' => ...]`, and the frontend (`useCreateChartOfAccount`, `useClosePeriod`) reads `data.data`.

## Decision

- Both test classes subscribe to the **`enterprise` plan** via `ensureSubscription($business->id, Plan::where('slug', 'enterprise')->first()?->id)`.
- `AccountingTest` seeds a **current-month** period (`now()->startOfMonth()/endOfMonth()`, name `now()->format('F Y')`) and derives journal/fixed-asset dates from `now()` (`startOfMonth()+7/+8`), removing the July-2026 hard-coding; added chart accounts **1200 / 1203**.
- Fixed production response shape: `ChartOfAccountController::store` and `AccountingPeriodController::store/close/reopen` now wrap resources in `['data' => ...]` (matches `JournalEntryController`, matches the frontend contract).
- `JournalEntryService::createEntry` now throws `ValidationException` (→ **422**) for unbalanced entries instead of `RuntimeException` (→ 500); `JournalEntryServiceTest::test_rejects_unbalanced_entry` updated to match the validation contract.

## Tests

- `AccountingTest` — 21/21 (92 assertions)
- `ReportPeriodRangeTest` — 3/3 (10 assertions)
- `JournalEntryServiceTest` — 4/4
- `BoardProgressTest`, `ForecastingAccountingCorrectnessTest`, `PipelineTest`, `ReportTest` failures verified **pre-existing on HEAD** (identical with these changes stashed).

## Consequences

- Accounting API single-resource mutations now consistently return `{ data: ... }` — a real production fix matching the frontend contract.
- Unbalanced journal entries return a validation `422` instead of a server `500`.
- Tests no longer rot with the calendar month.
