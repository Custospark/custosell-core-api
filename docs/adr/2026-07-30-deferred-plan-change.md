# ADR: Deferred Plan Change - Plan ID Updated on Payment Confirm, Not on Upgrade Request

## Date
2026-07-30

## Status
Accepted

## Context
When a user upgrades their plan (e.g., from Essential to Professional), the subscription `plan_id` was being updated immediately on the database during the `POST /api/v1/billing/upgrade` endpoint. Payment collection happened as a separate, subsequent step. This created a window where:

1. The user's `plan_id` reflected the upgraded plan before payment cleared.
2. If payment failed (STK push declined, timeout, insufficient funds), the subscription was already on the new plan - the user could access dashboard features gated on `plan_id` without having paid.
3. The payment confirmation callback (`handleUpgradeProration`) had a guard that checked whether the subscription was already on the target plan and skipped the plan change, assuming it was already applied.
4. For the OnboardingPage flow (subscribe to plan A, switch to plan B, pay onboarding fee), the `upgrade` endpoint changed `plan_id` but the payment was only for the onboarding fee - the plan upgrade was effectively granted without additional payment.

A similar issue existed in the `handlePaymentType` onboarding path: when a user subscribed to plan A, then upgraded to plan B before paying the onboarding fee, the callback never updated `plan_id` because it assumed the current plan was correct.

## Decision
Defer all `plan_id` mutations from the upgrade endpoint to the payment confirmation callback. The upgrade endpoint becomes a **quote-only** endpoint: it validates the request, computes the proration, and returns it - but does not mutate the subscription.

| Step | Before (problem) | After (fix) |
|------|-------------------|-------------|
| User requests upgrade | `plan_id` updated immediately, `schedulePlanChange()` called | `plan_id` unchanged, no schedule created |
| Payment collected | Separately, after plan already changed | Only payment is collected; plan still on old value |
| Payment confirms | `handleUpgradeProration` sees `plan_id` already matches, skips change | `handleUpgradeProration` applies `changePlan()` because `plan_id` still differs |
| Payment fails | User has upgraded plan without paying | User's plan unchanged, blocked from upgraded features |

For the onboarding path (`handlePaymentType('onboarding')`):
- The callback now reads `metadata.plan_id` from the payment record.
- If `metadata.plan_id` differs from the subscription's current `plan_id`, it calls `changePlan()` before `activateAfterOnboarding()`.
- This covers users who subscribe to plan A, switch to plan B on the OnboardingPage, then pay.

## Pros

1. **Atomic plan change with payment** - The `plan_id` updates in the same database transaction as the payment confirmation. Either both succeed or both fail. No window exists where the plan is upgraded without payment.
2. **Correct retry behavior** - If payment fails, the subscription is in its original state. Retrying the same upgrade flow works identically: same quote, same plan comparison, same payment.
3. **Reconciliation is single-source** - The billing_payments record, with its `metadata.plan_id`, is the single source of truth for what plan the user intended to buy. The subscription's `plan_id` always reflects what was actually paid for.
4. **Simpler callback logic** - The `handleUpgradeProration` no longer needs the "already on target plan" guard because that state never occurs. If the callback runs, the plan change is always needed.
5. **Covers onboarding+upgrade edge case** - The `handlePaymentType` onboarding path now handles the case where a user changes plan before paying, closing a gap where users could get a higher-tier plan for the original plan's onboarding fee.

## Cons

1. **Quote is potentially stale** - Between the time the upgrade endpoint returns a proration quote and the payment callback runs, the proration amount could change (e.g., if the billing period advances). This was already a risk before; it is unchanged by this decision.
2. **`upgrade` endpoint has no side effect** - Calling the endpoint without completing payment leaves no trace. This could be confusing operationally (no `scheduled_changes` record for an attempted upgrade).
3. **Frontend must pass `to_plan_id` in payment metadata** - The payment callback for onboarding needs to know which plan to activate. This requires the frontend to include `plan_id` in the payment metadata payload. Failure to do so results in the subscription staying on the original plan after payment confirms.
4. **Two code paths for plan changes** - `changePlan()` during onboarding (in `handlePaymentType`) and `changePlan()` during upgrade payment confirmation (in `handleUpgradeProration`) are conceptually the same operation but triggered from different contexts. If one diverges from the other behaviorally, the onboarding path could drift.

## Implementation

### Files changed

| File | Change |
|------|--------|
| `app/Http/Controllers/Api/SubscriptionController.php` | `upgrade()` no longer updates `plan_id` or calls `schedulePlanChange()` for `effective: 'immediate'`. Returns only the proration quote. |
| `app/Services/Payment/Concerns/HandlesPaymentApproval.php` | `handleUpgradeProration()`: removed "already on target plan" early return. `handlePaymentType('onboarding')`: added `metadata.plan_id` check + `changePlan()` if different from current. |

### Deployment considerations
- No migration needed (no schema change).
- All in-flight upgrade payments (initiated before deploy) will see `plan_id` already matching in `handleUpgradeProration` and will skip the plan change. This is acceptable because the controller was updating `plan_id` immediately; those pre-deploy payments are already on the new plan. Post-deploy payments will go through the new path.
- Frontend must ensure `metadata.plan_id` is included in payment initiation requests for onboarding payments. Current frontend already does this (`UpgradeFlowConfirmStep.tsx` passes `toPlanId` in metadata, and `OnboardingPage.tsx` passes `planId` in metadata).

## Revisit Triggers

| Trigger | Why |
|---------|-----|
| The product introduces a "pay later" upgrade flow where the user wants to upgrade immediately but pay at the end of the month | The current design requires payment before plan change; a "pay later" flow would need to revert to immediate plan change with a debt tracking mechanism |
| The platform adds a free trial period where users can try Professional features before paying | The upgrade endpoint would need to update `plan_id` immediately (for trial access) but delay payment; this decision would need to be reversed or extended |
| Users report "I upgraded but nothing changed" because they didn't complete payment | The lack of immediate feedback after clicking "Upgrade" could be confusing; may require polling or webhook-based UI refresh |
| The business wants to offer "instant activation" for credit card payments (vs. delayed activation for STK) | Different payment methods would need different plan-change timing, adding complexity that may tip the trade-off back toward immediate update |
| A `scheduled_changes` record becomes required for audit/analytics of upgrade attempts | The current design leaves no trace of an attempted-but-unpaid upgrade; an upgrade_attempts table or scheduled_changes entry would need to be added |

---

## Summary

This decision prioritizes **financial safety** (no plan upgrade without confirmed payment) and **correct retry semantics** (subscription state unchanged after failure) over **immediate feedback** and **audit trail**. The trade-off is that the upgrade endpoint no longer produces visible side effects, which may be surprising from an operational perspective but is safe from a revenue perspective.

This decision interacts with the existing referral discount architecture (ADR 2026-07-30-referral-discount-architecture.md): the `plan_id` determines the discount base for future payments, so ensuring it reflects paid-for plans prevents discount misapplication.
