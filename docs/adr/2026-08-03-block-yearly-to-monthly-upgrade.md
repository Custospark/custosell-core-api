# Block Yearly → Monthly Upgrades (Prepaid-Credit Anomaly)

## Status

Adopted 2026-08-03.

## Context

A user on an **annual (yearly) plan** holds prepaid credit for the whole year.
If they upgrade to a **monthly** higher plan while their annual term still has
significant time left, the proration math produces a revenue-loss path:

- Personal yearly = **$100/yr** (paid upfront, ~full year remaining).
- Professional monthly = **$54/mo**.
- `credit_usd = 100 × (daysRemaining / daysInPeriod) ≈ $100`.
- `charge_usd = $54`.
- `proration_due = max(0, 54 − 100) = $0`.

The user gets the higher plan with **$0 due** while the company holds their $100
and still owes ~$100 of service — i.e. the user would effectively be demanding
money back (or getting a higher plan for free). This is not a valid upgrade path.

## Decision

**Reject a yearly→monthly upgrade ONLY when the remaining annual credit exceeds
the target monthly charge.** This is an amount-based guard, not a blanket cycle
ban.

- `credit_usd` = prorated unused credit from the current annual plan.
- `charge_usd` = the new plan's monthly price (the amount the user would start
  paying).
- Block when `credit_usd > charge_usd` — this is the only situation where the
  user holds more prepaid value than the new monthly plan costs, i.e. the
  revenue-loss / chargeback path.
- Allow when `credit_usd <= charge_usd` — e.g. $20 unused credit → $35/mo plan:
  the user pays the $15 difference and the company keeps the prepaid value
  working toward the new plan. No money is demanded back.

Enforcement points (backend is authoritative; frontend surfaces the message):

1. **`SubscriptionController::upgrade()`** — computes the quote, then `422` when
   `current_cycle === 'yearly' && requested_cycle === 'monthly' && credit > charge`.
2. **`SubscriptionController::prorationQuote()`** — same guard, so the UI shows
   the rejection message when a blocked monthly quote is requested.
3. **Frontend `UpgradeFlowModal` / `UpgradeFlowConfirmStep`** — the monthly
   option stays available; if the backend rejects, the backend's message is
   shown on the quote error state.

A **monthly** subscription upgrading to a **monthly** higher plan is unaffected.
A yearly→yearly upgrade is unaffected.

## Why Amount-Based Instead of a Blanket Ban

A blanket "no yearly→monthly upgrades" was too strict. A user near the end of
their annual term (tiny remaining credit) switching to a *more expensive* monthly
plan is legitimate — they pay the difference. The anomaly only exists when the
unused credit is *larger* than the new monthly charge, so the rule is scoped
exactly to that case.

## Files Changed

| File | Change |
|------|--------|
| `Backend/.../SubscriptionController.php` | Adds `assertUpgradeAllowed()` (credit vs charge); called in `upgrade()` and `prorationQuote()` after the quote is computed |
| `Backend/tests/.../YearlyToMonthlyUpgradeBlockTest.php` | Feature tests: block (large credit), allow (small credit), yearly allowed, quotes |
| `Frontend/.../UpgradeFlowModal.tsx` | Keeps monthly selectable; surfaces backend rejection message via `quoteErrorMessage` |
| `Frontend/.../UpgradeFlowConfirmStep.tsx` | Shows backend message on quote error; monthly option always available |

## Verification

- Feature test `YearlyToMonthlyUpgradeBlockTest` (6 cases) + `ProrationAccuracyTest`
  all pass (16 tests, 58 assertions).
- Live (business 6, oscar2): `proration-quote?to_plan_id=8&billing_cycle=monthly`
  → `422` (credit $100 > charge $135); `billing_cycle=yearly` → `200`.

## Consequences

- Only the true anomaly is blocked: unused credit exceeding the new monthly charge.
- Legitimate upgrades to a more expensive monthly plan (e.g. $20 credit → $35/mo)
  remain available.
- Backend remains authoritative — a hand-crafted blocked monthly request is
  rejected even if the frontend is bypassed.
