# Subscription State Machine — Transition Map and Loophole Audit

**Date:** 2026-08-10
**Status:** Accepted (audit findings; no behavior change yet)
**Author:** Backend Orchestrator (audit of `SubscriptionStateMachineService`, `SubscriptionService`, `LoginSubscription`, `PaymentController`, `GatewayService`)

## Context

Oscar asked to map every subscription status transition from creation: which statuses are set, on which trigger date, in which method — and whether the current handling has transition loopholes (users gaining access they didn't pay for, or state rows that never converge).

This ADR records the complete map and the audit verdict. It complements `2026-08-02-subscription-state-machine-date-driven.md`, which documents the intended design. This one documents what the code actually does and where the holes are.

## Status enum

`SubscriptionStatus`: `trial` · `active` · `past_due` · `suspended` · `cancelled` · `expired` (`app/Enums/Billing/SubscriptionStatus.php`).

---

## 1. Birth — `SubscriptionService::subscribe()`

Applied at `app/Services/SubscriptionService.php:91`. Sets `status`, `trial_ends_at`, `next_billing_date`, `onboarding_fee_paid=false`, `trial_used=false`.

| Plan has trial (`plan.trial_days > 0`) and not `skipTrial` | No trial / `skipTrial` |
|---|---|
| `status = TRIAL` | `status = PAST_DUE` |
| `trial_ends_at = now + trial_days` | `trial_ends_at = null` |
| `next_billing_date = now + 1mo` | `next_billing_date = now + 1mo` |
| `onboarding_fee_paid = false` | `onboarding_fee_paid = false` |

Personal-plan subscriptions are created the same way but `onboarding_fee_paid` is force-set to `true` immediately after (`UserService.php:115`), so no pay screen ever shows for personal accounts.

> The no-trial initial status is **`PAST_DUE`** — that is a "pending setup fee" state, not a payment failure. It carries no `grace_period_ends_at` and no `grace_used`, and access is denied until the onboarding fee is paid.

---

## 2. Transition map — status → trigger → method → result

Only two engine surfaces mutate status:

- **Live**: `SubscriptionStateMachineService::processDueTransitions()` — run on login/`me` (`AuthController::reconcileSubscription`), on every guarded route (`EnsureActiveSubscription`), inside `SubscriptionService::hasAccess()` / `getByBusiness()`, and by the `/subscriptions/access` endpoint.
- **Cron**: four scheduled commands in `routes/console.php` (daily): `subscriptions:expire-trials` 02:00, `subscriptions:renew` 02:15, `subscriptions:suspend-past-due` 02:30, `subscriptions:cancel-at-period-end` 02:45.
- **Payment-driven**: webhook/callback → `GatewayService::autoApprove()` → `HandlesPaymentApproval::handlePaymentType()`.

| # | From | Trigger date | Method | Result |
|---|------|--------------|--------|--------|
| 1 | `trial` | `trial_ends_at` past | `processDueTransitions()` (`:319`) / `processExpiredTrials()` | `past_due`, grace+7d, `grace_used=true` |
| 2 | `past_due` (fresh, no trial) | onboarding fee paid | `activateAfterOnboarding()` (`:222`) | if `trial_days>0 && !trial_used` → new `trial`; else `active` |
| 3 | `active` | `next_billing_date` past AND `cancel_at_period_end` | `processDueTransitions()` (`:329`) / `processCancelAtPeriodEnd()` | `cancelled`, `ends_at=now` |
| 4 | `active` | `next_billing_date` past, no cancel flag, `grace_used=false` | `processDueTransitions()` (`:339`) / `processRenewals()` | `past_due`, grace+7d, `grace_used=true` |
| 5 | `active` | `next_billing_date` past, no cancel flag, `grace_used=true` | `processDueTransitions()` (`:341`) | `suspended` directly (no second grace) |
| 6 | `past_due` | `grace_period_ends_at` past | `processDueTransitions()` (`:355`) / `processSuspensions()` | `suspended`, `suspended_at=now` |
| 7 | `suspended` | subscription payment arrives | `HandlesPaymentApproval` (`:107`) → `reactivate()` (`:196`) | `active`, new `next_billing_date`, grace fields cleared |

**Payment-driven recoveries** (`autoApprove` → `handlePaymentType`, `HandlesPaymentApproval.php:14`):

| `payment_type` | Behavior |
|---|---|
| `onboarding` | `activateAfterOnboarding()` |
| `subscription` | if `suspended` → `reactivate()`; else `activateSubscription()` (accepts `trial` / `past_due` / `expired`) |
| `renewal` | `next_billing_date` future → `renewEarly()` (extends); else `renewSubscription()` |
| `topup` | `renewEarly(months)` |
| `upgrade_proration` / `billing_cycle_change` | plan/cycle swap, no status change |

`last_billing_date` / `grace_period_ends_at` are always computed `now + 7 days`; `next_billing_date` is always `from + 1mo` (or `+1yr` for yearly), never decremented by time.

---

## 3. The access gate — `Subscription::hasAccess()`

`app/Models/Subscription.php:130`. The middleware, `/subscriptions/access`, `UserResource::resolveModules()` (personal plans), and frontend offline mirror all read this.

```php
match ($this->status) {
    ACTIVE   => $this->hasActivePeriodAccess(),   // true unless cancelling-at-period-end AND ended
    TRIAL    => $this->trial_ends_at?->isFuture() ?? true,
    PAST_DUE => $this->grace_period_ends_at?->isFuture() ?? false,
    default  => false,                             // suspended / cancelled / expired / no-grace past_due
};
```

The gate re-reads the dates, so even a stale, un-reconciled row is denied if its grant date is in the past.

---

## 4. Audit verdict — what is correct

1. **Past-due users can pay and recover cleanly.** FE `planActionMatrix.ts:104` maps the `renew` intent → `payment_type='subscription'` → `activateSubscription()`, which accepts `past_due`. No dead-end: `past_due` is never stuck unless intentionally unpaiable.
2. **Grace is exactly once per lifecycle.** `markPastDue()` throws if `grace_used` (`:155`); `reactivate`/`renew`/`activate` never reset it. Tests assert `grace_hopper_cannot_use_grace_twice`. The latch is permanent by design (anti-ghost-hopper).
3. **The live path is the safety net.** Status is reconciled on login/me, every guarded request, and `/subscriptions/access` — a stale `active` row cannot outlive its billing date once the user hits the API.
4. **Gate is date-based, not status-trusting** — the same persisted grant dates are re-verified at check time.

---

## 5. Loopholes / rough edges (recommendations)

### L1 — Cron cannot suspend grace-expired subscriptions; suspension depends on a live request

`processRenewals()` (02:15 cron) calls `markPastDue()` directly and swallows the `RuntimeException` in a `catch` block. For an `active + grace_used=true + past next_billing_date` subscription, `markPastDue()` throws (grace already used), the exception is skipped, and the row is **never moved to `suspended`** by the cron. It only suspends if a live `processDueTransitions()` runs for that business (i.e., someone logs in or hits a guarded route).

**Impact for an offline-first POS**: an offline client computes access from its last-cached subscription; `computeOfflineAccess` for `active` returns `true` unconditionally (when `cancel_at_period_end` is not set). No request → no reconciliation → a grace-expired business keeps working offline indefinitely. The backend is only the arbiter on the next online request.

**Recommendation (unresolved)**: make the cron use the same guarded transition logic (`processDueTransitions` per eligible row) instead of bare `markPastDue()`, and/or force backend-arbitrated status on offline reconnect before granting offline access.

### L2 — `EXPIRED` is dead weight

Nothing in application code ever sets `subscription.status = expired`. Trial expiry goes straight to `past_due`. Yet `EnsureActiveSubscription` has an `'expired'` message, and `SubscriptionPaymentActionResolver` has an `EXPIRED → resubscribe` intent. They are unreachable unless a platform admin force-sets the status in the admin UI. Confusing but harmless; either wire the state or remove the messages.

### L3 — Cron transitions bypass `processDueTransitions` guardrails

The four cron methods call `markPastDue()` / `suspend()` / repository updates directly (with `try/catch` swallowing). They use the same methods as the live path, so not exploitable, but the exception-swallowing means a failed downgrade is silent (`L1` is the concrete consequence).

### L4 — Offline FE trusts cached `active`

`computeOfflineAccess` (frontend `SubscriptionGuard.tsx`) returns `true` for status `active` up front; only the online `/subscriptions/access` call can deny. Combine with `L1`: a grace-expired user with the app open but no connection keeps full access until reconnect.

---

## 6. Bottom line

The state machine itself is sound: one status per subscription, date-driven transitions, guard-railed, grace-once-per-lifecycle, and payment recovery paths line up. The one genuine risk is **L1/L4**: the cron cannot suspend grace-expired subscriptions, so suspension relies on a live request, and offline clients can ride a stale `active` status indefinitely. That is the fix worth planning.

Other two items (`EXPIRED` dead state, cron bypassing the guarded transitions) are cleanliness/consistency issues, not revenue or access leaks.

## Decided actions

- Record as audit; **no behavior change shipped in this ADR**.
- Open follow-up to fix `L1` (cron uses guard-railed transitions) and `L4` (offline access arbitrated by backend on reconnect), pending Oscar's go-ahead.

## Impact / files reviewed

- `app/Services/SubscriptionService.php`
- `app/Services/Billing/SubscriptionStateMachineService.php`
- `app/Services/Billing/SubscriptionPaymentActionResolver.php`
- `app/Services/Payment/Concerns/HandlesPaymentApproval.php`
- `app/Services/Payment/GatewayService.php`
- `app/Models/Subscription.php`
- `app/Http/Middleware/EnsureActiveSubscription.php`
- `app/Http/Controllers/Api/AuthController.php`
- `app/Http/Resources/UserResource.php`
- `routes/console.php`
- `tests/Unit/Billing/BillingAccessLifecycleTest.php`, `tests/Unit/Billing/ForensicGapFixTest.php`
- Frontend: `src/renderer/app/routes/middleware/SubscriptionGuard.tsx`, `src/renderer/modules/settings/planActionMatrix.ts`