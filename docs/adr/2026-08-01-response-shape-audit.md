# ADR-021: JSON response-shape audit — `{data: ...}` wrapping + confirmed store-shape fixes

**Date:** 2026-08-01
**Status:** Accepted

## Context

Cross-stack audit of API response shapes (backend `app/Http/Controllers/Api/**`, frontend `src/renderer/**`) to find `data.data` vs flat `response()->json(...)` drift.

**Key empirical finding (verified against Laravel 12.61 vendor):** `response()->json(new XResource)` serializes **flat** (no `data` key) — the `{data: ...}` wrapper only comes from implicit `return new XResource;` or `(new XResource)->response()`. So `response()->json(new Resource)` is NOT the same shape as `return new Resource;`, and `response()->json(['data' => new Resource])` is a single wrap, not a double.

## Decision

Standardize single-resource responses on `{data: ...}` (implicit resource returns or `['data' => ...]`), matching the frontend contract and the majority of the codebase. Confirmed and fixed:

1. **POST /referral-codes** (`ReferralCodeController::store`) — returned `response()->json(new ReferralCodeResource)` (flat). Frontend `useGenerateReferralCode` reads `result?.data?.code`; the generated code was never surfaced optimistically. Now wrapped: `['data' => new ReferralCodeResource(...)]`.
2. **POST /income-sources** (`IncomeSourceController::store`) — returned flat. Frontend `useCreateIncome` returns `data.data` and `IncomeForm` does `created.id` → **TypeError on create**. Now wrapped: `['data' => new IncomeSourceResource(...)]`, consistent with `show`/`update`/`index` (implicit wrapped).

Neither endpoint had tests pinning the flat shape (`ReferralBillingTest` still 2/2).

## Systemic finding (deferred debt, not fixed here)

~50 controllers still use `response()->json(new XResource(...))` → flat responses, mostly covered by dual-shape frontend normalizers, but any strict `data.data` reader against them breaks silently. Full per-call classification in the audit. Recommendations:
- Prefer `return new XResource(...);` / `(new XResource)->response()` so the wrapper applies automatically.
- Prefer `return ['data' => ...]` for JSON mutations.
- Frontend should use dual-tolerant `unwrapList`/`unwrapEntity` (already the norm in pipeline/HR/documents modules).

## Tests

- `composer vera:fast` — passed (lint + logic, file-size 500).
- `ReferralBillingTest` — 2/2 after the change.
- Frontend unchanged; `npm run vera:fast` passed.

## Consequences

- Income-source creation no longer throws; referral-code generation surfaces the code immediately.
- API contract for these two POST endpoints is now `{data: {...}}`, aligned with sibling reads and the FE mutation types.
- The ~50 flat `response()->json(new Resource)` calls remain as documented debt for a follow-up sweep.
