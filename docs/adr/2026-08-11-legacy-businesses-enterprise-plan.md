# ADR — Legacy businesses upgraded from Essential to Enterprise plan

- **Date:** 2026-08-11
- **Status:** Accepted
- **Stack:** Backend (data migration — the original backfill migration is left untouched per repo rules).

## Context

`2026_07_21_124239_create_subscriptions_for_legacy_businesses` backfilled a subscription for every business that predated the subscription system. It granted the **Essential** plan (`trial_used = true`, `onboarding_fee_paid = true`, status `trial`, 30-day trial). Essential omits accounting, HR, forecasting, documents, and other modules, so those legacy businesses could not explore what Custosell offers.

## Decision

Upgrade legacy backfilled subscriptions to the **Enterprise** plan so existing businesses can explore every module. Implemented as a **new idempotent migration** (`2026_08_11_000001_upgrade_legacy_subscriptions_to_enterprise`) rather than editing the historical migration.

The `up()` targets exactly the rows the legacy backfill inserted — `status = trial`, `trial_used = true`, `onboarding_fee_paid = true` on the essential plan — and moves them to enterprise (plan + pricing snapshot fields). New registrations never match (they are created with `trial_used = false`, `onboarding_fee_paid = false` via `SubscriptionService::subscribe`), and once upgraded the WHERE clause no longer matches, so re-running is a no-op. `down()` reverses the same signature.

## Why not edit the original migration

Repo rule 15 (AGENTS.md): existing migrations are historical records of what ran; editing them breaks reproducibility across environments. A corrective migration is the sanctioned path.

## Consequences

- Legacy businesses are placed on Enterprise and gain access to every plan-gated module immediately after `migrate`.
- Existing trial end dates, billing cycle, and next billing date are preserved (only the plan and pricing snapshot change).
- New registrations and any legacy business that later chose another plan are untouched.

## References

- `database/migrations/2026_08_11_000001_upgrade_legacy_subscriptions_to_enterprise.php`
- `database/migrations/2026_07_21_124239_create_subscriptions_for_legacy_businesses.php` (unchanged)
