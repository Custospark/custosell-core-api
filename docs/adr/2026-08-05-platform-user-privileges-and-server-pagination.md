# Platform Admin User Privileges & Server-Side Pagination

- **Date:** 2026-08-05
- **Status:** Accepted
- **Stack:** Backend (Laravel API)

## Context

Platform operators needed to grant and modify per-user (and per-linked-business)
privileges directly from the admin UI without a dev or DB hand-edit: subscription
plan, billing cycle, subscription status, onboarding-fee paid state, next billing
date, account type, and - as a last line of defense - user email and password.
The Users/Businesses admin listings also needed server-side pagination and
filtering on server-computed fields (`account_type`, `subscription_status`).

## Decision

### Routes

- `PATCH /platform/users/{id}/privileges` - single-user privileges update.
- `POST /platform/users/bulk-privileges` - bulk update across multiple users.
- Both under the `platform:platform.users.manage` middleware.

### Service (`PlatformUserService`)

- `updatePrivileges()` / `bulkUpdatePrivileges()`:
  - Update each field independently - only supplied fields change.
  - No existing subscription → create one via `subscribe()` then immediately
    `activateAfterOnboarding()` (never left in a pending state).
  - Password set as plaintext (min 8 chars).
  - Email updates `users.email` only (never `businesses.email`), lowercased and
    trimmed, uniqueness-checked against `LOWER(email)`.
  - Every change audited under `user.privileges.fields` and
    `user.privileges.subscription`.
- `paginateTenantUsers()` / `paginateTenantBusinesses()`: eager-load
  `business.subscription.plan`; support `page`/`per_page`, `search` (name, email,
  phone, business name), `account_type`, `is_active` (users) and `status`,
  `subscription_status` (businesses).

### Validation

- `subscription_status`: `trial|active|past_due|suspended|cancelled|expired`.
- `account_type`: `business|personal|storefront_buyer`.

### Resource & middleware

- `PlatformUserResource` exposes `account_type`, `is_active`, and a `subscription`
  object (plan, cycle, status, onboarding-fee paid, next billing date).
- New `EnsureAccountUsable` middleware (alias `account.usable`): blocks deactivated
  users and restricted/suspended businesses from privileged actions; platform
  admins pass; adds `X-Account-Status` response header.

### Pagination contract (uniform top-level shape)

- Users (`/platform/users`) and role members (`/platform/roles/{id}/members`)
  previously returned a Laravel resource-collection shape
  (`{ data, links, meta: {...} }`). Businesses (`/platform/businesses`) already
  returned the raw paginator shape (`{ data, current_page, per_page, total, last_page, ... }`).
- Both are now aligned on the **raw paginator top-level shape** to match the
  frontend `PaginatedPlatformResponse` contract: each row is transformed through
  `PlatformUserResource` before the paginator is serialized.
- `per_page` is clamped to `[15, 500]` on users to match businesses.

## Consequences

- Platform admins can self-service subscription/privilege corrections; fully
  audited.
- Server-side listing keeps admin tables responsive and enables filtering on
  server-computed fields.
- Email is normalized (lowercase/trimmed) and uniqueness enforced case-insensitively.

## Update - Status-Aware Subscription Dates & Before/After Diffs (2026-08-05)

### Context

The privileges editor previously sent a single `next_billing_date` regardless of
status. An admin picking "trial" expected to see the trial end date, but the UI
and service only handled "next billing" - the date shown and written did not
match the status being selected.

### Decision

- **Status → date mapping** (`PlatformSubscriptionPrivilegeService`):
  - `trial` → `trial_ends_at`
  - `active` → `next_billing_date`
  - `past_due` → `grace_period_ends_at`
  - `suspended` → `suspended_at`
  - `cancelled`/`expired` → `ends_at`
- Only the date field for the effective status is written; the endpoint now
  accepts `trial_ends_at`, `grace_period_ends_at`, `suspended_at`, `ends_at` in
  addition to `next_billing_date` (single + bulk validation).
- **Before/after audit**: every privilege change is recorded as a `diff` map of
  `field => { from, to }` in audit metadata (`user.privileges.fields` for account
  fields, `user.privileges.subscription` for subscription fields). Password is
  recorded only as `password_changed` - the stored hash cannot be read back.
- Extracted the subscription change logic from `PlatformUserService` into
  `PlatformSubscriptionPrivilegeService` to keep both files ≤500 lines.
- `PlatformUserResource` now exposes all lifecycle dates
  (`starts_at`, `trial_ends_at`, `next_billing_date`, `grace_period_ends_at`,
  `suspended_at`, `ends_at`, `cancelled_at`) and defaults `account_type` to
  `storefront_buyer` (shopping accounts) when a user has no stored account type.
- The frontend modal shows the date field that applies to the chosen status and
  renders a "Changes to apply" before → after summary for all changed fields.

## Files

- `app/Services/Platform/PlatformUserService.php`
- `app/Services/Platform/PlatformSubscriptionPrivilegeService.php`
- `app/Http/Controllers/Api/Platform/PlatformUserController.php`
- `app/Http/Resources/PlatformUserResource.php`
- `app/Http/Middleware/EnsureAccountUsable.php`
- `routes/api/v1/platform.php`, `bootstrap/app.php`

## Related

- `2026-08-05-owner-business-seeder.md` - owner/admin seeder guarantee.
- Frontend ADR `2026-08-05-platform-user-privileges-and-server-pagination.md`.
