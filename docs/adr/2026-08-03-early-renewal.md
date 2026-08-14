# Allow Active Subscriptions to Renew Early (Advance Payment)

## Status

Proposed 2026-08-03.

## Context

A subscribed user on an **active** plan has a known `next_billing_date` in the
near future. Today there is no way to pay in advance before that date arrives.
When `next_billing_date` passes, `SubscriptionStateMachineService::processDueTransitions()`
flips the subscription to `past_due` (7-day grace) or directly `suspended` if the
single `grace_used` flag is already consumed. This lockout disrupts the business
(for an offline-first POS this is especially damaging - a shop can lose access
mid-operation).

Because the system is date-driven and enforces lockout **synchronously on every
gated request** (not only via cron), even a brief lapse between payment and the
billing date can cause a disruption if a request lands after `next_billing_date`.
The natural, low-friction fix is to let an active subscriber **pay for the next
period in advance** so `next_billing_date` is always in the future.

Key observations from the current state:

- `renewSubscription()` exists (state-machine L53-72) but only accepts `ACTIVE`
  and extends **from `now`**, not from the existing schedule.
- The `renewal` payment type already exists and is amount-computed server-side
  in `GatewayService::initiatePayment()` using the subscription's stored
  `billing_cycle` (L72-85). The payment primitive is fully reusable.
- There is **no UI affordance** for renewing an active subscription: the plan
  matrix returns `{ type: 'current', label: 'Current Plan' }` for `active` rows
  (`planActionMatrix.ts` L42-46), and the payment-action resolver returns no
  payment intent for `ACTIVE` (`SubscriptionPaymentActionResolver.php` L127-142).

## Decision

**Allow an active subscription to renew in advance at any time**, extending
`next_billing_date` by a full period **from its existing value** (schedule
preserved, no proration needed). Do not wait for `past_due`/`suspended`.

### Backend

1. **New state-machine method `renewEarly(Subscription $subscription)`:**
   - Allowed only when `status === ACTIVE`.
   - Rejected (throw) when the subscription is set to cancel
     (`cancel_at_period_end`) - a cancelling subscriber should not be charged.
   - Sets `next_billing_date = nextBillingDate(current_next_billing_date, billing_cycle)`
     - i.e. adds one full period to the **existing** date, preserving the
     schedule (per Oscar's decision: extend from existing `next_billing_date`,
     not from today). Clears `grace_period_ends_at`.
   - Optionally records the pre-payment in metadata/notes for auditability.

2. **Reuse the `renewal` payment type.** `GatewayService` already computes the
   renewal amount from the stored billing cycle. No new payment type or amount
   math is required. The only wiring needed: on approval, the payment handler
   must call `renewEarly()` instead of the normal `renewSubscription()` when the
   payment is an early/advance renewal.

   - Distinguish an early renewal from an on-time renewal. Simplest: if
     `subscription->next_billing_date->isFuture()` at approval time, it was an
     early renewal → `renewEarly()`. Otherwise → existing `renewSubscription()`.

3. **Authorize frontend.** The existing `POST billing/payments/initiate` with
   `payment_type=renewal` is already valid for an active subscription server-side
   (the state-machine guard is only hit on approval). No new route needed. The
   authoritative amount stays server-side.

### Frontend changes

1. **New plan-action type `renew_early`** in `planActionMatrix.ts`, label
   **"Renew Early"** (or "Pay in Advance"). For the `active` current-plan row,
   return this actionable button instead of the inert "Current Plan".

2. **`PlanCard`** surfaces the button → opens `SubscriptionPaymentModal` with
   `paymentType='renewal'`.

3. **PlansTab banner / header** (`PlansTab.tsx` L199-225) gains a
   **"Renew early"** CTA (secondary/secondary-button) next to the
   `Next bill: {date}` so the user can act before expiry.

4. On completion, `refetchProfile()` refreshes the subscription so
   `next_billing_date` (and the expiry banner) update immediately.

## Why Extend from the Existing `next_billing_date`

Extending from the **current** `next_billing_date`:
- Preserves the original billing schedule (no drift).
- Requires **no credit/proration math** - the subscriber pays exactly one full
  period at the normal recurring price for one full period of future service.
- Avoids the revenue-loss edge cases that a "extend from today" approach would
  introduce (double-billing / overlapping periods).

## Failure States

| Scenario | Behavior |
|----------|----------|
| Subscriber not `ACTIVE` (past_due / suspended / trial / expired / cancelled) | `renewEarly` throws; only the existing on-time `renewal`/`reactivate` paths apply. UI only shows "Renew Early" for `active`. |
| Subscriber is `cancel_at_period_end` | Early renewal rejected - they shouldn't be charged for a period they intend to end. |
| Payment initiated twice (double-click / retry) | Existing `idempotency_key` + pending-payment flow already guards; a single renewal is captured. |
| Payment succeeds then webhook confirms | `next_billing_date` advanced server-side; frontend refetch reflects it. |
| Payment fails | Subscription stays `ACTIVE`, unchanged; user can retry. `SubscriptionsExpirePendingPayments` marks stuck pendings failed after 24h. |
| Both early renewal + scheduled plan change pending | Backend rejects early renewal if a pending change exists, or applies carefully (see Consequences). |
| Offline client | Renewal still requires a gateway payment (online); offline-first only affects display, not the payment itself. |

## Implementation Note (Proration)

Choice to keep scope small: early renewal is a **full-period prepayment at full
price**, no proration. If Oscar later wants "pay only the outstanding amount for
the current period", that is a separate proration feature using
`SubscriptionProrationCalculator`/`PaymentQuoteService` - out of scope here.

## Files Changed (planned)

| Stack | File | Change |
|-------|------|--------|
| Backend | `SubscriptionStateMachineService.php` | Add `renewEarly()` |
| Backend | `Services/Payment/Concerns/HandlesPaymentApproval.php` | In `handleRenewalPayment`, pick `renewEarly` vs `renewSubscription` based on `next_billing_date` future |
| Backend | `Services/SubscriptionService.php` | Expose `renewEarly` passthrough |
| Backend | `tests/Feature/Api/Billing/` | Feature tests: early renewal active, schedule preserved, blocked when not active, blocked when cancel_at_period_end, failure states |
| Backend | `docs/adr/2026-08-03-early-renewal.md` | This ADR |
| Frontend | `planActionMatrix.ts` | Add `renew_early` action for `active` current row |
| Frontend | `PlanCard.tsx` | Render "Renew Early" CTA |
| Frontend | `PlansTab.tsx` | Banner CTA + wire `SubscriptionPaymentModal` (`renewal`) + refetch |

## Consequences

- Active subscribers can pre-pay to avoid any lockout window - reducing business
  disruption (important for an offline-first POS).
- Keeps the billing schedule stable and the amount authoritative server-side.
- Requires backend + frontend BOTH changes (cross-stack) → Blue/Architect + Nora
  (smoke) + Vera both stacks per the standalone rule.
- Reuses the existing `renewal` payment type - no new enums, no new routes, small
  surface area.