# Billing — Real-Life Subscription Scenarios

## Characters

| Person | Business | Location | Plan |
|--------|----------|----------|------|
| **Oscar** | Kikuubo Retail Ltd | Kampala, UGX | Essential → Professional |
| **Grace** | Sino Hardware & General Supplies | Gulu, UGX | Professional |
| **David** | Pearl Tech Solutions | Kampala, USD | Enterprise |
| **Sarah** | Mama K's Foods | Jinja, UGX | Essential (trial only) |

---

## Scenario 1: Oscar Signs Up — Free Trial

**Oscar** opens **Kikuubo Retail Ltd** (retail shop in Kampala). Registers the business, chooses the **Essential** plan (UGX 75,000/mo, 14-day trial).

### Request
```
POST /api/v1/subscriptions/subscribe
Authorization: Bearer {oscar_token}
{
    "plan_id": 1,
    "billing_cycle": "monthly"
}
```

### What happens
1. `Plan:find(1)` → Essential (trial_days = 14)
2. `SubscriptionRepository::findByBusiness()` → null (new business, no existing sub)
3. `Plan:trial_days > 0` → true → status = `trial`
4. `trial_ends_at` = now + 14 days
5. `next_billing_date` = now + 1 month

### Response
```json
{
    "id": 1,
    "business_id": 1,
    "plan_id": 1,
    "status": "trial",
    "trial_ends_at": "2026-08-04T12:00:00Z",
    "starts_at": "2026-07-21T12:00:00Z",
    "next_billing_date": "2026-08-21T12:00:00Z"
}
```

### Access check
```
GET /api/v1/subscriptions/access → { "has_access": true }
```
Oscar can use the POS immediately during the 14-day trial.

### Failure states
| Condition | Result | How it's handled |
|-----------|--------|-------------------|
| Plan not found | 500 | `Plan not found` — `PlanRepository::find()` returns null |
| Business already subscribed | 500 | `Business already has a subscription` — repository guard |
| Invalid billing_cycle | 422 | `InitiatePaymentRequest` validation |

---

## Scenario 2: Oscar's Trial Payment (Activation)

Day 10 of trial. Oscar initiates the first payment via **PesaPal** to activate.

### Step 1: Initiate gateway payment
```
POST /api/v1/billing/payments/initiate
Authorization: Bearer {oscar_token}
{
    "gateway_name": "pesapal",
    "amount": 75000,
    "currency": "UGX",
    "payment_type": "subscription"
}
```

### What happens
1. `GatewayService::initiatePayment()`:
   - `GatewayManager::driver('pesapal')` → resolves PesaPalGateway singleton
   - `PesaPalGateway::isEnabled()` → checks `config('pesapal.enabled')`
   - `PaymentService::createPending()` → INSERT into `billing_payments` with `status = 'pending'`
   - `PesaPalGateway::initiate()` → calls PesaPal v3 API
   - On success: updates payment with `gateway_transaction_id`, returns redirect URL

### Response
```json
{
    "success": true,
    "payment_id": 1,
    "gateway": "pesapal",
    "type": "redirect",
    "redirect_url": "https://cybqa.pesapal.com/pesapalv3/Checkout?token=ABC123",
    "reference": "PESAPAL-REF-456"
}
```

Oscar is redirected to PesaPal. Pays 75,000 UGX via Mobile Money.

### Step 2: PesaPal webhook
```
POST /api/v1/billing/gateway/pesapal/webhook
```
1. `PesaPalGateway::verifyWebhookSignature()` → validates HMAC signature
2. `PesaPalGateway::parseWebhookPayload()` → extracts `order_tracking_id`, `status`
3. `GatewayService::resolvePaymentFromWebhook()` → finds matching `BillingPayment`
4. `PesaPalGateway::verify()` → confirms with PesaPal API
5. `GatewayService::autoApprove()`:
   - `PaymentService::complete()` → UPDATE `billing_payments` SET `status = 'completed'`
   - `SubscriptionService::activateSubscription()` → UPDATE `subscriptions` SET `status = 'active'`, `approved_at = now()`, clears grace period

### Final subscription state
```json
{
    "status": "active",
    "approved_at": "2026-07-31T12:00:00Z",
    "next_billing_date": "2026-08-21T12:00:00Z"
}
```

### Failure states
| Condition | Result | How it's handled |
|-----------|--------|-------------------|
| Gateway disabled | 502 | `Gateway 'pesapal' is not currently enabled` — isEnabled() guard |
| Invalid gateway name | 502 | `Payment gateway 'stripe' is not registered` — GatewayManager driver() guard |
| No active subscription | 404 | `No active subscription found` — Controller guard |
| Missing request fields | 422 | InitiatePaymentRequest validation rules |
| PesaPal API timeout | 502 | Gateway initiates, PesaPal unreachable → payment marked `failed` in DB, exception returned |
| Webhook invalid signature | 403/logged | `Invalid webhook signature` — signature verification guard |
| Duplicate webhook | Idempotent | `payment->isCompleted()` → throws `Payment #1 is already completed` — safely re-thrown, no double-activation |
| Invalid subscription for activation | 500 | `Cannot activate subscription with status 'active'` — only trial/past_due can activate |

---

## Scenario 3: Payment Bounces — Grace Period

**Oscar's** second month payment (UGX 75,000) fails. A cron job detects `next_billing_date` has passed with no completed payment.

### What happens
```
SubscriptionService::markPastDue($subscription)
```

1. Guard check: `status === 'active'` ✅
2. Guard check: `grace_period_ends_at === null` ✅ (first time)
3. `status` → `past_due`
4. `grace_period_ends_at` → now + 7 days

### Subscription state
```json
{
    "status": "past_due",
    "grace_period_ends_at": "2026-08-28T12:00:00Z"
}
```

### Access during grace
```
GET /api/v1/subscriptions/access → { "has_access": true }
```
Oscar keeps using the POS for the full 7-day grace window. No interruption.

### If Oscar pays during grace
1. Payment initiated via PesaPal (same flow as Scenario 2)
2. Webhook activates subscription via `activateSubscription()`
3. `status` → `active`, grace cleared, `next_billing_date` pushed

### If grace expires (day 8)
Cron detects `grace_period_ends_at < now()`:

```
SubscriptionService::suspend($subscription)
```
1. Guard check: status is 'past_due' ✅ (past_due or active can be suspended)
2. `status` → `suspended`
3. `suspended_at` → now

```
GET /api/v1/subscriptions/access → { "has_access": false }
```
Oscar is locked out of the POS.

### Failure states
| Condition | Result | How it's handled |
|-----------|--------|-------------------|
| Already past_due (second failure) | 500 | `Cannot mark subscription as past_due with status 'past_due'. Only active subscriptions can become past due.` |
| Grace already extended | 500 | `Grace period already set for this subscription. Cannot extend grace period.` |

---

## Scenario 4: Grace Upgrades from Essential to Professional

**Grace** from **Sino Hardware & General Supplies** (Gulu) started on Professional (UGX 200,000/mo). Business is growing fast — she wants to upgrade mid-cycle.

This uses `SubscriptionScheduledChange` with the `SubscriptionProrationCalculator`:

```
POST /api/v1/subscriptions/{id}/upgrade
{
    "to_plan_id": 3,  // Enterprise
    "effective": "immediate"
}
```

### Proration calculation
```
Days in cycle: 30
Days remaining: 18
Already paid: 200,000 UGX

Current daily rate: 200,000 / 30 = 6,667 UGX/day
Used value: 6,667 × 12 = 80,000 UGX
Remaining credit: 200,000 - 80,000 = 120,000 UGX

New daily rate: 500,000 / 30 = 16,667 UGX/day
Prorated remaining: 16,667 × 18 = 300,000 UGX

Charge: 300,000 - 120,000 = 180,000 UGX
```

### Subscription state
```json
{
    "plan_id": 3,
    "status": "active",
    "next_billing_date": "2026-08-21T12:00:00Z"  // unchanged
}
```

Grace pays UGX 180,000 prorated difference. Gets Enterprise features immediately. Billing cycle stays aligned.

### Downgrade (end of period)
```
POST /api/v1/subscriptions/{id}/downgrade
{
    "to_plan_id": 2  // Professional
}
```
Creates `SubscriptionScheduledChange` with `effective_at = next_billing_date`. Plan switches automatically at period end. Proration credit applied to next bill.

---

## Scenario 5: David Cancels at Period End

**David** from **Pearl Tech Solutions** (Kampala, USD, Enterprise $135/mo) is closing his business. He cancels politely — at period end.

```
POST /api/v1/subscriptions/{id}/cancel
```

### What happens
1. Guard: status is 'active' ✅ (not cancelled or expired)
2. `immediate = false` (default)
3. `cancel_at_period_end = true` added to metadata
4. `status` stays `active`
5. `cancelled_at` stays **null** (period-end cancel does NOT set cancelled_at immediately)

### Response
```json
{
    "message": "Subscription will be cancelled at the end of the billing period."
}
```

David keeps Enterprise access until `next_billing_date`.

### At period end (cron job)
```
Detect: cancel_at_period_end = true AND next_billing_date <= now()
```
- `status` → `cancelled`
- `cancelled_at` → now
- `ends_at` → now
- Access revoked

### Failure states
| Condition | Result | How it's handled |
|-----------|--------|-------------------|
| Already cancelled | 500 | `Cannot cancel subscription with status 'cancelled'. Subscription is already ended.` |
| Already expired | 500 | `Cannot cancel subscription with status 'expired'. Subscription is already ended.` |

---

## Scenario 6: Sarah's Trial Expires (No Payment)

**Sarah** from **Mama K's Foods** (Jinja) signed up for Essential trial, never paid. 14 days pass.

### What happens
A cron job detects:
```
status = 'trial' AND trial_ends_at < now() AND no completed payments
```

The system marks it:
```
status → 'expired'
cancelled_at → now
ends_at → now
```

```
GET /api/v1/subscriptions/access → { "has_access": false }
```

Sarah can't use the POS. She can still re-subscribe (subscribe endpoint will throw "Business already has a subscription" — she needs to contact support to reset).

---

## Scenario 7: Admin Force-Cancels (Immediate)

A dispute arises. Admin force-cancels **Oscar's** subscription immediately:

```
POST /api/v1/subscriptions/{id}/cancel
{
    "immediate": true
}
```

### What happens
```php
if ($immediate || $subscription->status === 'suspended') {
    $data = [
        'status' => SubscriptionStatus::CANCELLED,
        'cancelled_at' => now(),
        'ends_at' => now(),
    ];
}
```

### Result
- `status` → `cancelled`
- `cancelled_at` → now
- `ends_at` → now
- Access revoked instantly

---

## Scenario 8: Suspended User Tries to Access

**Oscar** was suspended (grace expired, no payment). He opens the POS app.

### What happens
The POS app calls:
```
GET /api/v1/subscriptions/access
```

Backend checks:
```php
public function hasAccess(int $businessId): bool
{
    $subscription = $this->subscriptionRepository->findByBusiness($businessId);
    if (!$subscription) return false;
    return $subscription->hasAccess();
}
```

`Subscription::hasAccess()`:
```php
public function hasAccess(): bool
{
    return match ($this->status) {
        SubscriptionStatus::ACTIVE => true,
        SubscriptionStatus::TRIAL => $this->trial_ends_at?->isFuture() ?? false,
        SubscriptionStatus::PAST_DUE => $this->grace_period_ends_at?->isFuture() ?? false,
        default => false,  // suspended, cancelled, expired → false
    };
}
```

### Response
```json
{ "has_access": false }
```

The POS app shows a lock screen: *"Your subscription has been suspended. Please contact support or make a payment to regain access."*

---

---

## Middleware — EnsureActiveSubscription

Registered as `subscription.active` in `bootstrap/app.php`. Applied to all core POS route files:

| Guarded | Not Guarded |
|---------|-------------|
| Sales, Customers, Products, Categories | Plans (public listing) |
| Shifts, Stock Movements, Expenses | Subscriptions (self-management) |
| Orders, Invoices, Payments | Billing payments (initiate/history) |
| Dashboards, Reports, Sync | Auth, Webhooks, Health |

### Behavior
- Platform admins bypass the check entirely
- Users without a business or subscription get `403` with descriptive message
- Users with active/trial/grace access pass through

### Error messages by status
| Status | Message |
|--------|---------|
| No subscription | `No active subscription. Please subscribe to continue using Custosell.` |
| `suspended` | `Your subscription has been suspended due to non-payment. Please make a payment to regain access.` |
| `cancelled` | `Your subscription has been cancelled. Please contact support to reactivate your account.` |
| `expired` | `Your trial has expired. Please subscribe to continue using Custosell.` |

---

## Scheduled Commands

| Command | Schedule | What it does |
|---------|----------|-------------|
| `subscriptions:expire-trials` | Daily 02:00 | Marks expired trials → `expired` status |
| `subscriptions:renew` | Daily 02:15 | Past-due billing dates → `past_due` with 7-day grace |
| `subscriptions:suspend-past-due` | Daily 02:30 | Expired grace periods → `suspended` |
| `subscriptions:cancel-at-period-end` | Daily 02:45 | Period-end cancels → `cancelled` |

---

## New API Endpoints

| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/v1/subscriptions/{id}/reactivate` | Reactivate from suspended |
| POST | `/api/v1/subscriptions/{id}/upgrade` | Upgrade plan (immediate or end-of-period) |
| POST | `/api/v1/subscriptions/{id}/downgrade` | Downgrade plan (end-of-period only) |
| POST | `/api/v1/subscriptions/{id}/cancel?immediate=true` | Immediate cancel (admin force) |

---

## Trial-Once / Grace-Once Rules

- `trial_used` column marks when trial is given — subsequent subscriptions skip trial
- `grace_used` column marks when grace is entered — each subscription lifecycle gets only ONE grace period
- If `trial_used` is true on a re-subscribe (future feature), subscription starts in `past_due` instead

---

| Gap | Impact | Priority | Resolution |
|-----|--------|----------|------------|
| **No reactivate from suspended** | Suspended users can't self-recover by paying. Need `reactivate()` on SubscriptionService that takes suspended → active | High | Sprint 2 |
| **No cancel-at-period-end cron** | `cancel_at_period_end` flag never auto-processes | High | Sprint 1 |
| **No renewal cron** | Subscriptions never auto-renew; billing cycle drift | High | Sprint 1 |
| **No upgrade/downgrade API** | ProrationCalculator exists but not wired to a controller action | Medium | Sprint 2 |
| **No subscription expiry cron** | Trial/subscription transitions to expired not automated | Medium | Sprint 1 |
| **No offline sync for access state** | POS app won't know mid-session that subscription was suspended | Medium | Sprint 2 (Frontend) |

---

## State Machine Summary

```
                    ┌──────────────────────────────┐
                    │           TRIAL              │
                    │  (hasAccess: ✅ 14 days)     │
                    └──────────┬───────────────────┘
                               │ pay / approve
                               ▼
                    ┌──────────────────────────────┐
          ┌────────│          ACTIVE               │◄────────────┐
          │        │  (hasAccess: ✅ unlimited)    │             │
          │        └──┬──────────┬────────────┬────┘             │
          │           │          │            │                  │
          │      payment       cancel       cancel              │
          │       fails      (period-end)  (immediate)          │
          │           │          │            │                  │
          │           ▼          ▼            ▼                  │
          │  ┌────────────┐ ┌──────────┐ ┌───────────┐         │
          │  │ PAST_DUE   │ │ ACTIVE   │ │ CANCELLED │  renewal│
          │  │ (grace 7d) │ │ (cancled)│ │ (immed.)  │         │
          │  │ hasAccess✅│ │ hasAccess│ │ hasAccess │         │
          │  └─────┬──────┘ │   ✅     │ │   ❌      │         │
          │        │        └────┬─────┘ └───────────┘         │
          │   grace expires      │ period ends                  │
          │        │             │                              │
          │        ▼             ▼                              │
          │  ┌────────────┐ ┌──────────┐                        │
          │  │ SUSPENDED  │ │ CANCELLED│                        │
          │  │ hasAccess❌│ │ hasAccess│                        │
          │  └────────────┘ │   ❌     │                        │
          │                 └──────────┘                        │
          │                                                     │
          └─────────────────── (GAP: reactivate) ──────────────┘
          
          ┌──────────────┐
          │   EXPIRED    │
          │  (trial ended│
          │   no pay)    │
          └──────────────┘
```
