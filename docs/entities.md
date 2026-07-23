# Custosell — Entity Documentation

## Plan - 2026-06-02 08:43:00

### Fields
- id: increments - Primary key
- name: string(100) - e.g. "Free", "Pro", "Premium"
- slug: string(100) - unique, e.g. "free", "pro", "premium"
- description: text - nullable, marketing copy
- price_monthly: decimal(10,2) - UGX
- price_yearly: decimal(10,2) - nullable, discounted yearly rate
- features: json - feature flags
- limits: json - numerical limits
- is_active: boolean - default true
- sort_order: integer - display order
- created_at: timestamp
- updated_at: timestamp

### Files Generated/Updated
- [x] Migration: `database/migrations/2026_06_02_084300_create_plans_table.php`
- [x] Model: `app/Models/Plan.php`
- [x] Repository Interface: `app/Repositories/Contracts/PlanRepositoryInterface.php`
- [x] Repository: `app/Repositories/Eloquent/PlanRepository.php`
- [x] Service Interface: `app/Services/Contracts/PlanServiceInterface.php`
- [x] Service: `app/Services/PlanService.php`
- [x] Request: `app/Http/Requests/PlanRequest.php`
- [x] Resource: `app/Http/Resources/PlanResource.php`
- [x] Collection: `app/Http/Resources/PlanCollection.php`
- [x] Controller: `app/Http/Controllers/Api/PlanController.php`
- [x] API Routes: `routes/api/v1/plans.php`
- [x] Registered in: `routes/api.php`
- [x] Provider: `app/Providers/PlanServiceProvider.php` + registered in `bootstrap/providers.php`
- [x] Seeder: `database/seeders/PlanSeeder.php`

### Provider Bindings
- `PlanRepositoryInterface` → `PlanRepository`
- `PlanServiceInterface` → `PlanService`

### API Endpoints
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/plans` | List all plans |
| GET | `/api/v1/plans/{plan}` | Get one plan |
| POST | `/api/v1/plans` | Create plan |
| PUT | `/api/v1/plans/{plan}` | Update plan |
| DELETE | `/api/v1/plans/{plan}` | Delete plan |

### Seeded Data
- **Free** — UGX 0/mo, 1 staff, 50 products, 100 monthly sales
- **Pro** — UGX 30,000/mo, 5 staff, 1,000 products, unlimited sales
- **Premium** — UGX 100,000/mo, unlimited everything

### Test Results
- Lint: ✅ Passed
- Migration: ✅ Ran successfully

---

## User - 2026-06-02 08:47:37

### Fields
- id: bigIncrements - Primary key
- business_id: unsignedBigInteger - FK to businesses, nullable
- role_id: unsignedBigInteger - FK to roles, nullable
- name: string(255)
- email: string(255) - unique
- password: string(255) - hashed
- is_active: boolean - default true
- phone: string(50) - nullable
- created_by: unsignedBigInteger - FK to users, nullable
- email_verified_at: timestamp - nullable
- remember_token: string(100) - nullable
- timestamps
- deleted_at: soft deletes

### Files Generated/Updated
- [x] Migration: `database/migrations/2026_06_02_084737_add_custosell_fields_to_users_table.php`
- [x] Migration: `database/migrations/2026_06_02_090349_add_foreign_keys_to_users.php`
- [x] Model: `app/Models/User.php`
- [x] Repository Interface: `app/Repositories/Contracts/UserRepositoryInterface.php`
- [x] Repository: `app/Repositories/Eloquent/UserRepository.php`
- [x] Service Interface: `app/Services/Contracts/UserServiceInterface.php`
- [x] Service: `app/Services/UserService.php`
- [x] Request: `app/Http/Requests/RegisterRequest.php`
- [x] Request: `app/Http/Requests/LoginRequest.php`
- [x] Resource: `app/Http/Resources/UserResource.php`
- [x] Collection: `app/Http/Resources/UserCollection.php`
- [x] Controller: `app/Http/Controllers/Api/AuthController.php`
- [x] Controller: `app/Http/Controllers/Api/UserController.php`
- [x] API Routes: `routes/api/v1/users.php`
- [x] Registered in: `routes/api.php`
- [x] Provider: `app/Providers/UserServiceProvider.php` + registered in `bootstrap/providers.php`

### API Endpoints
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/v1/auth/register` | Register user |
| POST | `/api/v1/auth/login` | Login |
| POST | `/api/v1/auth/logout` | Logout (auth) |
| GET | `/api/v1/auth/me` | Current user (auth) |
| GET | `/api/v1/users` | List users (auth) |
| POST | `/api/v1/users` | Create staff (auth) |
| GET | `/api/v1/users/{user}` | Get user (auth) |
| DELETE | `/api/v1/users/{user}` | Delete user (auth) |

---

## Business - 2026-06-02 08:57:01

### Fields
- id: bigIncrements
- owner_id: unsignedBigInteger - FK to users
- name: string(255)
- slug: string(255) - unique
- email: string(255) - nullable
- phone: string(50) - nullable
- address: text - nullable
- currency: string(10) - default 'UGX'
- receipt_footer: text - nullable
- logo_path: string(255) - nullable
- status: enum('active','suspended') - default 'active'
- trial_ends_at: datetime - nullable
- timestamps
- deleted_at: soft deletes

---

## Role - 2026-06-02 09:03:17

### Fields
- id: bigIncrements
- business_id: unsignedBigInteger - FK to businesses, nullable
- name: string(100)
- slug: string(100)
- description: text - nullable
- permissions: json
- is_default: boolean
- timestamps

### Seeded Roles per Business
- **Admin** — all 23 permissions true
- **Staff** — sales.create, sales.view, inventory.view, customers.view, customers.create only

---

## Category - 2026-06-02 09:05:24

### Fields
- id, business_id (FK), name, description (nullable), sort_order, timestamps

---

## Product - 2026-06-02 09:05:25

### Fields
- id, business_id (FK), category_id (FK, nullable), name, description (nullable), sku (nullable, unique per business), barcode (nullable), unit_price, cost_price (nullable), stock_quantity, low_stock_threshold, tax_percentage, is_active, timestamps, soft_deletes

---

## Customer - 2026-06-02 09:05:27

### Fields
- id, business_id (FK), name, phone, email (nullable), total_purchases, last_purchase_at (nullable), timestamps

---

## Shift - 2026-06-02 09:05:28

### Fields
- id, business_id (FK), user_id (FK), clock_in, clock_out (nullable), total_sales, total_cash, total_mobile_money, total_card, status (enum: active/completed), notes (nullable), timestamps

---

## Sale - 2026-06-02 09:05:29

### Fields
- id, business_id (FK), user_id (FK), customer_id (FK, nullable), shift_id (FK, nullable), receipt_number (unique per business), subtotal, tax_total, discount_amount, total_amount, payment_method (enum: cash/mobile_money/card/other), payment_status (enum: paid/partially_refunded/refunded), notes (nullable), sale_date, timestamps, soft_deletes

---

## SaleItem - 2026-06-02 09:05:30

### Fields
- id, sale_id (FK), product_id (FK, nullable), product_name (snapshot), product_price (snapshot), quantity, unit_price, subtotal, tax_amount, discount_amount, refunded_quantity, refunded_amount, timestamps

---

## StockMovement - 2026-06-02 09:05:32

### Fields
- id, business_id (FK), product_id (FK), sale_item_id (FK, nullable), type (enum: purchase/sale/adjustment/return/initial), quantity_change, stock_before, stock_after, reference (nullable), notes (nullable), created_by (FK, nullable), timestamps

---

## Subscription - 2026-06-02 09:05:33

### Fields
- id, business_id (FK, unique), plan_id (FK), status (enum: active/trialing/cancelled/expired), starts_at, trial_ends_at (nullable), ends_at (nullable), cancelled_at (nullable), timestamps

---

## ExpenseCategory - 2026-06-02 09:05:34

### Fields
- id, business_id (FK), name, description (nullable), sort_order, timestamps

---

## Expense - 2026-06-02 09:05:36

### Fields
- id, business_id (FK), expense_category_id (FK, nullable), recorded_by (FK, nullable), amount, description, reference (nullable), expense_date, timestamps, soft_deletes

---

## Sync API - 2026-06-02

### Endpoints
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/v1/sync/push` | Push local changes to cloud |
| GET | `/api/v1/sync/pull?since=` | Pull changes since timestamp |
| GET | `/api/v1/sync/full` | Full data dump for first-time setup |

### Architecture
- Desktop (Electron + SQLite) = source of truth
- Cloud (Laravel + MySQL) = backup/sync hub
- Auto-sync on reconnect + periodic background
- Delta sync via `updated_at` timestamps
- Receipt numbers: `{business_slug}-{increment}`

---

## SOLID Compliance
- [x] Single Responsibility - Controller (HTTP), Service (business), Repository (data)
- [x] Open/Closed - All repos/services have interfaces
- [x] Liskov Substitution - Any impl can replace interface
- [x] Interface Segregation - Split repo/service interfaces
- [x] Dependency Inversion - Providers bind interfaces, never concretions

---

## Plan (Updated) - 2026-07-21 12:00:00

### New Billing Fields
- `price_monthly_usd`: decimal(10,2) - USD monthly price
- `price_yearly_usd`: decimal(10,2) - USD yearly price (nullable)
- `onboarding_fee_ugx`: decimal(14,2) - UGX one-time onboarding fee
- `onboarding_fee_usd`: decimal(10,2) - USD one-time onboarding fee
- `trial_days`: tinyint - trial period in days (default 14)
- `billing_cycle`: varchar(20) - 'monthly', 'yearly', or 'both'
- `is_popular`: boolean - highlight recommended plan
- `metadata`: json - additional plan config

### Files Generated/Updated
- [x] Migration: `database/migrations/2026_07_21_000001_add_billing_fields_to_plans_table.php`
- [x] Model: `app/Models/Plan.php` (updated)
- [x] Seeder: `database/seeders/PlanSeeder.php` (updated with Essential/Professional/Enterprise)

### Seeded Plans
| Plan | UGX/mo | USD/mo | UGX/yr | USD/yr | Onboarding UGX | Onboarding USD | Trial |
|------|--------|--------|--------|--------|----------------|----------------|-------|
| Essential | 75,000 | 20 | 750,000 | 200 | 150,000 | 40 | 14 days |
| Professional | 200,000 | 54 | 2,000,000 | 540 | 350,000 | 95 | 14 days |
| Enterprise | 500,000 | 135 | 5,000,000 | 1,350 | 750,000 | 200 | 7 days |

---

## Subscription (Updated) - 2026-07-21 12:00:00

### New Lifecycle Fields
- `billing_cycle`: varchar(20) - 'monthly' or 'yearly'
- `next_billing_date`: datetime - when next payment is due
- `grace_period_ends_at`: datetime - payment grace window (7 days after past_due)
- `suspended_at`: datetime - when access was suspended
- `approved_at`: datetime - when trial/past_due was activated
- `approved_by_user_id`: FK -> users (nullable)
- `onboarding_fee_paid`: boolean - whether onboarding fee was paid
- `notes`: text - internal notes
- `metadata`: json - includes cancel_at_period_end flag
- `deleted_at`: soft deletes

### Statuses (SubscriptionStatus Enum)
- `trial`, `active`, `past_due`, `suspended`, `cancelled`, `expired`

### Subscription Service
- `subscribe()` - creates trial or past_due
- `activateSubscription()` - activates from trial/past_due
- `renewSubscription()` - renews active subscription
- `markPastDue()` - marks active as past_due with 7-day grace
- `suspend()` - suspends past_due or active
- `cancel()` - immediate (ends now) or period-end (cancel_at_period_end flag)
- `hasAccess()` - checks trial/active/past_due-within-grace

### Files Generated/Updated
- [x] Migration: `database/migrations/2026_07_21_000002_add_billing_lifecycle_to_subscriptions_table.php`
- [x] Model: `app/Models/Subscription.php` (updated)
- [x] Repository Interface: `app/Repositories/Contracts/SubscriptionRepositoryInterface.php`
- [x] Repository: `app/Repositories/Eloquent/SubscriptionRepository.php`
- [x] Service Interface: `app/Services/Contracts/SubscriptionServiceInterface.php`
- [x] Service: `app/Services/SubscriptionService.php` (rewritten)
- [x] Provider: `app/Providers/BillingServiceProvider.php`
- [x] Provider registered in: `bootstrap/providers.php`

### API Endpoints (Subscription)
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/subscriptions` | List all subscriptions |
| GET | `/api/v1/subscriptions/current` | Get business subscription |
| POST | `/api/v1/subscriptions/subscribe` | Subscribe to a plan |
| POST | `/api/v1/subscriptions/{id}/cancel` | Cancel subscription |
| GET | `/api/v1/subscriptions/access` | Check access |

---

## BillingPayment - 2026-07-21 12:00:00

### Fields
- `id`: bigIncrements - Primary key
- `business_id`: FK -> businesses (cascade)
- `subscription_id`: FK -> subscriptions (set null)
- `amount`: decimal(14,2) - payment amount
- `currency`: varchar(3) - UGX or USD
- `method`: varchar(50) - 'gateway', 'manual', 'mobile_money', etc.
- `payment_type`: varchar(50) - 'subscription', 'onboarding', 'renewal'
- `status`: varchar(20) - PaymentStatus enum values
- `transaction_reference`: varchar(255) - our reference
- `gateway_name`: varchar(50) - e.g. 'pesapal'
- `gateway_transaction_id`: varchar(255) - gateway reference
- `gateway_request`: json - raw request sent
- `gateway_response`: json - raw response from gateway
- `paid_at`: timestamp - when payment completed
- `approved_at`: timestamp - admin approval
- `approved_by_user_id`: FK -> users (set null)
- `rejection_reason`: text - failure reason
- `metadata`: json - additional data
- timestamps

### Statuses (PaymentStatus Enum)
- `pending`, `completed`, `failed`, `refunded`, `cancelled`

### Files Generated
- [x] Migration: `database/migrations/2026_07_21_000003_create_payments_table.php`
- [x] Model: `app/Models/BillingPayment.php`
- [x] Repository Interface: `app/Repositories/Contracts/PaymentRepositoryInterface.php`
- [x] Repository: `app/Repositories/Eloquent/PaymentRepository.php`
- [x] Service Interface: `app/Services/Contracts/PaymentServiceInterface.php`
- [x] Service: `app/Services/Billing/PaymentService.php`
- [x] Request: `app/Http/Requests/Billing/InitiatePaymentRequest.php`
- [x] Resource: `app/Http/Resources/Billing/PaymentResource.php`
- [x] Collection: `app/Http/Resources/Billing/PaymentCollection.php`
- [x] Controller: `app/Http/Controllers/Api/Billing/PaymentController.php`
- [x] API Routes: `routes/api/v1/billing.php`
- [x] Registered in: `routes/api.php`

### API Endpoints (Billing Payments)
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/billing/payments` | List payments |
| POST | `/api/v1/billing/payments/initiate` | Initiate gateway payment |
| GET | `/api/v1/billing/gateway/{gateway}/callback` | Gateway callback |
| POST | `/api/v1/billing/gateway/{gateway}/webhook` | Gateway webhook |

---

## SubscriptionScheduledChange - 2026-07-21 12:00:00

### Fields
- `id`: bigIncrements - Primary key
- `subscription_id`: FK -> subscriptions (cascade)
- `business_id`: FK -> businesses (cascade)
- `change_type`: varchar(32) - ScheduledChangeType: 'downgrade', 'cancel'
- `from_plan_id`: FK -> plans (set null)
- `to_plan_id`: FK -> plans (set null)
- `effective_at`: timestamp - when change takes effect
- `status`: varchar(16) - ScheduledChangeStatus: 'pending', 'processed', 'failed'
- `proration_amount`: decimal(14,2) - prorated credit/debit
- `requested_by_user_id`: FK -> users (set null)
- `metadata`: json
- timestamps

### Files Generated
- [x] Migration: `database/migrations/2026_07_21_000004_create_subscription_scheduled_changes_table.php`
- [x] Model: `app/Models/SubscriptionScheduledChange.php`
- [x] Repository Interface: `app/Repositories/Contracts/SubscriptionScheduledChangeRepositoryInterface.php`
- [x] Repository: `app/Repositories/Eloquent/SubscriptionScheduledChangeRepository.php`
- [x] Service Interface: `app/Services/Contracts/SubscriptionScheduledChangeServiceInterface.php`
- [x] Service: `app/Services/Billing/SubscriptionScheduledChangeService.php`
- [x] Provider: `app/Providers/BillingServiceProvider.php`

---

## Payment Gateway Infrastructure - 2026-07-21 12:00:00

### Architecture
- **Strategy pattern** with gateway-agnostic interface
- `PaymentGatewayInterface` — contract all gateways implement
- `GatewayManager` — registry + singleton driver resolver
- `GatewayService` — orchestration: initiate, webhook, callback
- `PesaPalGateway` — PesaPal v3 driver (sandbox + production)

### Files Generated
- [x] Interface: `app/Services/Payment/Contracts/PaymentGatewayInterface.php`
- [x] Manager: `app/Services/Payment/GatewayManager.php`
- [x] Service: `app/Services/Payment/GatewayService.php`
- [x] Gateway: `app/Services/Payment/Gateways/PesaPalGateway.php`
- [x] Exception: `app/Services/Payment/Gateways/Exceptions/GatewayException.php`
- [x] Config: `config/pesapal.php`
- [x] Provider: `app/Providers/PaymentGatewayServiceProvider.php`
- [x] Provider registered in: `bootstrap/providers.php`

### Provider Bindings (BillingServiceProvider)
- `PaymentRepositoryInterface` → `PaymentRepository`
- `PaymentServiceInterface` → `Billing\PaymentService`
- `SubscriptionScheduledChangeRepositoryInterface` → `SubscriptionScheduledChangeRepository`
- `SubscriptionScheduledChangeServiceInterface` → `SubscriptionScheduledChangeService`
- Singleton: `SubscriptionProrationCalculator`
- Singleton: `PaymentQuoteService`

### Provider Bindings (PaymentGatewayServiceProvider)
- `GatewayManager` → singleton
- `GatewayService` → singleton

### Test Results (2026-07-21)
- Unit tests (SubscriptionLifecycleTest): 15/15 ✅
- Feature tests (SubscriptionBillingTest): 22/22 ✅
- Vera Fast: ✅ (php -l on 61 files + logic rules)
- Vera Extended: ✅ (migrate --pretend all 4 migrations)
- Total: 38 tests, 115 assertions, 0 failures

---

## Referral Code - 2026-07-21 14:00:00

### Fields
- id: bigIncrements - Primary key
- owner_type: varchar(32) - business, sales_rep, campaign
- owner_business_id: unsignedBigInteger - nullable, FK→businesses(id)
- owner_user_id: unsignedBigInteger - nullable, FK→users(id)
- code: varchar(64) - unique
- discount_type: varchar(20) - percentage, flat_amount, free_month
- discount_value: decimal(14,2) - nullable
- discount_duration_months: tinyint unsigned - default 1
- reward_type: varchar(20) - default free_month
- reward_value: decimal(14,2) - nullable
- max_uses: int unsigned - nullable
- used_count: int unsigned - default 0
- is_active: boolean - default true
- expires_at: datetime - nullable
- timestamps

### Files Generated
- [x] Migration: `database/migrations/2026_07_21_125052_create_referral_codes_table.php`
- [x] Migration: `database/migrations/2026_07_21_125054_create_sales_reps_table.php`
- [x] Migration: `database/migrations/2026_07_21_125056_create_referrals_table.php`
- [x] Model: `app/Models/ReferralCode.php`
- [x] Model: `app/Models/SalesRep.php`
- [x] Model: `app/Models/Referral.php`
- [x] Repository Interface: `app/Repositories/Contracts/ReferralCodeRepositoryInterface.php`
- [x] Repository Interface: `app/Repositories/Contracts/SalesRepRepositoryInterface.php`
- [x] Repository Interface: `app/Repositories/Contracts/ReferralRepositoryInterface.php`
- [x] Repository: `app/Repositories/Eloquent/ReferralCodeRepository.php`
- [x] Repository: `app/Repositories/Eloquent/SalesRepRepository.php`
- [x] Repository: `app/Repositories/Eloquent/ReferralRepository.php`
- [x] Service Interface: `app/Services/Contracts/ReferralCodeServiceInterface.php`
- [x] Service Interface: `app/Services/Contracts/SalesRepServiceInterface.php`
- [x] Service Interface: `app/Services/Contracts/ReferralServiceInterface.php`
- [x] Service: `app/Services/ReferralCodeService.php`
- [x] Service: `app/Services/SalesRepService.php`
- [x] Service: `app/Services/ReferralService.php`
- [x] Request: `app/Http/Requests/ReferralCodeRequest.php`
- [x] Request: `app/Http/Requests/SalesRepRequest.php`
- [x] Resource: `app/Http/Resources/ReferralCodeResource.php`
- [x] Resource: `app/Http/Resources/SalesRepResource.php`
- [x] Resource: `app/Http/Resources/ReferralResource.php`
- [x] Collection: `app/Http/Resources/ReferralCodeCollection.php`
- [x] Collection: `app/Http/Resources/SalesRepCollection.php`
- [x] Collection: `app/Http/Resources/ReferralCollection.php`
- [x] Controller: `app/Http/Controllers/Api/ReferralCodeController.php`
- [x] Controller: `app/Http/Controllers/Api/SalesRepController.php`
- [x] Controller: `app/Http/Controllers/Api/ReferralController.php`
- [x] API Routes: `routes/api/v1/referral-codes.php`
- [x] API Routes: `routes/api/v1/sales-reps.php`
- [x] API Routes: `routes/api/v1/referrals.php`
- [x] Registered in: `routes/api.php`
- [x] Provider: `app/Providers/ReferralServiceProvider.php` + registered in `bootstrap/providers.php`

### Enums Created
- `App\Enums\Billing\DiscountType`: percentage, flat_amount, free_month
- `App\Enums\Billing\RewardType`: percentage, flat_amount, free_month
- `App\Enums\Billing\ReferralStatus`: pending, active, rewarded
- `App\Enums\Billing\CommissionType`: percentage, flat
- `App\Enums\Billing\ReferralCodeOwnerType`: business, sales_rep, campaign

### Provider Bindings
- `ReferralCodeRepositoryInterface` → `ReferralCodeRepository`
- `SalesRepRepositoryInterface` → `SalesRepRepository`
- `ReferralRepositoryInterface` → `ReferralRepository`
- `ReferralCodeServiceInterface` → `ReferralCodeService`
- `SalesRepServiceInterface` → `SalesRepService`
- `ReferralServiceInterface` → `ReferralService`

### API Endpoints
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/referral-codes` | List all |
| GET | `/api/v1/referral-codes/{id}` | Get one |
| POST | `/api/v1/referral-codes` | Create |
| PUT | `/api/v1/referral-codes/{id}` | Update |
| DELETE | `/api/v1/referral-codes/{id}` | Delete |
| GET | `/api/v1/sales-reps` | List all |
| GET | `/api/v1/sales-reps/{id}` | Get one |
| POST | `/api/v1/sales-reps` | Create |
| PUT | `/api/v1/sales-reps/{id}` | Update |
| DELETE | `/api/v1/sales-reps/{id}` | Delete |
| GET | `/api/v1/referrals` | List all |
| GET | `/api/v1/referrals/{id}` | Get one |
| GET | `/api/v1/referrals/business/{businessId}` | By business |
| GET | `/api/v1/referrals/code/{codeId}` | By code |

### Subscription Integration
- `SubscriptionService::subscribe()` accepts optional `referral_code` parameter
- `SubscriptionController::subscribe()` accepts `referral_code` in request payload
- On valid referral code: creates Referral with status=pending, increments code usage

### Test Results
- Unit tests (ReferralCodeTest): 5/5 ✅
- Unit tests (ReferralTest): 5/5 ✅
- Feature tests (ReferralBillingTest): 2/2 ✅
- Total: 12 tests, 29 assertions, 0 failures
- Vera Fast: ✅ (php -l on 46 files + logic rules)

### SOLID Compliance Checklist
- [x] Single Responsibility
- [x] Open/Closed
- [x] Liskov Substitution
- [x] Interface Segregation
- [x] Dependency Inversion

---

## Onboarding Payment Flow — 2026-07-22 20:00:00

### User Journey
```
Register (name/email/password + plan_id) 
  → Subscription created as PAST_DUE, onboarding_fee_paid = false
  → Redirect to payment page
  → Pay onboarding fee via PesaPal STK push
  → IPN/callback → autoApprove → Subscription becomes TRIAL (or ACTIVE if no trial)
  → Full dashboard access
```

### Key Decisions
- **Onboarding fee is mandatory** before any app access
- **`EnsureActiveSubscription` middleware** naturally blocks `past_due` status — no additional route guards needed
- **No new subscription status** added — reuses existing `past_due` → `trial`/`active` lifecycle
- **Sandbox keys** for dev, production keys saved as `PESAPAL_PRODUCTION_*`

### Files Changed

**Backend (7 files):**
- [x] `app/Http/Requests/BusinessRegisterRequest.php` — Added `plan_id` (required, exists:plans), `billing_cycle` (sometimes, in:monthly,yearly)
- [x] `app/Services/BusinessService.php` — Injected `SubscriptionServiceInterface`, calls `subscribe()` after business creation with `skipTrial=true`
- [x] `app/Services/Contracts/SubscriptionServiceInterface.php` — Added `$skipTrial` param to `subscribe()`, added `activateAfterOnboarding()`
- [x] `app/Services/SubscriptionService.php` — Modified `subscribe()` to accept `$skipTrial`, added `activateAfterOnboarding()` method (transitions PAST_DUE → TRIAL/ACTIVE, sets `onboarding_fee_paid = true`)
- [x] `app/Services/Payment/GatewayService.php` — Split `autoApprove` match: `onboarding` → `activateAfterOnboarding()`, `subscription` → `activateSubscription()`
- [x] `app/Http/Controllers/Api/AuthController.php` — Load `business.subscription` in `login()` and `me()`
- [x] `app/Http/Resources/UserResource.php` — Added `subscription` object to business payload in auth response

**Test files (2 files fixed):**
- [x] `tests/Feature/BusinessTest.php` — Added `plan_id` + `privacy_consent` to registration payloads
- [x] `tests/Feature/PlatformTest.php` — Added `PlanSeeder` to setUp, added `plan_id` + `privacy_consent` to registration payload

### Subscription State Transitions (Onboarding Flow)
| Step | Status | Onboarding Fee Paid | Notes |
|------|--------|---------------------|-------|
| Register + select plan | `past_due` | false | Skipped trial, blocked by middleware |
| Onboarding fee paid, plan has trial_days | `trial` | true | `trial_ends_at` = now + plan.trial_days |
| Onboarding fee paid, no trial | `active` | true | Full access immediately |
| Trial expired | `expired` | true | Normal lifecycle |

### Test Results
- SubscriptionLifecycleTest: 15/15 ✅
- SubscriptionBillingTest: 22/22 ✅
- SubscriptionTest: 5/5 ✅
- Referral tests: 12/12 ✅
- Payment tests: 2/2 ✅
- **Total billing/subscription tests: 54/54, 157 assertions**

### Env Configuration (Sandbox for Dev)
```
PESAPAL_ENVIRONMENT=sandbox
PESAPAL_ENABLED=true
PESAPAL_CONSUMER_KEY=<sandbox-key>
PESAPAL_CONSUMER_SECRET=<sandbox-secret>
PESAPAL_PRODUCTION_CONSUMER_KEY=<production-key>  // for reference
PESAPAL_PRODUCTION_CONSUMER_SECRET=<production-secret>
```

---


