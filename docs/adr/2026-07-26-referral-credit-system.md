# ADR: Referral Credit System

## Date
2026-07-26

## Status
Accepted

## Context
Referral earnings (`reward_amount` from referral activations) need to be useful to the referrer without requiring manual payouts. Industry best practice is to auto-apply credit to subscription renewals.

## Decision
1. When a referral becomes ACTIVE via `markActive()`, a `BillingCredit` record is created in USD via `CreditService::createFromReferral()`.
2. Credits are owned polymorphically (`morphs('owner')`): `business` (for users with a `business_id`) or `user` (for staff without a business).
3. On renewal payments (`payment_type = 'renewal'`), `GatewayService::initiatePayment()` checks available business credit via `CreditService::applyToRenewal()` and applies it FIFO (by `created_at`) before initiating the gateway.
4. If credit covers the full amount → `CreditService::completeRenewalWithCredit()` completes the payment with no gateway call, creating a `completed` payment record with method `credit`.
5. If credit covers partially → the gateway is called for the reduced amount only; `validatePaymentAmount()` accounts for the credit-applied portion.
6. Staff (user-owned) credits accumulate as user credit. Platform admins can record manual payouts via `POST /api/v1/platform/credits/{creditId}/payout`, which updates `amount_used` and transitions status to `partially_used` or `fully_used`.
7. All referral amounts are in USD throughout: `discount_applied`, `reward_amount`, `commission_earned`, and credit amounts use `plan.price_monthly_usd`.

## Consequences
- Renewals automatically consume available business credit before charging, oldest credit first.
- Staff members without a subscription can see their user credit balance via `GET /api/v1/credits/balance`.
- Platform admin panel (`/api/v1/platform/credits/*`) shows all credits with totals, pending payouts, and supports manual payout recording.
- No manual payout infrastructure needed for business owners — their credit is auto-consumed on renewal.
- Existing pre-feature referrals (processed before the migration) will not have associated credit records.

## New API Endpoints
### User-facing (auth:sanctum + business.active)
- `GET /api/v1/credits/balance` — returns `business_credit`, `user_credit`, `total_credit`, `currency`
- `GET /api/v1/credits/history` — merged credit history (business + user) with eager-loaded referral and application relations
- `GET /api/v1/referrals/earnings/me` — now includes `available_credit`, `business_credit`, `user_credit`, `currency`

### Platform Admin
- `GET /api/v1/platform/credits` — all credits with referred business info, plus totals (`total_outstanding`, `total_fully_used`, `total_available`)
- `GET /api/v1/platform/credits/pending-payouts` — user credits with remaining > 0, ready for manual payout
- `POST /api/v1/platform/credits/{creditId}/payout` — record payout (validates amount ≤ remaining)

## Tables
### `billing_credits`
| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint, PK | |
| `owner_type` | string (morphs) | `business` or `user` |
| `owner_id` | bigint (morphs) | polymorphic owner ID |
| `referral_id` | bigint, FK→referrals, nullable | nullOnDelete |
| `amount` | decimal(14,2) | Total credit amount in USD |
| `amount_used` | decimal(14,2), default 0 | Amount consumed |
| `status` | string(20), default 'available' | `available`, `partially_used`, `fully_used`, `expired` |
| `expires_at` | timestamp, nullable | Expiry date |
| `created_at` / `updated_at` | timestamps | |

Index: `(owner_type, owner_id, status)`

### `credit_applications`
| Column | Type | Notes |
|--------|------|-------|
| `id` | bigint, PK | |
| `credit_id` | bigint, FK→billing_credits | cascadeOnDelete |
| `subscription_id` | unsignedInt, FK→subscriptions | cascadeOnDelete |
| `billing_payment_id` | bigint, FK→billing_payments, nullable | nullOnDelete (set when payment completes) |
| `amount_applied` | decimal(14,2) | USD amount applied |
| `applied_at` | timestamp | When the application was recorded |
| `created_at` / `updated_at` | timestamps | |

## Files Changed
### Backend
- `app/Services/CreditService.php` — new service (199 lines): createFromReferral, completeRenewalWithCredit, getBusinessCredit, getUserCredit, applyToRenewal, getHistoryForOwner, getPendingPayouts, getAllCredits
- `app/Services/ReferralService.php` — `markActive()` now calls `creditService->createFromReferral()` when `rewardAmount > 0`
- `app/Services/Payment/GatewayService.php` — `initiatePayment()` auto-applies credit on renewal via `applyToRenewal()`; delegates to `completeWithCredit()` (private → `creditService->completeRenewalWithCredit()`) when remaining amount ≤ 0
- `app/Http/Controllers/Api/CreditController.php` — `balance()` and `history()` endpoints
- `app/Http/Controllers/Api/Platform/PlatformCreditController.php` — `index()`, `pendingPayouts()`, `recordPayout()` for admin panel
- `app/Http/Controllers/Api/ReferralController.php` — `myEarnings()` now includes `available_credit`, `business_credit`, `user_credit`
- `app/Models/BillingCredit.php` — new model with morphTo owner, referral and applications relations, `amount_remaining` accessor
- `app/Models/CreditApplication.php` — new model with credit, subscription, billingPayment relations
- `database/migrations/2026_07_26_000004_create_billing_credits_table.php`
- `database/migrations/2026_07_26_000005_create_credit_applications_table.php`
- `routes/api/v1/credits.php` — credits/balance, credits/history
- `routes/api.php` — require credits.php
- `routes/api/v1/platform.php` — platform/credits with index, pending-payouts, payout

### Frontend
- `src/renderer/modules/referral/api/ReferralTypes.ts` — added credit fields to earnings response
- `src/renderer/shared/components/layout/ReferralDropdown.tsx` — credit balance banner
- `src/renderer/modules/referral/pages/PipelineReferralsPage.tsx` — USD display + credit section
