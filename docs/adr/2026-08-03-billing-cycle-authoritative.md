# Billing Cycle Is Authoritative Server-Side (Monthly/Yearly Charging)

## Status

Adopted 2026-08-03.

## Context

When a user chose the **Yearly (annual)** plan, the payment provider was sent the
**monthly** amount and a "monthly" description. The plan UI correctly showed the
yearly price, but the backend charged (and recorded) the monthly price. This was a
direct **revenue loss** and created inconsistent billing records.

## Root Cause

The user's chosen billing cycle was never communicated from the frontend to the
backend. The backend then fell back to the subscription's **stored** `billing_cycle`
column, which was set at subscription creation.

The cycle resolution diverged across three backend sites:

- `GatewayService::initiatePayment()` - computed the *authoritative amount* using
  `$subscription->billing_cycle`.
- `PaymentValidator::validatePaymentAmount()` - computed the *expected* amount using
  `$subscription->billing_cycle`.
- `InitiatesGatewayPayments` - built the provider description/log using
  `$subscription->billing_cycle`.

Additionally, the frontend `useInitiatePayment()` never sent a `billing_cycle` field,
so the request carried no information about whether the user picked monthly or yearly.

## Decision

1. **Thread `billing_cycle` end-to-end.** The frontend payment modals send the user's
   chosen cycle (`billing_cycle: 'monthly' | 'yearly'`) in the
   `POST /api/v1/billing/payments/initiate` request.

2. **Backend resolves the effective cycle once and reuses it.** Both
   `GatewayService` and `PaymentValidator` resolve the effective cycle with the same
   rule and use it for the authoritative amount, the expected/validated amount, and
   the provider description/log. This guarantees the amount shown, the amount
   validated, and the amount sent to the provider always agree.

3. **Renewals always follow the stored cycle.** A renewal must not let a UI toggle
   silently change a user from yearly to monthly (or vice versa). For
   `payment_type = renewal`, the effective cycle is always the subscription's stored
   `billing_cycle`, regardless of what the request says. Yearly→monthly flips are only
   legitimately done through the dedicated billing-cycle-change flow
   (`billing_cycle_change` with proration).

4. **Subscription cycle persisted on confirmation.** On payment approval for
   `subscription` and `renewal` types, if the paid cycle differs from the stored one
   (e.g. a suspended yearly user re-subscribes yearly while stored as monthly), the
   paid cycle is persisted onto the subscription via
   `SubscriptionService::applyBillingCycleChange()`. This keeps
   `next_billing_date` and future renewals aligned with what was actually paid.

## Behavior by Payment Type

| Type | Effective cycle | Amount source | Result |
|------|-----------------|---------------|--------|
| `onboarding` | Request (irrelevant - fixed fee) | `plan.onboarding_fee_usd` | Cycle-independent one-time fee |
| `subscription` | Request cycle (chosen in UI) `??` stored | `plan.price_yearly_usd` or `price_monthly_usd` | Charges what the user selected |
| `renewal` | **Stored cycle only** | `plan.price_yearly_usd` or `price_monthly_usd` | Cannot silently change contract |
| `upgrade_proration` | Request cycle (via metadata) | Frontend proration (validated) | Unaffected; proration-based |
| `billing_cycle_change` | Target cycle stored in metadata | Proration quote (validated) | Unaffected; dedicated flow |

## Effective-Cycle Rule (shared)

```
effectiveCycle =
    payment_type === 'renewal'
        ? stored_billing_cycle
        : request_billing_cycle ?? stored_billing_cycle
```

## Files Changed

| File | Change |
|------|--------|
| `Frontend/.../SubscriptionQueries.ts` | `useInitiatePayment()` accepts and sends `billingCycle` |
| `Frontend/.../SubscriptionPaymentModal.tsx` | Sends the chosen `billingCycle` in the initiate payload |
| `Backend/.../InitiatePaymentRequest.php` | Accepts optional `billing_cycle` rule (`in:monthly,yearly`) |
| `Backend/.../PaymentController.php` | Forwards `billing_cycle` from the request |
| `Backend/.../GatewayService.php` | Resolves effective cycle; uses it for the authoritative amount + payment metadata |
| `Backend/.../PaymentValidator.php` | Resolves the same effective cycle for the expected amount |
| `Backend/.../InitiatesGatewayPayments.php` | Uses effective cycle for provider description + debug log |
| `Backend/.../HandlesPaymentApproval.php` | Persists the paid cycle onto the subscription for `subscription`/`renewal` |

## Verification

- Tinker check: yearly chosen → amount `$100` (was `$10`); no choice / renewal → falls
  back to stored cycle.
- End-to-end yearly flow: provider payload `{"amount":100.0,"currency":"USD","billing_cycle":"yearly"}`.
- Post-payment DB state: `billing_cycle = yearly`, `next_billing_date` = now + 1 year,
  payment recorded at `$100.00`, `payment_type = subscription`, `status = completed`.

## Consequences

- Correct charge is sent to the provider for the user's chosen cycle.
- Provider description, validated amount, recorded amount, and renewal cycle all agree.
- Renewal contracts cannot be silently changed (yearly→monthly) through a toggle.
- Fixes the earlier symptom: "Payment amount USD 100 does not match expected amount
  USD 10" - the expected amount now honors the chosen cycle.