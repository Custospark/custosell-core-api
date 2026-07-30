# Payment Architecture

## Context

Payments touch every subscription lifecycle event: onboarding, subscribing, renewing,
upgrading, billing-cycle changes, and downgrades (which require no payment). Each
event has the same correctness requirements:

- The **correct amount** (after referral discounts, billing credits) must be shown to
  the user and sent to the payment provider
- The **correct currency** (business currency or USD fallback) must be used
- All **side effects** (plan changes, account conversions, referral rewards) must be
  **atomic with payment confirmation** — if the payment confirms, all side effects
  apply; if it fails, none do

## Principles

1. **Authoritative server-side amount** — The backend always computes the amount to
   charge. The frontend-provided value is overwritten for known payment types and
   validated for all types.
2. **Atomic side effects** — Payment status update and all side effects run in a
   single `DB::transaction()` inside `autoApprove()`.
3. **Currency resolved from business** — The business's configured currency is used;
   USD is the fallback. Exchange rates are resolved at initiation time and validated
   before any amount is sent.
4. **Deferred plan change** — Every plan change goes through the payment pipeline.
   Even zero-proration upgrades create a $0 completed payment and route through the
   same `handlePaymentType()` dispatch.
5. **Referral discount at initiation, reward at confirmation** — The first month's
   discount is consumed during payment initiation (reducing the gateway amount).
   After payment confirms, `markActive()` creates BillingCredit for remaining months
   and the referrer reward.

## Payment Lifecycle

```
┌──────────────────────────────────────────────────────────────┐
│  Payment Lifecycle (unified for all payment types)           │
│                                                              │
│  QUOTE ──→ UPGRADE ──→ INITIATE ──→ CONFIRM ──→ SIDE EFFECTS│
│  (GET)    (POST)      (POST)       (webhook)   (atomic)      │
└──────────────────────────────────────────────────────────────┘
```

### Step 1: Quote (for upgrade/billing-cycle-change)

**Endpoint:** `GET /api/v1/subscriptions/{id}/proration-quote`

**Backend handler:** `SubscriptionController::prorationQuote()`

- Computes proration using `SubscriptionProrationCalculator`
- Returns `proration_due`, `proration_due_usd`, breakdown
- Does NOT include referral discount (discount is applied at initiation)

### Step 2: Upgrade (confirm intent)

**Endpoint:** `POST /api/v1/subscriptions/{id}/upgrade`

**Backend handler:** `SubscriptionController::upgrade()`

- Validates request, computes quote
- If `effective === 'end_of_period'`: schedules a `scheduled_change` record,
  returns quote. No payment needed.
- If `effective === 'immediate'`:
  - If `proration_due > 0`: returns quote with the amount. Frontend will call
    Initiate next. Plan change is deferred to payment confirmation.
  - If `proration_due <= 0`: creates a $0 completed payment via
    `GatewayService::processZeroCostUpgrade()`, which routes through
    `handlePaymentType()` → `handleUpgradeProration()`.
    **All side effects run atomically inside this path.**

### Step 3: Initiate (send to gateway)

**Endpoint:** `POST /api/v1/billing/initiate`

**Backend handler:** `GatewayService::initiatePayment()`

```
1. Resolve payment currency from business settings
2. Compute authoritative amount in USD
   - onboarding: plan.onboarding_fee_usd
   - subscription/renewal: plan price (yearly or monthly)
   - upgrade_proration: from frontend (trusted — already validated in Step 2)
3. Validate amount against expected (PaymentValidator)
4. Apply referral discount (PENDING → APPLIED, reduces amount)
5. Apply billing credits (reduces amount further)
6. If fully covered by credits → bypass gateway, create $0 completed payment
7. Convert to local currency using exchange rate
8. Send to payment gateway (PesaPal)
9. If gateway returns bypass → auto-approve immediately
```

### Step 4: Confirm (webhook/callback)

**Endpoint:** Webhook or callback from payment provider

**Backend handler:** `GatewayService::processWebhook()` or `processCallback()`

- Verifies payment with gateway
- Calls `autoApprove()`

### Step 4b: autoApprove (atomic side effects)

```php
DB::transaction(function () {
    1. Update payment: status → completed, approved_at → now
    2. handlePaymentType():
       onboarding         → activateAfterOnboarding()
       subscription        → activateSubscription()
       renewal             → renewSubscription()
       upgrade_proration   → handleUpgradeProration()
       billing_cycle_change → handleBillingCycleChange()
});
```

Each handler is responsible for:
- Creating `scheduled_change` record (for upgrades/cycle-changes)
- Updating subscription plan, billing cycle, status
- Converting personal→business account type when moving to a business plan
- Activating referral rewards via `activateForSubscription()` → `markActive()`
  (for onboarding, subscription, and upgrade_proration types)

## Payment Type Matrix

| Type | Amount Source | Amount Validated | Side Effects on Confirm | Referral Activation |
|------|---------------|------------------|------------------------|---------------------|
| `onboarding` | `plan.onboarding_fee_usd` | Yes — exact match | `activateAfterOnboarding()`: status → trial/active, mark trial used | Yes (`activateForSubscription`) |
| `subscription` | `plan.price_monthly_usd` or `price_yearly_usd` | Yes — exact match | `activateSubscription()`: status → active, set next billing | Yes (`activateForSubscription`) |
| `renewal` | plan price (matching billing cycle) | Yes — exact match | `renewSubscription()`: advance billing date | No (already active) |
| `upgrade_proration` | From frontend (quote value) | Validated against `pending_upgrade_amount_usd` in metadata | `handleUpgradeProration()`: create scheduled_change, change plan, convert account type | Yes (`activateForSubscription`) |

| `billing_cycle_change` | From frontend (quote value) | Pass-through | `handleBillingCycleChange()`: update billing cycle, clear pending | No |
## Discount and Credit Application Order

```
Amount in USD
  │
  ├─ Referral Discount (if PENDING referral exists)
  │    Status: PENDING → APPLIED
  │    Amount: discount_applied (min of discount and amount)
  │
  ├─ Billing Credits (from `CreditService::applyToRenewal()`)
  │    Status: available → consumed
  │    Amount: min of available credit and remaining amount
  │
  └─ Final amount (after discounts + credits)
       │
       ├─ If <= 0: bypass gateway, create $0 completed payment
       │            (includes referral discount + credit metadata)
       │
       └─ If > 0: convert to local currency, send to gateway
```

## Currency Handling

```
1. Business currency → check if gateway supports it
2. If supported: use business currency (UGX, KES, TZS)
3. If not supported: fall back to USD
4. Resolve USD→local exchange rate at initiation (fail hard if unavailable)
5. Validate amount in USD before any conversion
6. Convert to local currency AFTER validation, discount, and credit application
```

## Upgrade Flow (detailed)

```
Frontend                              Backend
──────────────────────────────────────────────────────
GET /proration-quote
 ──────────────────→  PaymentQuoteService::getQuote()
 ←──────────────────  { proration: { proration_due, ... } }

POST /upgrade (to_plan_id, billing_cycle, effective=immediate)
 ──────────────────→  SubscriptionController::upgrade()
                      │
                      ├─ If proration_due > 0:
                      │    Store pending_upgrade_amount_usd in subscription metadata
                      │    Return { proration: ..., proration_due: X }
                      │    (Plan change deferred — no mutation yet)
                      │
                      ├─ If proration_due <= 0:
                      │    GatewayService::processZeroCostUpgrade()
                      │      → create $0 completed payment
                      │      → handlePaymentType('upgrade_proration')
                      │      → handleUpgradeProration()
                      │        ├─ create scheduled_change
                      │        ├─ changePlan()
                      │        ├─ activateForSubscription()
                      │        ├─ clear pending upgrade metadata
                      │        └─ account type conversion
                      │
                      └─ Return { proration: ..., proration_due: 0 }

User enters referral/promo code
 ──────────────────→  processReferral()
                      ├─ Creates referral (PENDING, discount_applied=X)
                      └─ Referral code marked as used

POST /billing/initiate (amount=${proration_due})
 ──────────────────→  GatewayService::initiatePayment()
                      ├─ Compute authoritative amount (trust frontend for proration)
                      ├─ Validate amount against pending_upgrade_amount_usd
                      │  (PaymentValidator checks subscription metadata)
                      ├─ Apply referral discount (amount -= discount_applied)
                      ├─ Apply billing credits
                      ├─ If fully covered: bypass gateway
                      │    → create $0 completed payment
                      │    → handlePaymentType('upgrade_proration')
                      │    → handleUpgradeProration() (same as above)
                      └─ Else: convert to local currency
                           → send to PesaPal
                           → return { payment_id, redirect_url }

User pays via PesaPal
 → Webhook/callback → autoApprove()
   ├─ status: completed
   └─ handlePaymentType('upgrade_proration')
      → handleUpgradeProration()
        ├─ create scheduled_change
        ├─ changePlan()
        ├─ activateForSubscription()
        ├─ clear pending upgrade metadata
        └─ account type conversion
```

## Zero-Cost Path (no gateway)

Two situations create $0 completed payments:

1. **Zero-proration upgrade** — User upgrades to same-priced or lower plan.
   `processZeroCostUpgrade()` creates the payment and dispatches side effects.

2. **Full credit coverage** — Existing credits or discounts bring the amount to $0.
   `initiatePayment()` creates the payment in a bypass block and dispatches
   side effects.

Both paths create a payment record with:
- `amount: 0`
- `status: completed`
- `payment_type: upgrade_proration`
- Metadata including `to_plan_id`, `billing_cycle`, `zero_cost_upgrade: true`

## File Map

| File | Role |
|------|------|
| `app/Services/Payment/GatewayService.php` | Payment orchestration: initiate, approve, webhook, callback, zero-cost upgrade |
| `app/Services/Payment/Concerns/HandlesPaymentApproval.php` | Trait: `autoApprove()`, `handlePaymentType()`, type-specific handlers |
| `app/Services/Payment/Validation/PaymentValidator.php` | Amount validation + payment resolution from webhook |
| `app/Services/Payment/GatewayManager.php` | Gateway driver resolution |
| `app/Services/Billing/PaymentQuoteService.php` | Upgrade/billing-cycle-change quote computation |
| `app/Services/Billing/SubscriptionProrationCalculator.php` | Proration math (days, credits, charges) |
| `app/Services/SubscriptionService.php` | Subscription CRUD + plan/billing-cycle changes |
| `app/Services/Billing/SubscriptionStateMachineService.php` | State transitions: activate, renew, suspend, cancel + referral activation |
| `app/Services/ReferralService.php` | Referral lifecycle: process, markActive, earnings |
| `app/Http/Controllers/Api/SubscriptionController.php` | API endpoints: subscribe, upgrade, downgrade, billing-cycle change |

## Future Improvements

1. **Include referral discount in quote response** — The quote endpoint currently
   doesn't return the expected amount after referral discount. The frontend manually
   computes it. Include `discount_applied` in the quote so the UI can use it
   directly.

2. **Referral consumption on payment failure** — Currently, referral status moves to
   APPLIED during initiation. If payment fails, the discount is consumed but no
   side effects ran. Consider reversing referral status on payment failure, or
   deferring the APPLIED transition to payment confirmation.

3. **Billing cycle change amount validation** — The `validatePaymentAmount()` method
   currently passes through for `billing_cycle_change`. Store the expected amount
   in subscription metadata (like the upgrade flow does) and validate against it.
