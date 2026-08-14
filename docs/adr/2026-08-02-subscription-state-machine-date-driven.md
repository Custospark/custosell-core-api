# Subscription State Machine - Date-Driven Transitions and Fairness

**Date:** 2026-08-02
**Status:** Accepted
**Author:** Backend Orchestrator (implemented from `SubscriptionStateMachineService`)

## Context

Oscar asked two questions that this ADR formalizes:

1. When are `trial_ends_at` and `next_billing_date` set, and on which events?
2. Does the access gate keep the system fair - no party (company or user) gains or loses - regardless of whether the user pays well, in part, or not at all?

## 1. Where the two key dates are set

`subscriptions` is a single row per business with exactly **one `status` at a time** (`subscriptions.status`, a string enum). The two dates are set in different lifecycle events:

### `trial_ends_at`
Set in `SubscriptionService::subscribe()` - **at subscription creation, if and only if the plan has a trial** (`plan.trial_days > 0`):

```php
if (!$skipTrial) {
    $trialDays = (int)($plan->trial_days ?? 0);
    if ($trialDays > 0) {
        $data['status'] = SubscriptionStatus::TRIAL;
        $data['trial_ends_at'] = $now->copy()->addDays($trialDays);
    }
}
```

- No payment is required to set it - it is created at signup for trial-enabled plans.
- Re-spun in `activateAfterOnboarding()` only when the trial was never used / still-unexpired; otherwise a fresh trial starts with a new `trial_ends_at`.

### `next_billing_date`
Set/computed from a "billing from" date in several places - always anchored externally, never decremented by time:

| Event | Service method | Derived from |
|-------|----------------|--------------|
| Subscribe (create) | `subscribe()` | `now` |
| Onboarding fee paid | `activateAfterOnboarding()` | `now` (or active trial end) |
| First activation (paid) | `activateSubscription()` | active `trial_ends_at`, else `now` |
| Successful renewal | `renewSubscription()` | `now` |
| Reactivation (paid) | `reactivateSubscription()` | `now` |

`nextBillingDate($from) = yearly ? +1yr : +1mo`.

## 2. The state machine (one status, date-driven transitions)

`SubscriptionStateMachineService::processDueTransitions()` runs on every guarded request the auth path (`login`/`me`) and the `/subscriptions/access` endpoint. It evaluates exactly the current `status` against `now`:

```
                    trial_ends_at
                    in THE PAST
   [TRIAL] ─────────────────────────► [PAST_DUE]───grace_period_ends_at in PAST───► [SUSPENDED]
       ▲                                   │                                              │
       │                                   │  next_billing_date in PAST; grace not used  │ reactivate
       │                                   ▼                                              ▼
       │                            grace (7d) granted                                     │
       │                                    │                                              │
       └────── activateAfterOnboarding ◄────┘─ activate / renew ✓✓ ──► [ACTIVE]

Distribution:
    ACTIVE ────(next_billing_date in PAST and cancel_at_period_end)──► CANCELLED
    ACTIVE ────(next_billing_date in PAST, no cancel flag)───────────► PAST_DUE (grace) or SUSPENDED (grace_used)
```

Transitions table (from `SubscriptionStateMachineService`):

| Current | Date compared | Past ⇒ New status | Note |
|---------|---------------|-------------------|------|
| `trial` | `trial_ends_at` | `past_due` | grants 7d grace, `grace_used=1` |
| `active` | `next_billing_date` + `cancel_at_period_end` | `cancelled` | ends at period end |
| `active` | `next_billing_date` (grace_used) | `past_due` | grants 7d grace |
| `active` | `next_billing_date` (grace_used) | `suspended` | no grace left |
| `past_due` | `grace_period_ends_at` | `suspended` | grace consumed |

Every mutation is wrapped in `DB::transaction` and guarded by status assertions, so an impossible move throws rather than silently corrupting state.

### `hasAccess()` - the single fairness gate

```php
match ($this->status) {
    ACTIVE   => true,                                   // unless cancelling-at-period-end and ended
    TRIAL    => trial_ends_at?->isFuture(),
    PAST_DUE => grace_period_ends_at?->isFuture(),
    default  => false,
};
```

Used by: `EnsureActiveSubscription` middleware (403), `SubscriptionService::hasAccess()` (the `/subscriptions/access` API), and `UserResource::resolveModules()` (gates personal-plan module list delivered to the frontend at login).

## 3. Fairness analysis - does anyone lose?

**Access is granted strictly proportionally to paid state.** The benefit and the access are derived **from the same persisted fields** - there is no second, checkable-to-access dimension:

- A paying user keeps `status=active` and `next_billing_date` pushed forward on each successful renewal ⇒ **uninterrupted access; nobody is short**: the user is never cut off before the paid-through date.
- A non-paying (or missed-payment) user moves `trial→past_due` at `trial_ends_at`, getting only a **bounded 7-day grace**, then `past_due→suspended` at `grace_period_ends_at`. If suspension, `hasAccess()` returns `false`; modules collapse to `account/guide/discover`; guarded API routes return **403**. So a non-payer **cannot keep consuming** beyond their granted window, and cannot "cheat" by re-reading stored data that was never granted.
- The company cannot be exploited because **access is gated and then revoked deterministically by date**, and payment records/renewal require successful Dthe transition to advance.

The single `status` field is the invariant. Because:

1. Every transition is atomic (transactions),
2. every transition is guarded (only presentated states),
3. the gate reads the same persisted status,
4. and `processDueTransitions()` runs on the request/auth path (no reliance on a cron that may not fire),

**neither the company's revenue nor the user's access can be silently shortchanged by stale state.** The user who pays is granted; the user who stops paying loses access at the deterministic boundary - the same for everyone.

## Decisions recorded

- Backend and frontend agree on `hasAccess()` semantics (frontend now mirrors it for past_due-within-grace banners).
- Auth path (`login`/`me`) reconciles the subscription so modules and access are truthful from first login.
- State is mutated only by `processDueTransitions()` (date-driven) or explicit payment-driven actions; never by time passing alone.

## Impact / files

- `app/Services/SubscriptionService.php`, `app/Services/Billing/SubscriptionStateMachineService.php`, `app/Models/Subscription.php`, `app/Http/Middleware/EnsureActiveSubscription.php`, `app/Http/Controllers/Api/AuthController.php`, `app/Http/Resources/UserResource.php`.
- Frontend: `src/renderer/modules/personal/PersonalModulesPage.tsx` (hasSubscriptionAccess mirror).