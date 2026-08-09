# Custosell Backend documentation

Technical documentation for the Custosell backend (Laravel + PHP).

## Quick start

| If you need… | Start here |
|--------------|------------|

## Architecture decisions (ADR)

| Document | Contents |
|----------|----------|
| [2026-07-26-referral-credit-system.md](./adr/2026-07-26-referral-credit-system.md) | Referral credit system — auto-apply referral earnings to subscription renewals |
| [2026-07-30-payment-architecture.md](./adr/2026-07-30-payment-architecture.md) | Payment architecture — authoritative amounts, atomic side effects, currency handling, zero-cost upgrades |
| [2026-07-30-referral-discount-architecture.md](./adr/2026-07-30-referral-discount-architecture.md) | Referral discount architecture — dynamic discount base, discount applied at payment time |
| [2026-07-31-referral-reward-economics.md](./adr/2026-07-31-referral-reward-economics.md) | Referral reward economics — reward = % of amount actually paid, 10% off / 15% reward split |
| [2026-08-01-account-welcome-email.md](./adr/2026-08-01-account-welcome-email.md) | Welcome email on account creation — UserRegistered event + SendWelcomeEmail listener |
| [2026-08-01-default-listed-products-bulk-listing.md](./adr/2026-08-01-default-listed-products-bulk-listing.md) | New products default to listed (supply + storefront); bulk list/unlist endpoint |
| [2026-08-02-branch-stock-transfer-excludes-services.md](./adr/2026-08-02-branch-stock-transfer-excludes-services.md) | Branch stock transfer excludes service items (no branch stock for non-inventory services) |
| [2026-08-02-subscription-state-machine-date-driven.md](./adr/2026-08-02-subscription-state-machine-date-driven.md) | Subscription state machine — date-driven transitions, date-setting points, access fairness |
| [2026-08-03-billing-cycle-authoritative.md](./adr/2026-08-03-billing-cycle-authoritative.md) | Billing cycle is authoritative server-side — yearly/monthly charges, renewal lock, persistence |
| [2026-08-03-block-yearly-to-monthly-upgrade.md](./adr/2026-08-03-block-yearly-to-monthly-upgrade.md) | Block yearly→monthly upgrade when unused credit exceeds the new monthly charge (amount-based) |
| [2026-08-03-early-renewal.md](./adr/2026-08-03-early-renewal.md) | Allow active subscriptions to renew early — advance-pay to keep next_billing_date in the future |
| [2026-08-03-renewal-top-up.md](./adr/2026-08-03-renewal-top-up.md) | Renewal top-up — pay in advance for any duration, amount prorated to the stored billing cycle |
| [2026-08-05-owner-business-seeder.md](./adr/2026-08-05-owner-business-seeder.md) | Owner business seeder (Enterprise, Dec 2030) — update-or-create, admin via eligibility |
| [2026-08-05-platform-user-privileges-and-server-pagination.md](./adr/2026-08-05-platform-user-privileges-and-server-pagination.md) | Platform-admin user privileges endpoints + server-side Users/Businesses pagination |
| [2026-08-09-referral-reward-system-against-what-base.md](./adr/2026-08-09-referral-reward-system-against-what-base.md) | Referral reward system — exact base resolution (referee discount + referrer reward/commission against what) |
| [2026-08-10-sales-rep-safe-zone-referral-economics.md](./adr/2026-08-10-sales-rep-safe-zone-referral-economics.md) | Sales-rep safe zone — 20/30 decoupled rates, Company > Referrer > Referee guard, $95 workbook + renewal/top-up math |
