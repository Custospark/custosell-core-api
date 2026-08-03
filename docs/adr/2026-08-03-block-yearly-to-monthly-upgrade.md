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

**Reject every upgrade request where the current subscription is on `yearly`
billing and the requested target cycle is `monthly`.** The user must stay on
`yearly` to upgrade, or change their billing cycle through the dedicated
billing-cycle-change flow.

Enforcement points (backend is authoritative; frontend prevents the flow):

1. **`SubscriptionController::upgrade()`** — `422` when
   `current_cycle === 'yearly' && requested_cycle === 'monthly'`.
2. **`SubscriptionController::prorationQuote()`** — same guard, so the UI can
   never even render a monthly upgrade quote for a yearly subscription.
3. **Frontend `UpgradeFlowModal`** — for a yearly subscription the upgrade cycle
   is forced to `yearly` (the PlansTab monthly toggle is ignored) and the
   "Monthly" option is disabled with an explanatory notice.

A **monthly** subscription upgrading to a **monthly** higher plan is unaffected.
A yearly→yearly upgrade is unaffected.

## Why Not a "Softer" Rule

We considered allowing the upgrade when the monthly charge still exceeds the
remaining annual credit. That is brittle: the anomaly is structural (annual
credit is nearly always larger than a single higher-plan monthly charge), and
allowing a $0-due higher plan invites the exact chargeback/refund scenario this
guard exists to prevent. Block the whole class of transitions.

## Files Changed

| File | Change |
|------|--------|
| `Backend/.../SubscriptionController.php` | Adds `assertUpgradeCycleAllowed()`; called in `upgrade()` and `prorationQuote()` |
| `Backend/tests/.../YearlyToMonthlyUpgradeBlockTest.php` | New feature test: block, allow yearly, quote block, monthly unaffected |
| `Frontend/.../UpgradeFlowModal.tsx` | Forces `yearly` for yearly subs; guards `onBillingCycleChange` |
| `Frontend/.../UpgradeFlowConfirmStep.tsx` | Disables "Monthly" + shows explanation when on an annual plan |

## Verification

- Feature test `YearlyToMonthlyUpgradeBlockTest` (4 cases) + `ProrationAccuracyTest`
  all pass (14 tests, 52 assertions).
- Live (business 6, oscar2): `proration-quote?to_plan_id=8&billing_cycle=monthly`
  → `422` with guard message; `billing_cycle=yearly` → `200` with correct
  proration (credit $100, charge $1350, due $1250).

## Consequences

- No more $0-due higher-plan upgrades from annual subscriptions.
- Users on annual plans keep their prepaid credit working by staying yearly.
- Backend remains authoritative — a hand-crafted `monthly` upgrade request is
  rejected even if the frontend is bypassed.
