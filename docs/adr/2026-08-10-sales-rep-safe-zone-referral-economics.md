# ADR: Sales-Rep Safe Zone + Referral Economics Workbook (Company > Referrer > Referee)

## Date
2026-08-10

## Status
Accepted. Extends `2026-08-09-referral-reward-system-against-what-base.md` (the base-resolution source of truth) with the sales-rep rate decoupling and the Company > Referrer > Referee guard. This workbook is the canonical $95 Professional onboarding example plus recurring-renewal and top-up math.

## One-Line Model
A referral/rep code is still a **one-shot offer per new signup**. What changed for **sales-rep codes**:

- **Referee discount** is now its own dial (`sales_reps.discount_rate`, default 20) — decoupled from the rep's commission. Previously `discount_value` mirrored `commission_rate`, so a 50% commission auto-produced a 50% referee discount and the compounding 50/50/25 outcome.
- **Rep commission** (`commission_rate`, default 30) is a percentage/flat of what the referee **actually paid**, earned **once** at activation — not per renewal, not per top-up.
- **Safe zone enforced** so the ordering **Company > Referrer > Referee** always holds:
  - `discount_rate ≤ 30%`
  - commission strictly between `d/(1−d)%` and `50%`
  - enforced in `SalesRepRequest.withValidator()` **and** `SalesRepService::create()` (covers the import path).
- **Existing reps migrated** to 20/30 by `2026_08_10_000000_add_discount_rate_to_sales_reps_table`.

The math below uses the **Professional** plan as shipped: **fee $95, monthly $54, yearly $540** (`PlanSeeder`).

---

## Workbook — $95 Professional Onboarding, per code type

### A. Sales-rep code — 20% discount / 30% commission (current default)

| Step | Math | Result |
|---|---|---|
| Referee discount | 20% × 95 | $19.00 off |
| Referee pays (original_amount) | 95 − 19 | $76.00 |
| Referrer commission (one-time) | 30% × 76 | $22.80 |
| Company keeps | 76 − 22.80 | $53.20 |

Order: Company **$53.20** > Referrer **$22.80** > Referee **$19.00**. ✓
Commission is a **cash payout** (PayoutService), not a credit.

### B. Normal referral code — 10% discount / 15% reward (personal-account default)

| Step | Math | Result |
|---|---|---|
| Referee discount | 10% × 95 | $9.50 off |
| Referee pays | 95 − 9.50 | $85.50 |
| Referrer reward (one-time) | 15% × 85.50 | $12.83 |
| Company keeps (effective) | 85.50 − 12.83 | $72.68 |

Order: Company **$72.68** > Referrer **$12.83** > Referee **$9.50**. ✓
Referrer reward is a **BillingCredit** (not cash). A `discount_duration_months > 1` also extends the referee's discount to later periods (see Renewal below).

### C. Campaign code — 30% discount / 0 reward (company-owned promo, e.g. DEZFQBBB)

| Step | Math | Result |
|---|---|---|
| Referee discount | 30% × 95 | $28.50 off |
| Referee pays | 95 − 28.50 | $66.50 |
| Referrer reward | 0 — campaign codes earn nothing (company would be crediting itself) | $0.00 |
| Company keeps | 66.50 | $66.50 |

Order: Company **$66.50** > Referee **$28.50**, no referrer. ✓

### D. Side-by-side — $95 Professional onboarding

| Code | Referee pays | Referee saves | Referrer gets | Company nets |
|---|---|---|---|---|
| Sales rep (20/30) | $76.00 | $19.00 | **$22.80 cash** | $53.20 |
| Normal (10/15) | $85.50 | $9.50 | $12.83 credit | $72.68 |
| Campaign (30/0) | $66.50 | $28.50 | $0 | $66.50 |

All three satisfy **Company > Referrer > Referee**. The sales-rep code gives the referrer the largest slice while keeping the company on top.

---

## Why the commission base and the safe zone

1. **Commission is % of what was actually paid** (`markActive`: `paid_base = confirmed payment metadata.original_amount` = post-discount, pre-credit USD). Rationale:
   - The referrer earns in proportion to real collected cash flow, not list price.
   - A free-month referee (paid $0) structurally yields $0 reward — the cap is a property of the base, not a heuristic.
   - This is a deliberate, defensive engineering choice: rewards/commissions are *never* computed off full price (see the defensive "Discount vs price" pattern).
2. **The safe zone guarantees the ordering for every configuration.** Solving the compounding shares:
   - Referee saves `d·P`
   - Referrer earns `r·(1−d)·P`
   - Company keeps `(1−d)(1−r)·P`
   - `Company > Referrer` requires `r < 50%`.
   - `Referrer > Referee` requires `r > d/(1−d)`.
   - Together with `d ≤ 30%` (`(1−d)²·P > d·P` when `d < 33.3%`), every allowed combo keeps the company the biggest earner. 20/30 is comfortably inside: `min r = 20/80 = 25%`, `30 ∈ (25, 50)`. ✓
3. **Referee-facing UI needs no new wiring** — `PaymentPage`/`OnboardingPage` read `referral.discount_value` off the code, which now holds `discount_rate`, so they display the new discount automatically.

---

## Renewal math — what the architecture actually produces

Critical fact: **commission/reward is ONE-TIME per signup.** `markActive()` stores `commission_earned` once at activation and no code path recomputes it on renewals. Renewals never re-pay the referrer. This is why the referrer's slice is bounded and company economics stay sane over the subscription's life.

What renewals *do* carry: the **referee's** remaining-purchase discount, as a **BillingCredit** created in `markActive()` (one credit per period after the first, sized against the RECURRING charge), consumed FIFO by `CreditService::applyToRenewal`.

### Renewal math per code (Professional, monthly $54, default `discount_duration_months = 1`)

| Code | Month-1 renewal charge | Referrer payout | Company net |
|---|---|---|---|
| Sales rep (20/30) | $54.00 (no credit at duration 1) | $0 | $54.00 |
| Normal (10/15) | $54.00 | $0 | $54.00 |
| Campaign (30/0) | $54.00 | $0 | $54.00 |

With a longer lifetime, only the **referee** benefits further:
e.g. sales-rep code with `discount_duration_months = 3`, monthly $54:
- Month 1: charged $43.20 (20% × $54 applied via the code's discount still applying to the renewal? No — discount applies at gateway only while referral is `pending`).
  - At gateway, the referral discount applies only while the referral is **PENDING** (`GatewayService` loads `PENDING` referrals). Once ACTIVE, renewals rely on the **standing BillingCredit** instead.
  - `markActive` baked `20% × $54 × 2 = $21.60` as ONE credit for months 2–3.
- Month 2: charge $54, credit consumes $10.80 → **pays $43.20**.
- Month 3: charge $54, credit consumes $10.80 → **pays $43.20**.
- Month 4+: full **$54.00**.
- Referrer: still **$22.80**, paid once at activation.

So "N months at X%" means: first period discounted at charge time (while pending), later periods discounted via the standing credit — never extra referrer commission.

---

## Top-up math

Top-up = buying `N` extra months at the stored-cycle rate (`GatewayService` lines 82–94): `topup_months × monthlyRate` (yearly subs use `yearly/12`). With the referral **active** (the normal case), top-up behaves like a renewal:

Professional, monthly $54, rep code 20/30, top-up 6 months:
- Solicited amount: 6 × 54 = **$324.00**.
- If no discount credit remains (duration 1): full **$324.00**. No commission. Company keeps $324.00.
- If discount credit remains, `applyToRenewal` offsets part of it FIFO (same credit the renewal uses), leaving the rest for later.
- No new `commission_earned`, no new reward.

The top-up path never re-triggers commission because the referral is already `ACTIVE` — only a genuinely `PENDING` referral triggers the gateway-level discount, and one redemption per business is enforced at apply time.

---

## Atomicity — commission/reward is ONLY recorded with a successful payment

**Verified 2026-08-10.** The referrer's reward/commission can never be created, updated, or paid without a legally-completed payment, because the entire settlement runs inside one DB transaction:

```
PesaPal webhook / credit-cover path
  └─ GatewayService::autoApprove() ── DB::transaction(...)          (HandlesPaymentApproval.php:261)
       ├─ mark payment status = completed, approved_at = now
       ├─ handlePaymentType($payment)                                (:275)
       │    └─ onboarding ─> activateAfterOnboarding($sub) ── DB::transaction(...)
       │         └─ referralService->activateForSubscription($subId)   (SubscriptionStateMachineService.php:263)
       │              └─ ReferralService::markActive($referralId) ── DB::transaction(...)
       │                   ├─ compute commission_earned / reward_amount from paid_base
       │                   └─ create referee discount BillingCredit (remaining months)
       └─ sendReceiptIfDue + audit logs                              (all inside the SAME outer tx)
```

Laravel nests the inner `DB::transaction()` calls as **savepoints**, so a failure anywhere in the chain rolls back *everything* — the payment completion, the subscription activation, referral `ACTIVE`, the referrer's commission/reward, and the referee's discount credit. There is no code path that pays/rewards a referrer for an unconfirmed payment:
- Webhook confirm → `autoApprove` (transactional).
- Gateway-bypass credit cover → `GatewayService` wraps in `DB::transaction` (lines 182, 261).
- No payment → referral stays `PENDING` → `markActive` never runs → `commission_earned` stays 0.

---

## Recommendation — keep ONE-TIME commission (do NOT go recurring)

**Decision (2026-08-10, Oscar + team):** keep commission as a one-time amount per signup, recorded at activation. Do not build recurring commission on renewals or top-ups.

### Why (lifetime math, Professional $54/mo, customer stays 12 months)

| Model | Onboarding | 11 renewals | 12-month referrer | 12-month company |
|---|---|---|---|---|
| **One-time (current)** | Rep $22.80 / Co $53.20 | Rep $0 / Co $594 | **$22.80** | **$647.20** |
| Recurring 30%/mo (hypothetical) | Rep $22.80 / Co $53.20 | Rep ~$178 / Co ~$416 | **~$201** | **~$469** |

1. **Generated cash stays with the company.** After month 1, renewals ($54 or $540/yr) are pure revenue. One-time commission = bounded, predictable CAC. Recurring commission taxes every renewal at 30% — uneconomic on a $54 product.
2. **Preserves the approved guard.** The safe-zone ordering (Company > Referrer > Referee) was ratified for the *deal*; recurring commission would let the referrer drain a large share of lifetime value while the discount keeps compounding.
3. **Zero architecture cost.** `commission_earned` is stored once; `pending = earned − payouts` stays correct. Recurring would need a vests ledger + scheduled accrual + clawback accounting (see `2026-07-31-referral-reward-economics.md` Option 3).
4. **Referral is an acquisition channel.** You pay for acquiring the customer, not for every future invoice.

### The single cheap improvement to revisit later (not built now)
**Payout timing, not earning.** A rep who brings a churn-after-1-month signup still collects all $22.80 at payout. If churn-mining ever shows up in the data, the fix is: same $22.80 total, but released in installments gated on the referee staying active (partial payouts already supported by the payout system). No new ledger. Not implemented today — tracked as a revisit trigger.

---

## Customer Acquisition Cost (CAC) workbook — $95 Professional onboarding

CAC = everything the company gives up to land one paying signup: the referee discount + the referrer's reward/commission (cash or credit). The "cost" is absent where a party earns nothing.

| Code | Referee discount | Referrer payout | **CAC** | Company net (month 1) | CAC as % of $95 |
|---|---|---|---|---|---|
| Sales rep (20/30) | $19.00 | $22.80 (cash) | **$41.80** | $53.20 | 44% |
| Normal (10/15) | $9.50 | $12.83 (credit) | **$22.33** | $72.68 | 23.5% |
| Campaign (30/0) | $28.50 | $0 | **$28.50** | $66.50 | 30% |

### CAC amortized over the customer's lifetime (Professional $54/mo, 12 months)

Recurring revenue is unaffected by referral cost (renewals carry no referral payout at duration 1), so CAC is a **front-loaded one-time charge**:

| Code | CAC (front-loaded) | Renewal revenue 12 mo | Total revenue | CAC / lifetime revenue |
|---|---|---|---|---|
| Sales rep (20/30) | $41.80 | $594 | $647.20 | **6.5%** |
| Normal (10/15) | $22.33 | $594 | $666.33 | **3.4%** |
| Campaign (30/0) | $28.50 | $594 | $660.50 | **4.3%** |

Takeaway: a sales-rep acquisition costs ~44% of the onboarding fee on day one, but falls to ~6.5% of 12-month revenue — acceptable for an acquisition channel, and far below a recurring model whose referrer share would approach 30%+ of lifetime revenue. Budgeting guard: if the target cohort LTV drops toward ~$45–50, the 44% day-one CAC becomes dangerous and discount/commission should be tightened.

---

## Fixed policy (ratified 2026-08-10) — dials locked for future teams

Prices will change; **the structural dials do not**. These are the enforceable defaults a future team should preserve and revisit only via a documented ADR change:

### P1 — Sales-rep rates: discount 20% / commission 30% (decoupled)
- `sales_reps.discount_rate` (referee) and `commission_rate` (referrer) are independent dials.
- Safe zone (enforced in `SalesRepRequest` + `SalesRepService::create` so imports can't bypass):
  - `discount_rate ≤ 30`
  - commission strictly between `d/(1−d)%` and `50%`
- This guarantees **Company > Referrer > Referee** on every allowed configuration. 20/30 is the default; any value inside the zone is legal.

### P2 — Rep commission is ONE-TIME per signup
- Earned once at activation from the referee's actually-paid amount (`metadata.original_amount`, post-discount).
- Renewals and top-ups never pay the referrer.
- **Do NOT build recurring commission** without a vested-commission ledger + scheduled accrual (see `2026-07-31-referral-reward-economics.md` Option 3) — economically destructive on a monthly-billing product (see Recommendation above).

### P3 — Rep codes are single-period (`discount_duration_months = 1`) — HARD LOCK
- Rep referral codes apply the referee discount to the **first charge only**.
- Locked in `SalesRepService::create` (explicit) **and** `ReferralCodeRequest.withValidator` (clamps admin referral-code CRUD for `sales_rep` codes).
- Why: duration > 1 creates standing monthly discount credits — the **only recurring company cost** — with **no additional earnings for the rep**. It's a pure cost dial with zero upside to the referrer, so it's gated.

### P4 — Normal/campaign codes unchanged
- Default business/personal code: discount 10% / reward 15% (of paid), already Company-first.
- Campaign codes (company-owned): discount only, **never a reward** (no self-crediting). Do not re-enable rewards on campaign codes.
- **Extended guard (2026-08-10, apply-time):** campaign codes mirror the sales-rep safe zone in `ReferralCodeRequest::withValidator`:
  - single-period hard lock (`discount_duration_months = 1`),
  - reward rejected entirely (campaign codes never carry a reward),
  - percentage discount capped at **30%**,
  - flat_amount capped at **half the cheapest active plan's onboarding fee** (currently $40 → $20; computed from the `plans` table so it tracks price changes).
  - The discount cap/reward checks fire only when those fields are *submitted* — a status-only toggle on a legacy out-of-zone campaign code is not blocked.
- The FE `PlatformCampaignCodeFormModal` mirrors the guard live (`CampaignDiscountGuardHint`): amber warning when a percentage > 30%, flat ≥ $20, or duration > 1 would be submitted; the duration field is locked to 1.

### P5 — Measurement: channel attribution before tuning rates
- Channel is derivable today: `referral_codes.owner_type` = `sales_rep | business` (normal referral) | `campaign`; no referral row → `organic`.
- Before changing any rate, measure **cohort LTV by channel** (rep-sourced vs normal-referral vs organic). Only if rep-sourced LTV is materially lower than organic do we revisit rates or add retention-gated payout installments. Attribution column/flag is a known future enhancement if cohort queries become heavy.

---

## Files

| Area | File | Role |
|---|---|---|
| Migration | `database/migrations/2026_08_10_000000_add_discount_rate_to_sales_reps_table.php` | Adds `discount_rate`; migrates existing reps to 20/30 |
| Model | `app/Models/SalesRep.php` | `discount_rate` fillable + decimal cast |
| Request | `app/Http/Requests/SalesRepRequest.php` | `discount_rate` rules + safe-zone `withValidator` |
| Request | `app/Http/Requests/ReferralCodeRequest.php` | campaign safe-zone guard (duration=1, no reward, % ≤ 30, flat < half cheapest fee) |
| Service | `app/Services/SalesRepService.php` | `create` writes `discount_rate` → code `discount_value`; `create` safe-zone guard; `update` resyncs code discount; import maps `Discount Rate` (default 20) + template header |
| Resource | `app/Http/Resources/SalesRepResource.php` | exposes `discount_rate` |
| FE form | `Frontend/.../PlatformCampaignCodeFormModal.tsx` + `CampaignDiscountGuardHint.tsx` | campaign safe-zone live hint + single-period lock; duration clamped to 1 in payload |
| FE form | `Frontend/.../PlatformSalesRepFormModal.tsx` + `SalesRepCommissionSection.tsx` | decoupled fields + live split/hint |
| FE table | `Frontend/.../PlatformSalesRepsPage.tsx` | Referee Discount column |

## Revisit Triggers

| Trigger | Why |
|---|---|---|
| Product wants RECURRING rep commission (e.g. 30% of every monthly charge) | Not modeled; requires a vested-commission ledger + scheduled per-renewal accrual (see `2026-07-31-referral-reward-economics.md` Option 3). Current model is one-time-per-signup by design, which caps liability. Recommendation (2026-08-10): keep one-time |
| Churn-mining appears in the data | Adopt retention-gated installments (same $22.80, released over active months) instead of changing earnings; no new ledger needed — partial payouts already exist |
| A sales-rep code should override the safe zone | Guard is intentional; overriding requires an owner/sales-manager override path with explicit economics review |
| Marketing asks to advertise "30% of everything the business pays" | Not true under current model (one-time). Would require a campaign flag + vested ledger |