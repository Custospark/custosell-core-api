# ADR: Plan Upgrade — Proration Charging, Billing-Cycle Preservation, and Promo Exclusion

## Date
2026-08-09

## Status
Accepted

## Purpose
Fix two defects found while verifying the upgrade flow for subscription 25 (Professional → Enterprise, yearly): (P1) the UI showed a promo discount on the upgrade that the backend never applied, and (P2) the upgrade reset the billing cycle / `next_billing_date` to a single month even though the user was charged for a full yearly cycle.

## The Two Bugs

### P1 — Consumed promo discount shown (but not charged) on upgrade
`UpgradeFlowConfirmStep` / `UpgradeFlowModal` subtracted `subscription.referral.discount_applied` (the $28.50 onboarding promo) from the upgrade amount due, so the UI read `$900.00` while the backend charged `$928.45` (`referral_discount_usd: 0` in `[PaymentAudit]`). The referral discount applies to the first `discount_duration_months` billing periods (see `2026-08-09-referral-reward-system-against-what-base.md`); it is a **subscription-period** discount and does **not** carry into a new-plan prorated up-charge.

### P2 — Paid billing cycle dropped, `next_billing_date` reset to +1 month
`GatewayService::initiatePayment()` writes `metadata.billing_cycle` defaulting to `$subscription->billing_cycle` when the top-level request omits it, and `HandlesPaymentApproval::handleUpgradeProration()` read that field to apply the plan change. Because the frontend sent `billing_cycle` only inside `metadata` (not top-level), the payment recorded `billing_cycle: monthly` for a **yearly** upgrade and `SubscriptionService::changePlan()` then set `next_billing_date = now() + 1 month` — wiping the paid full-year coverage and double-billing the user a month later.

## Decisions

| # | Decision | Rationale |
|---|---|---|
| 1 | **Promo/referral discount does NOT apply to plan upgrades.** The upgrade transaction charges the full prorated difference between the new plan price and the remaining days credit. UI must not display or subtract the consumed promo. | The discount was scoped to the original plan's first N billing periods (`discount_duration_months`). Upgrading to a different plan is a new commercial transaction; carrying the orphaned promo would mislead the user into expecting a $28.50 credit the backend would never grant. |
| 2 | **The billing cycle paid for on upgrade is authoritative and must be preserved.** `handleUpgradeProration` resolves `billing_cycle` from `subscription.metadata.pending_upgrade_billing_cycle` (recorded at quote time via `SubscriptionController::upgrade()`), falling back to payment metadata then the subscription's current cycle. | Payment metadata's `billing_cycle` is a late default (subscription's current cycle) and can mismatch the cycle the user actually paid for. `pending_upgrade_billing_cycle` is set from the user's explicit choice at the moment the upgrade is confirmed. |
| 3 | **Frontend sends `billingCycle` top-level** in `useInitiatePayment` for the upgrade payment so the backend's `effectiveCycle`, gateway description, and payment metadata all reflect the yearly choice. | Mirrors what `SubscriptionPaymentModal` already does for renewals; keeps the provider-facing description and audit log truthful. |
| 4 | **Billing credits still apply to upgrades.** Backend applies credits via `CreditService::applyToRenewal` for all payment types; both the confirm and paying steps now compute the same `creditAfterProration = min(availableCredit, prorationDue)` so the UI matches the backend. | Consistency between FE and BE (no phantom discount or hidden credit). |
| 5 | **Free trial days are never credited toward an upgrade.** The proration credit for unused days is computed from the later of `now()` and `trial_ends_at` (`paidStart`), so a subscription still inside its preserved trial only credits the *paid* portion of the current cycle. Found live: sub 26's upgrade showed "60/30 days remaining" and credited $108 (2× monthly) because `daysRemaining` counted from `now` all the way to `next_billing` (10-08) while the period was only 30 days. With the fix the credit is $54 (30/30), and the Enterprise-yearly due is $1,296 instead of $1,242. | Charging for 2 months of credit when only 1 month of Professional was paid is an under-collection; the free trial portion must not be credited as if it were paid coverage. |

## Resulting Behavior (subscription 25)
- Upgrade quote: Enterprise yearly $1,350.00 − unused-days credit $421.55 → **$928.45** (`proration_due_usd`).
- UI shows $928.45 (or local currency), no promo line, credit shown when available.
- After approval, `changePlan()` runs with `billing_cycle = yearly` → `next_billing_date = now() + 1 year`, matching the paid full-year period.
- Referral on the subscription stays untouched (campaign code, reward 0).

## Files
| Area | File | Change |
|---|---|---|
| Backend | `app/Services/Payment/Concerns/HandlesPaymentApproval.php` | `handleUpgradeProration`: resolve `billing_cycle` from `pending_upgrade_billing_cycle` first |
| Frontend | `src/renderer/modules/settings/UpgradeFlowConfirmStep.tsx` | remove promo discount line/subtraction; real credit applied amount |
| Frontend | `src/renderer/modules/settings/UpgradeFlowModal.tsx` | remove promo from paying step; `creditAfterProration` from earnings; yearly plan price (not monthly-price/`yr`); send `billingCycle` top-level |

## Revisit Triggers
| Trigger | Why |
|---|---|
| Promo discount should be pro-rated into an upgrade | Current decision: upgrades are a fresh transaction (full prorated difference). |
| Per-period upgrade charging (e.g. charge per remaining month instead of full cycle minus credit) | Would change `SubscriptionProrationCalculator` and this ADR's decision #1. |