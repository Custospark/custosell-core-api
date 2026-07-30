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

1. **Authoritative server-side amount** — The backend always computes or validates
   the amount to charge. The frontend-provided value is overwritten for fixed-price
   types and validated against stored pending amounts for variable types.
2. **Atomic side effects** — Payment status update and all side effects run in a
   single `DB::transaction()` inside `autoApprove()`. All nested transactions use
   MySQL savepoints so the outermost transaction controls the commit/rollback.
3. **Currency resolved from business** — The business's configured currency is used;
   USD is the fallback. Exchange rates are resolved at initiation time and validated
   before any amount is sent.
4. **Deferred plan change** — Every plan change goes through the payment pipeline.
   Even zero-proration upgrades create a $0 completed payment and route through the
   same `handlePaymentType()` dispatch.
5. **Referral discount at initiation, reward at confirmation** — The discount is
   consumed during payment initiation (reducing the gateway amount). The referral
   status moves from PENDING to APPLIED at this point. After payment confirms,
   `activateForSubscription()` → `markActive()` creates BillingCredit for remaining
   discount months and the referrer reward. Both the discount consumption and the
   reward creation are atomic with their respective stages.
6. **All zero-cost paths must be atomic** — $0 completed payments (zero-proration
   upgrades, full credit coverage) must create the payment record AND dispatch side
   effects inside a single `DB::transaction()`. A partial failure (payment created
   but side effects skipped) would leave the system in an inconsistent state.

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

### Amount Sources & Validation

| Type | Amount Source | Backend Authority | Validated Against |
|------|---------------|-------------------|-------------------|
| `onboarding` | `plan.onboarding_fee_usd` | Overridden server-side | Exact match (tolerance $0.50) |
| `subscription` | `plan.price_monthly_usd` or `price_yearly_usd` | Overridden server-side | Exact match (tolerance $0.50) |
| `renewal` | plan price matching billing cycle | Overridden server-side | Exact match (tolerance $0.50) |
| `upgrade_proration` | Frontend (from proration quote) | Frontend-supplied, validated | `subscription.metadata.pending_upgrade_amount_usd` (tolerance $0.50) |
| `billing_cycle_change` | Frontend (from proration quote) | Frontend-supplied, validated | `subscription.metadata.pending_cycle_change_amount_usd` (tolerance $0.50) |

### Side Effects on Confirmation

| Type | Handler | Status Change | Plan/Billing Change | Referral Activation | Account Type Promotion |
|------|---------|---------------|---------------------|---------------------|----------------------|
| `onboarding` | `activateAfterOnboarding()` | `onboarding_fee_paid=true`; if trial still active → stay trial, else → trial/active | None (may apply upgrade from metadata `plan_id`) | Yes: `activateForSubscription()` | No |
| `subscription` | `activateSubscription()` | From trial/past_due/expired → active | Sets `next_billing_date` | Yes: `activateForSubscription()` | No |
| `renewal` | `renewSubscription()` | Active → active (advances billing date) | Advances `next_billing_date` | No (already active) | No |
| `upgrade_proration` | `handleUpgradeProration()` | None (subscription stays active) | `changePlan()` → updates `plan_id`, prices, billing cycle | Yes: `activateForSubscription()` | If target plan is not `personal` and current account is `personal` → promote to `business` with full module access |
| `billing_cycle_change` | `handleBillingCycleChange()` | None | `applyBillingCycleChange()` → updates `billing_cycle`, `next_billing_date` | No | No |

### Downgrade (no payment required)

| Effective | Action | Atomicity |
|-----------|--------|-----------|
| `immediate` | Plan changed immediately via `subscriptionService->update(plan_id)` | Not wrapped in a transaction — single update, no side effects |
| `end_of_period` | `schedulePlanChange()` creates a scheduled change record; applied later by `applyPendingChanges()` | Each scheduled change application is wrapped in a transaction |

### Referral Lifecycle Per Type

| Type | Discount Applied at Initiation? | Reward Created at Confirmation? | Rationale |
|------|-------------------------------|--------------------------------|-----------|
| `onboarding` | Yes | Yes — via `activateForSubscription()` | New user gets discount; referrer gets reward |
| `subscription` | Yes | Yes — via `activateForSubscription()` | Converting from trial to paid; same treatment as onboarding |
| `renewal` | Yes (if referral still PENDING) | No — referral already active from first payment | A renewal shouldn't create a second reward |
| `upgrade_proration` | Yes | Yes — via `activateForSubscription()` | Upgrade triggers referral reward activation if not yet active |
| `billing_cycle_change` | Yes (if referral still PENDING) | No — billing cycle change is not a conversion event | Referral should have been activated on first payment |
## Atomicity Scenarios by Code Path

### Path 1: Gateway Payment (webhook/callback)

```
initiatePayment()                                          autoApprove()
┌─────────────────────────────────┐                       ┌──────────────────────┐
│ 1. Amount authoritative compute │                       │ DB::transaction() {  │
│ 2. Validate amount              │  ─── gateway ───→     │   1. payment→completed│
│ 3. Apply referral discount      │                       │   2. handlePaymentType│
│    (PENDING→APPLIED)            │                       │ }                     │
│ 4. Apply billing credits   ←───┤ ←── failure ────┤     └──────────────────────┘
│ 5. Create pending payment       │    credits reversed   │  If handlePaymentType │
│ 6. Send to gateway ─────────────┘    (catch block)      │  throws → transaction │
│                                                         │  rolls back → payment │
│  On gateway failure:                                    │  stays pending        │
│  - payment→failed                                       │                       │
│  - credits reversed                                     │  Everything succeeds  │
│  - referral is already APPLIED (risk #2 below)          │  OR everything fails  │
└─────────────────────────────────┘                       └──────────────────────┘
```

**Atomicity guarantee:** ✅ Payment status update + all side effects are in one
`DB::transaction()`. If any side effect fails, the payment remains `pending` and
the gateway will retry the webhook.

**Credit safety:** If gateway initiation fails (before `autoApprove()`), the catch
block at `GatewayService:initiatePayment()` calls `reverseApplications()` to undo
credit consumption.

**Referral gap:** The PENDING→APPLIED transition happens at initiation (step 3)
before the gateway payment. If the gateway payment never completes, the referral
discount is consumed but no side effects ran. See Future Improvements.

### Path 2: Credit Full-Payment Bypass

```
initiatePayment()
┌──────────────────────────────────────────────────────────────┐
│ 1. Amount authoritative compute                              │
│ 2. Validate amount                                           │
│ 3. Apply referral discount (PENDING→APPLIED)                  │
│ 4. Check credit balance                                      │
│ 5. Apply billing credits ◀── credits consumed here            │
│ 6. Detected: amount ≤ 0                                      │
│    try {                                                     │
│      DB::transaction() {                                     │
│        create $0 completed payment                           │
│        link credit applications to payment                   │
│        handlePaymentType()                                   │
│      }                                                       │
│    } catch (\Throwable) {                                    │
│      reverseApplications()  ←── credits restored on failure  │
│    }                                                         │
└──────────────────────────────────────────────────────────────┘
```

**Atomicity guarantee:** ✅ Try-catch around the bypass transaction. If payment
creation or `handlePaymentType()` fails, credits are reversed back to `available`
via `CreditService::reverseApplications()`.

### Path 3: Zero-Cost Upgrade (processZeroCostUpgrade)

```
SubscriptionController::upgrade()
  └─ proration_due ≤ 0
      └─ GatewayService::processZeroCostUpgrade()
           └─ DB::transaction() {
                create $0 completed payment
                handlePaymentType('upgrade_proration')
                  └─ handleUpgradeProration()
                       DB::transaction() {  ←── savepoint
                         create scheduled_change
                         changePlan()
                         activateForSubscription()
                           └─ markActive()
                                DB::transaction() {  ←── savepoint
                                  update referral→ACTIVE
                                  create BillingCredit (discount)
                                  create BillingCredit (reward)
                                }
                         clear pending upgrade metadata
                         promote personal→business account
                       }
              }
```

**Atomicity guarantee:** ✅ The outer `DB::transaction()` wraps payment creation
and all side effects. Nested transactions become MySQL savepoints. If any inner
step fails, the entire operation rolls back — payment is never persisted as
`completed`.

### Path 4: Deferred Upgrade (pending amount stored, paid later via gateway)

```
Upgrade Confirmation (SubscriptionController::upgrade)
  └─ Store pending_upgrade_amount_usd in subscription metadata
  └─ Return quote to frontend
  └─ ⚠ This step is NOT wrapped in a transaction — single metadata update

Payment Initiation (separate request)
  └─ Validate amount against stored pending_upgrade_amount_usd
  └─ Proceeds via Path 1, 2, or 5
```

**Rationale:** The pending amount is stored as a soft contract. It's just metadata
on the subscription — not a critical business state. If the user never pays, the
pending metadata remains but doesn't affect anything. The payment initiation
validates against it to prevent amount tampering.

### Path 5: processWebhook (async confirmation)

```
processWebhook(gatewayName, Request)
  ├─ Verify webhook signature
  ├─ Parse webhook payload
  ├─ Resolve payment via PaymentValidator::resolvePaymentFromWebhook()
  ├─ If payment already processed → skip
  ├─ Verify with gateway (status check)
  └─ autoApprove()  ←── same atomic path as Path 1
```

**Atomicity guarantee:** ✅ Same as Path 1. The webhook handler is idempotent —
if the same webhook arrives twice, the `isPending()` check skips processing.

### Account Type Promotion (personal → business)

```
handleUpgradeProration()
  └─ DB::transaction() {
       ...
       $plan = Plan::find($toPlanId);
       if ($plan && $plan->type !== 'personal') {
         $business = $subscription->business;
         $owner = $business->owner;
         if ($owner && $owner->account_type === 'personal') {
           $owner->update([
             'account_type' => 'business',
             'modules' => ModuleAccessService::BUSINESS_MODULES,
           ]);
           $business->update(['business_type' => 'retail']);
         }
       }
     }
```

**Atomicity guarantee:** ✅ The account type promotion runs inside `handleUpgradeProration()`'s
transaction, which is nested inside `autoApprove()`'s transaction. If the upgrade
payment confirms, the account is promoted atomically. If it fails, nothing changes.

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
   `processZeroCostUpgrade()` creates the payment and dispatches side effects
   inside a single `DB::transaction()`.

2. **Full credit coverage** — Existing credits or discounts bring the amount to $0.
   `initiatePayment()` consumes credits and creates the payment inside a
   `DB::transaction()` with a try-catch that reverses credits on failure.

Both paths create a payment record with:
- `amount: 0`
- `status: completed`
- `payment_type: upgrade_proration`
- Metadata including `to_plan_id`, `billing_cycle`, `zero_cost_upgrade: true`

**Atomicity rule for zero-cost paths:** The payment record creation and
`handlePaymentType()` dispatch MUST be inside the same `DB::transaction()`.
A partial failure (payment persisted as completed but side effects skipped)
would leave the system in an inconsistent state — subscription unchanged,
payment says paid.

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
   APPLIED during initiation. If payment never completes, the discount is consumed
   but no side effects ran. Options:
   a) Defer the PENDING→APPLIED transition to payment confirmation (alongside
      reward creation)
   b) Add a scheduled job that reverses APPLIED→PENDING for referrals on failed
      payments after a timeout
