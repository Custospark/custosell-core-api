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
