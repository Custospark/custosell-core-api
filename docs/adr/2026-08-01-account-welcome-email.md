# ADR-015: Welcome email on account creation

**Date:** 2026-08-01
**Status:** Accepted

**Context:** New users got no email after registering. The platform already had a standard transactional email (`emails.standard` view + `StandardEmail` mailable) used for password resets, dormant-account warnings, and notification digests, but nothing fired on account creation for personal, storefront-buyer, or business accounts.

**Decision:**
- Introduce a `UserRegistered` domain event carrying the `User` and, when present, the `Business`.
- Dispatch it from both registration paths:
  - `UserService::register` (personal / storefront-buyer accounts, `POST /auth/register`)
  - `BusinessService::register` (business accounts, `POST /businesses/register` — dispatched **after** the transaction commits)
- A synchronous `SendWelcomeEmail` listener sends the existing `StandardEmail` mailable (brand name, logo, personalised greeting, feature list, "Get Started" CTA to `FRONTEND_URL`, offline-first pro tip).
- Email failures are caught and logged — they never fail or roll back registration.
- Fixed a latent bug in `StandardEmail::content()` that passed the logo as `logoPath` while the view reads `logoUrl`, so the header logo now actually renders.

**Consequences:**
- Every new account (personal, storefront-buyer, business) receives a branded welcome email.
- One event → one listener means future post-registration side effects (onboarding nudges, analytics) can be added without touching the services.
- StandardEmail now renders its logo, improving all emails sent through it.

**Tests:** `tests/Feature/SendWelcomeEmailTest.php` (with/without business, `Mail::assertSent`)
