# Email Verification, 2FA & Account Activity Audit Log - backend

## Status

Adopted 2026-08-04.

## Context

Sign-ins only required an email + password. There was no way to:

1. Gate login on email ownership when the product wanted verification.
2. Add a second factor beyond the password.
3. Give users a record of what happened to their account (sign-ins, sign-outs,
   password changes, verification events).

## Decision

### Configuration-gated email verification

- `config/auth.php` gains a `verification` block:
  - `verification.required` → `(bool) env('REQUIRE_EMAIL_VERIFICATION', false)`
  - `verification.code_ttl_minutes` → `(int) env('VERIFICATION_CODE_TTL_MINUTES', 10)`
  - `verification.code_digits` → `6`
- The check is **login-time only** and purely config-driven. When the flag is off, or
  the account's `email_verified_at` is already set, login proceeds exactly as before.
  No middleware, route guard, or request handler blocks an already-verified user, so
  an existing (verified) session is never disturbed.

### Email-based 2FA

- `users.two_factor_enabled` boolean (default `false`), exposed in `UserResource`.
- Both verification and 2FA use a single 6-digit email code mechanism
  (`AccountVerificationService`), avoiding a TOTP/authenticator flow.

### Auth challenge flow

- `AuthController::login`:
  1. Invalid creds → 401.
  2. Inactive → 403.
  3. If `verification.required` and `email_verified_at` null → issue an
     `email_verification` code, return 403 `{ requires_email_verification, email }`.
  4. Else if `two_factor_enabled` → issue a `two_factor` code, return 403
     `{ requires_two_factor, email }`.
  5. Otherwise → existing success payload via the extracted `authResponse()` helper.
- `POST /auth/verify` (`AuthController::verify`):
  - `email_verification` purpose only accepted when the email is not yet verified;
    verifying marks `email_verified_at`, then if 2FA is enabled it **chains** into a
    2FA challenge instead of logging in directly.
  - `two_factor` purpose only accepted when `two_factor_enabled` is true; verifying
    completes the login.
  - Invalid/expired code → 422.
- `POST /auth/verify/send` resends a code; `email_verification` resend is rejected for
  already-verified emails.

### Audit log

- `account_audit_logs` table: `user_id`, `action`, `context` (json), `ip_address`,
  `user_agent`, timestamps.
- `AccountAuditLogService::log()` records `login`, `logout`, `email_verified`,
  `two_factor_challenge`, `two_factor_passed`, `two_factor_enabled`,
  `two_factor_disabled`, `password_changed`. Logouts are audited, not just logins.
- `GET /auth/activity` returns the newest-first feed (`up to 200`).

### Endpoints

| Method | Endpoint | Purpose |
|--------|----------|---------|
| POST | `/auth/verify/send` | Send a verification/2FA code |
| POST | `/auth/verify` | Verify a code; completes login (+ chains into 2FA) |
| POST | `/auth/two-factor` | Toggle 2FA (authenticated) |
| GET | `/auth/activity` | Account activity feed (authenticated) |

Password changes made via the existing `PUT /auth/profile` are now audited as
`password_changed`.

## Failure states

- Invalid or expired code → 422 actionable message.
- `two_factor` verify on an account without 2FA → 422.
- `email_verification` verify/send on an already-verified email → 422.
- Login when verification is required but unverified → 403 challenge (no token issued).

## Verification

- `AccountSecurityTest` (13 tests): login gating for verification + 2FA, verify
  completes login, invalid code, email-verify chaining into 2FA, 2FA rejected when
  disabled, resend rejected when verified, toggle + audit, activity feed, logout audited,
  password change audited.
- `AuthTest` (10 tests) still passes - existing login/register flows unchanged.
- `composer vera:fast`: php -l (all changed files) + logic - passed. `migrate --pretend`
  clean for the three new migrations.

## Related files

- `config/auth.php`, `.env.example`
- `app/Http/Controllers/Api/AuthController.php`
- `app/Http/Controllers/Api/AccountSecurityController.php`
- `app/Http/Controllers/Api/UserController.php` (password audit)
- `app/Services/AccountVerificationService.php` + interface
- `app/Services/AccountAuditLogService.php` + interface
- `app/Http/Requests/SendVerificationCodeRequest.php`, `VerifyCodeRequest.php`,
  `ToggleTwoFactorRequest.php`
- `app/Models/AccountVerificationCode.php`, `app/Models/AccountAuditLog.php`
- `app/Http/Resources/UserResource.php`
- `routes/api/v1/users.php`, `bootstrap/providers.php`
- migrations `2026_08_04_*` (two_factor_enabled, verification codes, audit logs)
- `tests/Feature/AccountSecurityTest.php`