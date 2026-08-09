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

## Files

| Area | File | Role |
|---|---|---|
| Migration | `database/migrations/2026_08_10_000000_add_discount_rate_to_sales_reps_table.php` | Adds `discount_rate`; migrates existing reps to 20/30 |
| Model | `app/Models/SalesRep.php` | `discount_rate` fillable + decimal cast |
| Request | `app/Http/Requests/SalesRepRequest.php` | `discount_rate` rules + safe-zone `withValidator` |
| Service | `app/Services/SalesRepService.php` | `create` writes `discount_rate` → code `discount_value`; `create` safe-zone guard; `update` resyncs code discount; import maps `Discount Rate` (default 20) + template header |
| Resource | `app/Http/Resources/SalesRepResource.php` | exposes `discount_rate` |
| FE form | `Frontend/.../PlatformSalesRepFormModal.tsx` + `SalesRepCommissionSection.tsx` | decoupled fields + live split/hint |
| FE table | `Frontend/.../PlatformSalesRepsPage.tsx` | Referee Discount column |

## Revisit Triggers

| Trigger | Why |
|---|---|
| Product wants RECURRING rep commission (e.g. 30% of every monthly charge) | Not modeled; requires a vested-commission ledger + scheduled per-renewal accrual (see `2026-07-31-referral-reward-economics.md` Option 3). Current model is one-time-per-signup by design, which caps liability |
| A sales-rep code should override the safe zone | Guard is intentional; overriding requires an owner/sales-manager override path with explicit economics review |
| Marketing asks to advertise "30% of everything the business pays" | Not true under current model (one-time). Would require a campaign flag + vested ledger |