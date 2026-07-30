# ADR: Referral Discount Architecture — Two Key Decisions

## Date
2026-07-30

## Status
Accepted

## Context
The referral and campaign code system needs to apply discounts to subscription payments. Two architectural decisions shape how discounts are calculated and when they take effect:

1. **What is the discount base?** — Against what amount is the percentage calculated?
2. **When is the discount applied?** — At invoice creation, at payment time, or as a pre-existing credit?

This ADR documents both decisions, their rationale, trade-offs, and conditions under which they should be revisited.

---

# Decision 1: Dynamic Discount Base

## The Decision
The discount/reward percentage is calculated against the **amount the user is actually being charged at that moment**, not against a fixed reference like the recurring subscription price.

| Scenario | Discount Base |
|---|---|
| Onboarding (onboarding fee unpaid) | `plan.onboarding_fee_usd` |
| Normal monthly subscription payment | `plan.price_monthly_usd` |
| Normal yearly subscription payment | `plan.price_yearly_usd` |
| Prorated upgrade charge | `proration_due_usd` (the prorated amount) |
| Renewal after onboarding fee paid | `plan.price_monthly_usd` or `plan.price_yearly_usd` |

For example, a 20% code on the Essential plan ($40 onboarding fee, $0/month) gives $8 off the onboarding fee — not $0 off a $0 monthly price.

## Pros

1. **Honest discounting** — The user sees the discount apply to what they are actually handing over. A "$0 monthly" plan does not yield a "$0 discount" during onboarding.
2. **Predictable company cost** — The discount is always a known percentage of a known receivable. No scenario where a large percentage discount applies to an unexpectedly large amount.
3. **Simpler frontend display** — `totalDue = fee - (fee * discount%)` works uniformly across onboarding, subscription, and upgrade.
4. **No arbitrage** — Users cannot exploit a gap between the discount base and the actual payment. Discount is always proportional to real cash flow.

## Cons

1. **Not industry standard** — Most SaaS discounts are against the recurring subscription price. This deviation may surprise users accustomed to "20% off your monthly plan."
2. **Onboarding fee skew** — A high onboarding fee (e.g., $200) paired with a low monthly price ($10) means the referral gets most of their benefit upfront. The referrer's reward is similarly skewed — large upfront, small ongoing.
3. **Upgrade proration complexity** — The discount base for upgrades is the prorated amount, which is computed server-side and may not be known to the frontend at code-application time. This requires the discount to be stored in USD on the referral record rather than as a percentage.
4. **Inconsistent messaging** — "20% off for 3 months" literally means different dollar amounts depending on when the code is applied (before vs. after onboarding fee is paid).

## Revisit Triggers

| Trigger | Why |
|---------|-----|
| Users complain that the discount during onboarding is "smaller than expected" compared to the monthly price | May indicate the mental model mismatch is causing churn |
| The platform introduces plans with a $0 onboarding fee | The dynamic base collapses to $0 and the discount provides zero value at the only point it's applied (first month $0) |
| Plans standardize to always-paid monthly (no free tier, no separate onboarding fee) | The distinction between onboarding fee and subscription price disappears; always-against-monthly becomes equivalent |
| Marketing runs a campaign advertised as "20% off your monthly plan" and users onboard during the free-trial period | The discount against $0 onboarding fee contradicts the marketing copy |
| The product moves to annual-only billing | Fewer payment types simplifies the base to a single number |

---

# Decision 2: Discount Applied at Payment Time (Not Invoice Time, Not Pre-Created Credit)

## The Decision
The referral discount is **not** materialized as a `BillingCredit` before payment. Instead, `GatewayService::initiatePayment()` checks for a pending referral and reduces the payment amount directly by `referral.discount_applied`. After payment confirms, `markActive()` creates BillingCredits for the referrer's reward and the referee's remaining discount months.

Flow:
```
User applies code → processReferral() stores referral with discount_applied (no credit)
User clicks Pay → GatewayService reduces amount by discount_applied → sends reduced amount to gateway
Payment confirms → markActive() creates BillingCredit for referrer reward + referee remaining months
```

## Pros

1. **Zero prepaid liability** — No credit exists before money is in hand. If payment fails, there is no credit to reverse or expire.
2. **No double-discount risk** — The discount is consumed atomically with the payment it applies to. There is no separate "credit consumption" step where a bug could apply a credit alongside a fresh discount.
3. **Clean reconciliation** — Every payment has exactly one discount applied at most. The payment record itself contains the discounted amount; no need to cross-reference a separate credit table to understand what the user paid.
4. **Resilient to payment failure** — If the STK push fails, the referral remains PENDING with its `discount_applied`. The user can retry and the same discount re-applies. No credit to recreate or re-issue.
5. **Simpler state machine** — Referral transitions: `pending → activated → reward_credited`. No intermediate state where a credit exists but the payment hasn't cleared.

## Cons

1. **No pre-payment discount visibility in credit balance** — The `GET /api/v1/referrals/earnings/me` endpoint shows `available_credit` = 0 until payment clears. The frontend must read `subscription.referral.discount_applied` separately to show the pending discount.
2. **Credit consumption logic lives in two places** — Referral discounts are applied in GatewayService at payment time; BillingCredits (from referrer rewards) are consumed in CreditService at renewal time. Two different mechanisms for what feels like the same concept ("get money off").
3. **Harder to expire discounts** — A pending referral with an unused discount cannot be easily expired mid-payment-attempt. If the user applies a code and never pays, there is no credit to expire anyway (the referral is still PENDING), but the `discount_applied` value stays on the referral record indefinitely.
4. **Upgrade payment split** — For prorated upgrades, the upgrade itself is confirmed first (via `useUpgrade()`), then payment is collected separately. If the referral discount is on the subscription, it must be re-read by GatewayService after the upgrade mutates the subscription. The proration amount itself does not carry the discount.

## Revisit Triggers

| Trigger | Why |
|---------|-----|
| The team wants to show a unified "available credit" balance that includes pending referral discounts | Requires merging discount_applied into the earnings endpoint or creating in-memory credit before payment |
| Users frequently abandon payment after applying a code, then ask "where did my discount go?" | Indicates the lack of a pre-payment credit object is causing confusion (mitigated by subscription.referral display) |
| The business needs to offer "stackable" discounts (multiple codes on one payment) | Multiple pending referrals would each need to be consumed; billing credits provide a natural stacking mechanism |
| Payment gateway requires the full (undiscounted) amount to be authorized, with discount applied as a separate ledger entry | The direct amount reduction in GatewayService would need to be replaced with a post-authorization credit |
| Regulatory requirement to show "discount as a separate line item" on payment receipts | Currently the discount is invisible to the gateway — it only sees the reduced amount |

---

## Summary

Both decisions prioritize **financial safety** (no prepaid liability, no double-discounting, atomic consumption) over **industry convention** or **display convenience**. The trade-off is a slightly more complex frontend display (reading `discount_applied` from the subscription rather than a universal credit balance) and a non-standard discount base that must be clearly communicated to users.

These decisions should be revisited when the product成熟s to a point where the discount base becomes unambiguous (e.g., no free tier) or when the payment flow would benefit from a unified credit model (e.g., stackable codes, regulatory receipt requirements).
