# Plan identity follows payment — reactivation / resubscription plan reconciliation

**Date:** 2026-08-10
**Status:** Implemented

## Problem

When a suspended (or otherwise reactivated/resubscribed) subscription moved onto a
different plan, the subscription **kept the OLD plan it held before suspension**.

Concretely: a user who suspended as **Professional** and then paid to stay as
**Enterprise** was reactivated but remained on Professional — `plan_id`,
`price_*_usd` and `next_billing_date` all kept the pre-suspension identity. The
amount was charged against whichever plan the subscription happened to hold, so
the user was both **under-provisioned** (wrong plan access) and **mis-billed**
(old plan price for a new-plan purchase) or **blocked** (validator rejected the
amount because it disagreed with the old price).

## Root cause (three seams, none of which carried the chosen plan)

1. **Frontend** — `PlansTab.getPaymentMetadata()` returned `undefined` for the
   `reactivate` action. The plan the user clicked was only ever shown in the UI;
   it never travelled to the backend payment metadata.
2. **Backend amount resolution** — `GatewayService::resolveEffectivePlan()` and
   `PaymentValidator::validatePaymentAmount()` resolved the charged plan from
   metadata **only** for `onboarding` payments (`plan_id`). A `subscription`
   payment resolved the plan from the subscription's *current* state.
3. **Backend activation** — `HandlesPaymentApproval::handleSubscriptionPayment()`
   called `reactivate()` / `activateSubscription()`, which manipulate status,
   dates and approval fields but never touch `plan_id`.

## Decision

- **The plan the user pays for is authoritative.** Any `onboarding` or
  `subscription` payment that carries a target plan in its metadata
  (`to_plan_id`, or `plan_id` for onboarding) must move the subscription onto
  that plan **before** any activation/reactivation side effects run.
- `applyPaidPlan()` (new helper in `HandlesPaymentApproval`) reconciles
  `plan_id`, plan prices and `next_billing_date` via the existing
  `SubscriptionService::changePlan()` when the metadata target differs from the
  subscription's current plan. It is applied only for `onboarding` and
  `subscription` payment types — `upgrade_proration` already swaps plans inside
  its own `changePlan()` transaction and must not double-apply.
- `resolveEffectivePlan()` and `PaymentValidator` now honour `to_plan_id` /
  `plan_id` for `subscription` (reactivate / subscribe / resubscribe) payments in
  addition to onboarding, so charges and validations target the plan being paid
  for, never the plan accidentally still held by the subscription.
- `logSubscriptionAuditState()` now records the **resolved** subscription (after
  plan reconciliation) so the audit trail shows the plan the money bought.

## Contract

- Frontend `getPaymentMetadata('reactivate')` → `{ action: 'reactivate', to_plan_id }`
- Frontend `getPaymentMetadata('upgrade')` → `{ action: 'upgrade', to_plan_id }` (unchanged)
- Frontend `getPaymentMetadata('subscribe'|'resubscribe')` → `{ action: 'subscribe', plan_id }` (unchanged)
- Backend `subscription` payment metadata `to_plan_id` ⇒ apply that plan, then reactivate.
- Backend `onboarding` payment metadata `plan_id` ⇒ apply that plan, then onboard. (unchanged)

## Test coverage

`tests/Unit/Billing/PlanReactivationUpgradeTest.php`

- reactivate onto a higher plan → `plan_id` / prices move to the paid plan
- reactivate onto the same plan → plan identity preserved
- reactivate with no target plan in metadata → current plan retained (legacy path)
- amount validation prices a `subscription` payment against the **target** plan
  (accepts target price, rejects old-plan price)

## Out of scope / notes

- `activateSubscription()` still requires `trial | past_due | expired`
  (suspended is handled by `reactivate()`). Reactivation/resubscription from a
  `cancelled` subscription is a separate concern tracked separately.
- The offline stale-active risk already documented in the 2026-08-10
  subscription state machine audit is unaffected.