# ADR: Referral Reward Economics — Amount-Actually-Paid Base with Balanced Split

## Date
2026-07-31

## Status
Accepted

## Context
The default referral program gives the **referee 10% off** and the **referrer a 20% reward**, both calculated against the amount the referee is charged (see `2026-07-30-referral-discount-architecture.md` for the dynamic discount base). The problem:

1. **The reward exceeds collected revenue in the worst case.** A 20% reward against the discounted amount still leaves the platform holding the difference, but on a deep-discount or free-month code the give-away can approach or exceed what was actually collected.
2. **The reward can feel larger than the discount** (20% referrer reward vs. 10% referee discount), which is expensive per acquisition and reads as "referral is for the referrer," not "get your friend a deal."

The product wants to keep referral energy (both parties benefit) while guaranteeing the platform never gives away more than it collects.

## Considered Options

Three concrete options were evaluated:

### Option 1 — Symmetric 10/10 (flat, on full amount)
Referee 10% off; referrer earns 10% of the **undiscounted** base.

| | Value |
|---|---|
| Referee pays | 90% |
| Referrer reward | 10% of full base (10% of collected pre-discount) |
| Platform net | ~90% − 10% = **80%** |

**Pros:** Simple, symmetric, easy to communicate ("10% for you, 10% for a friend").
**Cons:** Halves the referrer incentive from today (20% → 10%), risking referral energy; rewards are still measured against the full base, so a free-month or deep-discount code can still make the reward disproportionate to what was paid.

### Option 2 — Acquirer-favored but capped (CHOSEN)
Referee 10% off; referrer earns **15% of the amount actually paid** (base minus the referral discount).

| | Value |
|---|---|
| Referee pays | 90% of base |
| Referrer reward | 15% × 90% = 13.5% of base |
| Platform net | 90% − 13.5% = **76.5%** |

**Pros:**
- Referrer still earns **more than the referee saves** (15% of the paid amount ≈ 13.5% of base, vs. 10% saved), preserving referral energy.
- **The give-away never exceeds what's collected.** On a free-month code (referee pays $0) the referrer earns $0; on a 50%-off code the reward shrinks proportionally. Cap is structural, not an estimate.
- Referrer earnings stay correlated with real cash flow — the more the referee actually pays, the more the referrer earns.

**Cons:**
- Referrer reward drops from 20% → ~13.5% of base (mild incentive reduction vs. today).
- Slightly more complex math to communicate: "15% of what your friend pays" instead of "20% of their plan."

### Option 3 — Retention-vested
Small upfront reward (e.g., 5%) plus a vested reward on renewals (e.g., 5% of each month the referee stays subscribed, credited at renewal).

**Pros:** Strongest long-term retention incentive; aligns referrer earnings with lifetime value.
**Cons:** Most complex — requires scheduled reward crediting per renewal, a vests ledger, and deferred-credit bookkeeping; weakest for driving immediate conversions; over-engineered for the current monthly-billing model.

## Decision

Adopt **Option 2**:

- Default program stays **referee 10% off**, referrer reward default drops from **20% → 15%**.
- The reward (and sales-rep commission, for consistency) is calculated as a percentage of the **amount actually paid**, i.e. `max(0, base − discount_applied)` — NOT the undiscounted base.
- Flat-amount rewards and commissions are unchanged (they are already absolute, not percentage-of-base).
- A free-month code yields a $0 referrer reward, since the referee paid $0. This is the cap working as designed.

### Implementation Notes
- Changed in `ReferralService::markActive()`: introduced `$paidBase = max(0, $rewardBase − discount_applied)` and used it for `PERCENTAGE` rewards, `PERCENTAGE` commissions, and `FREE_MONTH` rewards.
- Defaults updated from `reward_value 20` → `15` in `UserService` (both auto-code spots), `BusinessService`, and the `SimulateCreditDeduction` console command.
- `discount_applied` remains stored on the referral record, so the paid base is computable at settlement time (`markActive`) without schema changes.

### Economics on the Essential plan ($40 onboarding fee, unpaid)
| Line item | Value |
|---|---|
| Base (onboarding fee) | $40.00 |
| Referee discount (10%) | −$4.00 |
| Amount actually paid | $36.00 |
| Referrer reward (15% of paid) | **$5.40** |
| Platform net | $36.00 − $5.40 = **$30.60 (76.5%)** |

## Revisit Triggers

| Trigger | Why |
|---------|-----|
| Referral-driven conversion rate drops meaningfully after the 20% → 15% change | The 5-point incentive cut may cost more in acquisition than it saves — restore a higher reward or switch to Option 3's renewal-vested model |
| Marketing wants a "referrer gets 20% of the full plan" campaign | Percentage-of-full-base campaigns bypass the cap by design; either disallow them for default codes or revisit the cap for campaign-specific codes |
| A sales-rep commission program matures independently | Commission was aligned to the paid base for consistency; a dedicated sales-rep compensation policy may warrant its own base (e.g., full price) — revisit if sales incentive targets conflict |
| The product moves to annual-only billing or standardizes no-free-tier plans | The dynamic base collapses to a single number; simpler "10% / 10% of plan price" symmetric models become viable (Option 1) |
| Free-month codes become a primary acquisition channel | Under this ADR free-month codes pay the referrer $0; if that kills free-month referral energy, revisit whether free-month codes should carry a fixed referrer reward instead |
| Renewal/recurring reward crediting infrastructure exists (scheduled credits, vests ledger) | Unblocks Option 3, which is strictly better for retention if the bookkeeping cost is acceptable |

## References
- Supersedes the reward-rate portion of `2026-07-30-referral-discount-architecture.md` (base definition remains dynamic; reward base now = amount actually paid).
- `2026-07-26-referral-credit-system.md` — reward is credited to the referrer as a `BillingCredit` at settlement.
