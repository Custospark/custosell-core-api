# ADR: Referral Reward System — How it Works and Against What Base

## Date
2026-08-09

## Status
Accepted (supersedes the base formulas in `2026-07-30-referral-discount-architecture.md`, `2026-07-26-referral-credit-system.md`, and `2026-07-31-referral-reward-economics.md` for how the base is finally resolved; flow/duration/credit mechanics below remain authoritative)

## Purpose
Document exactly how the referral program computes the **referee discount** and the **referrer reward/commission**, and **against what amount each is calculated**, as verified in production-like tests. This is the single source of truth for the numbers Oscar sees in the UI and in `[PaymentAudit]` logs.

## One-Line Model
A referral code is a **one-shot offer per new signup**:

- **Referee:** gets a discount on a **fixed number of billing periods** (`discount_duration_months`, default 1). After those, full price — forever.
- **Referrer:** earns a **one-time** reward credit (no recurring commission), sized as a percentage/flat of what the referee **actually paid**.
- **Code itself:** remains redeemable unless `expires_at` (date) or `max_uses` (cap) is set. One redemption **per business/account** — unlimited different referrees can use a code whose cap is unlimited.

Duration and usage limits are **independent dials**: `discount_duration_months` = number of periods ONE signup's discount lasts; `max_uses`/`used_count` = how many different signups can redeem the code.

---

## Part 1 — The Referee Discount

### What Is the Base?
The discount percentage is always computed against the **amount the charge actually uses at payment time** — the RESOLVED plan, not the plan captured at registration:

| Charge type | Discount base |
|---|---|
| Onboarding (fee unpaid) | `plan.onboarding_fee_usd` |
| Subscription / renewal, monthly | `plan.price_monthly_usd` |
| Subscription / renewal, yearly | `plan.price_yearly_usd` (fallback `monthly*12`) |
| Top-up | monthly (or yearly/12) rate × months |

### Where It is Materialized
1. `processReferral()` — on code application, stores `referral.discount_applied` as an **estimate** (registration-time plan) + transitions status `pending`. Creates **no** credit.
2. `GatewayService::initiatePayment()` — recomputes the discount against the **resolved** plan via `ReferralService::resolveDiscountForCharge($referral, $plan, $paymentType, $effectiveCycle)` and subtracts it directly from the amount handed to the gateway. If the effective discount differs from the stored one, it persists the corrected `discount_applied`. (This is the fix for the "10% against $40 Essential vs $95 Professional" bug.)
3. The discount is **stored in USD** on the referral. `original_amount` (post-discount) is recorded in the payment's `metadata`.

### Formula (PERCENTAGE & FLAT & FREE_MONTH)
```
base          = (paymentType === 'onboarding' && fee>0) ? onboarding_fee : (yearly ? yearly_price : monthly_price)
discount      = PERCENTAGE ? round(base * value/100, 2)
              | FLAT_AMOUNT ? value
              | FREE_MONTH ? base
discount      = min(discount, base)          // cap at the charge itself
```

### Discount Duration → Credit for Later Months
- First period's discount is applied directly to the first charge.
- `markActive()` then creates a BillingCredit for the referee **per remaining period, sized against the RECURRING charge** — the monthly price (or the monthly equivalent on a yearly cycle, `yearly/12`) — not the fee-shaped `discount_applied`. So "N months at X%" gives a genuine X% off the current charge each period.
- Formula: `credit = round(discountAgainstBase(code, recurring_monthly) * (duration − 1), 2)`.
- Example (Professional: fee $95, monthly $54, 10% off, duration 2): month-1 charge $61.00 (95 − 9.50), then one remaining month credit = 10% × $54 = **$5.40** (not $9.50), consumed FIFO on the next renewal.

### Where the skew previously was (fixed 2026-08-09)
Older `markActive` created the lump as `discount_applied × (duration − 1)`, where `discount_applied` came from the **onboarding fee** base. On a 30%-off, 2-month Professional code that meant month 2 got `30% × $95 = $28.50` instead of `30% × $54 = $16.20`, silently turning "2 months at 30%" into "month 2 at ~53%" (and the inverse skew on plans whose monthly > fee). The credit is now reproducible off the recurring charge via the shared `ReferralService::discountAgainstBase()` helper used by both `resolveDiscountForCharge()` and `markActive()`.

---

## Part 2 — The Referrer Reward (and Sales-Rep Commission)

### The Reward Base (THE answer to "against what?")
The reward is a percentage/flat of **the amount the referee actually paid** — defined as:

> the confirmed payment's `metadata.original_amount` (USD, **after** the referral discount, **before** credit/gateway convert).

This is read from the most recent `completed` payment on the subscription (`markActive()`). If no paid amount is present, it falls back to `plan base − discount_applied`.

This base is deliberate: the referrer earns **in proportion to real cash flow**. A free-month referee (paid $0) yields a $0 reward — the structural cap.

### Formula
```
paid_base    = paidPayment.metadata.original_amount  (USD, post-discount)
reward       = PERCENTAGE  ? round(paid_base * reward_value/100, 2)
          | FLAT_AMOUNT ? reward_value
          | FREE_MONTH  ? paid_base
```
- Default program: `reward_value = 15`, i.e. **15% of what the referee paid**.
- Sales-rep codes: commission follows the same base logic (`commission_rate` % or flat of `paid_base`).
- **CAMPAIGN codes (company-owned, created by platform admins): earn NO reward.** The company shouldn't credit itself free months for its own promotions. `reward_amount` is forced to 0 and no `BillingCredit` is created; referee discount still applies. (Fix 2026-08-09 — the DB default `reward_type = 'free_month'` plus the admin form not sending `reward_type` was silently granting the company a full free-month credit per signup.)

### When
At settlement (`markActive()`, after payment confirms). Before that the referral sits `pending`. No payment → no reward — zero prepaid liability.

---

## Part 3 — Duration / Cap / Expiry Semantics

| Dial | Meaning | Default |
|---|---|---|
| `discount_duration_months` | Discount periods for ONE referee (billing period #1 being the onboarding charge) | 1 |
| `max_uses` / `used_count` | How many different signers can redeem the code (`markUsed()` on each signup; `isValid()` rejects past cap) | null = unlimited |
| `expires_at` | Date until which the code is redeemable | null = never |

**Duration does NOT mean "referrer keeps earning."** The referrer's reward is one-time per signup. Long durations only extend the referee's discount (and thus the company's cost), at a constant one-time reward.

## Company economics (per signup, Enterprise plan: fee $200, 10% off, reward 15%)
| Lines | Amount |
|---|---|
| Referee pays ($200 − $20) | $180 |
| Referrer reward (15% of $180) | $27 (one-time) |
| Company net, month 1 | $153 |
| Renewals 2+ | full $200 each, no discount, no reward |

Payback? none — the ultimate is a one-time reward (~$27) + N discount periods. e.g. a 12-month duration nets ~$127 less than a no-code year of $2,400 (3-month: −$47, 9-month: −$107, 12-month: −$127).

## Audit trail (`PaymentAudit`, `[logs]`)
`[PaymentAudit]` on: initiate-payment resolved (plan fees, disc base, discount_applied), referral reward/commission computed (reward_base_usd, discount_applied, reward_amount_usd, commission_earned_usd), referral credit created (owner).

---

## Files
| Area | File | Role |
|---|---|---|
| Gateway | `app/Services/Payment/GatewayService.php` | recompute discount at charge time (`resolveDiscountForCharge`), `original_amount` metadata |
| Service | `app/Services/ReferralService.php` | `resolveDiscountForCharge` (discount formula + `markActive` (reward base from paid payment OR plan−discount), duration credit, status transitions |
| Contract | `app/Services/Contracts/ReferralServiceInterface.php` | declares `resolveDiscountForCharge` |
| Credits | `app/Services/CreditService.php` | `createFromReferral`, `applyToRenewal` (FIFO), `completeRenewalWithCredit` |
| Defaults | `app/Services/UserService.php`, `BusinessService`, seeders | default reward 15%, discount 10% |
| Config | `app/Models/ReferralCode.php` casts | `discount_duration_months`, `max_uses`, `expires_at` |

---

## Revisit Triggers
| Trigger | Why |
|---|---|
| Reward-base message ("15% of what your friend pays") feels too complex for marketing | The net-base model is honest but harder to slogan; a full-price-base campaign intentionally bypasses the cap and must be gated |
| Introduce single-plan models | The dynamic base collapses; simpler flat 10/10 symmetric becomes viable |
| Need recurring (vested) referrer earnings | Requires a vests ledger + scheduled renewals — currently not modeled |
| Free-month codes as primary channel | Under net-base, they pay $0 reward; if that kills energy, revisit with a flat referrer payout |