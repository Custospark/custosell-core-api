# Platform Admin User Privileges & Server-Side Pagination

- **Date:** 2026-08-05
- **Status:** Accepted
- **Stack:** Backend (Laravel API)

## Context

Platform operators needed to grant and modify per-user (and per-linked-business)
privileges directly from the admin UI without a dev or DB hand-edit: subscription
plan, billing cycle, subscription status, onboarding-fee paid state, next billing
date, account type, and — as a last line of defense — user email and password.
The Users/Businesses admin listings also needed server-side pagination and
filtering on server-computed fields (`account_type`, `subscription_status`).

## Decision

### Routes

- `PATCH /platform/users/{id}/privileges` — single-user privileges update.
- `POST /platform/users/bulk-privileges` — bulk update across multiple users.
- Both under the `platform:platform.users.manage` middleware.

### Service (`PlatformUserService`)

- `updatePrivileges()` / `bulkUpdatePrivileges()`:
  - Update each field independently — only supplied fields change.
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

## Consequences

- Platform admins can self-service subscription/privilege corrections; fully
  audited.
- Server-side listing keeps admin tables responsive and enables filtering on
  server-computed fields.
- Email is normalized (lowercase/trimmed) and uniqueness enforced case-insensitively.

## Files

- `app/Services/Platform/PlatformUserService.php`
- `app/Http/Controllers/Api/Platform/PlatformUserController.php`
- `app/Http/Resources/PlatformUserResource.php`
- `app/Http/Middleware/EnsureAccountUsable.php`
- `routes/api/v1/platform.php`, `bootstrap/app.php`

## Related

- `2026-08-05-owner-business-seeder.md` — owner/admin seeder guarantee.
- Frontend ADR `2026-08-05-platform-user-privileges-and-server-pagination.md`.
