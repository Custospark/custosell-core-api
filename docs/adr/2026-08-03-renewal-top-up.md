# Renewal Top-Up — Pay in Advance for Any Duration

## Status

Proposed 2026-08-03.

## Context

The first "Renew Early" iteration (`2026-08-03-early-renewal.md`) lets an active
subscription prepay **one full period** of its stored billing cycle. Oscar wants
the natural next step: renewal as a **top-up**, where the user chooses how much
to add (e.g. 3 months, 6 months, 1 year, 2 years) — still anchored to the
existing `next_billing_date` so the schedule never drifts, but with the **amount
computed correctly** for the chosen duration.

Why top-up: a monthly subscriber who wants six months of runway shouldn't be
forced into a single month (or a single year). A yearly subscriber who wants an
extra quarter shouldn't have to pay a full extra year. Top-up removes the
lockout-disruption risk (the same goal as early renewal) while giving the user
control over how far in advance they pay.

## Decisions

### 1. Top-up extends from the existing `next_billing_date`

`next_billing_date` is advanced by the chosen number of months **from its current
value**. No proration of the already-paid current period, no schedule drift.

### 2. Amount is prorated to the stored billing cycle (single consistent rate)

The unit rate always follows the subscription's stored `billing_cycle`:

- `monthly` subscription → **monthly rate** = `price_monthly_usd`. A 12-month
  top-up charges `12 × price_monthly_usd`.
- `yearly` subscription → **yearly rate**, prorated per month =
  `price_yearly_usd / 12`. A 3-month top-up charges `3 × (price_yearly_usd / 12)`;
  a 12-month top-up charges `price_yearly_usd`.

```
effectiveMonths = selectedMonths + (selectedYears × 12)
monthlyRate     = billing_cycle === 'yearly' ? price_yearly_usd / 12 : price_monthly_usd
amount          = round(effectiveMonths × monthlyRate, 2)
```

This keeps one consistent rate per subscription — no mixing of monthly and yearly
rates, no double-discount, no revenue-loss edge cases. The amount is computed
**server-side** (authoritative), never trusted from the frontend.

### 3. New `topup` payment type

A dedicated payment type keeps semantics clean and auditable, distinct from the
"one full period" `renewal`:

- `PaymentType::TOPUP = 'topup'`
- Request carries `topup_months` (integer, 1..60) in the payload.
- `GatewayService` computes the authoritative amount from `topup_months` and the
  stored billing cycle.
- `topup_months` is persisted in the payment `metadata` so the approval path
  knows exactly how much to extend.
- On approval, the state machine extends `next_billing_date` by `topup_months`.

### 4. `renewEarly()` accepts a months parameter

`SubscriptionStateMachineService::renewEarly(Subscription $subscription, int $months = null)`:

- `months = null` → default to one full stored period (monthly=1, yearly=12) —
  keeps the existing "single Renew Early" behavior working.
- Guard: only from `ACTIVE`, rejected if `cancel_at_period_end`, `months >= 1`.
- Extends `next_billing_date` from its existing value by `months`.
- Records `renewed_early_at` and `topup_months` in metadata.

### 5. Frontend: chips + live amount preview

A **top-up picker** replaces the single "Renew Early" payment for active
subscribers:

- Quick-select chips (1, 3, 6, 12 months / 1, 2 years) plus a custom input.
- Live **amount preview** as the selection changes (in the business currency,
  using the authoritative monthly rate).
- "Pay" opens the existing payment flow with `payment_type=topup` and
  `topup_months` in metadata; `refetchProfile()` on completion.

## Failure States

| Scenario | Behavior |
|----------|----------|
| Not `ACTIVE` (trial / past_due / suspended / expired / cancelled) | `renewEarly` throws; picker only shown for `active`. |
| `cancel_at_period_end` | Top-up rejected — don't charge a subscriber planning to end. |
| `topup_months` missing / < 1 / > 60 | Request validation 422. |
| Double-click / retry | Existing `idempotency_key` + pending-payment guard. |
| Payment fails / never confirms | Subscription stays `ACTIVE` with original date; `SubscriptionsExpirePendingPayments` marks stuck pendings failed after 24h. |
| Credit covers full top-up | Existing credit path applies server-side (amount computed first, then credit). |
| Offline client | Payment still requires a gateway; offline-first affects display only. |

## Files Changed (planned)

| Stack | File | Change |
|-------|------|--------|
| Backend | `app/Enums/Billing/PaymentType.php` | Add `TOPUP` case |
| Backend | `app/Http/Requests/Billing/InitiatePaymentRequest.php` | Add `topup_months` (int 1..60), allow `topup` type |
| Backend | `app/Services/Billing/SubscriptionStateMachineService.php` | `renewEarly()` takes `?int $months`, extends by months |
| Backend | `app/Services/Contracts/SubscriptionStateMachineServiceInterface.php` | Updated signature |
| Backend | `app/Services/Payment/GatewayService.php` | Compute authoritative `topup` amount from `topup_months`; persist in metadata |
| Backend | `app/Services/Payment/Concerns/HandlesPaymentApproval.php` | Handle `topup` payment → `renewEarly($sub, $months)` |
| Backend | `tests/Unit/Billing/TopUpRenewalTest.php` | Monthly & yearly amounts, extension math, guards, approval path |
| Frontend | `src/renderer/shared/types/index.ts` | Add `'topup'` to `PaymentType` |
| Frontend | `src/renderer/modules/settings/ui/RenewTopUpModal.tsx` | New picker: chips + custom + amount preview |
| Frontend | `src/renderer/modules/settings/PlansTab.tsx` | Banner/`renew_early` opens top-up picker; wire `topup` payment |
| Frontend | `src/renderer/modules/settings/planActionMatrix.ts` | `renew_early` remains, opens picker |

## Consequences

- Users control how far in advance they pay; amount is always correct and
  authoritative server-side.
- No schedule drift; single consistent rate per subscription.
- Reuses the existing gateway/payment/approval machinery — new surface is small
  and auditable via the `topup` type + `topup_months` metadata.
- Cross-stack change (backend + frontend) — both stacks verified before commit.
