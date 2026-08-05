# Account Type ↔ Plan Pairing Guard on Privilege Endpoints

- **Date:** 2026-08-05
- **Status:** Accepted

## Context

Platform admins grant plans via the Privileges endpoints (`PATCH /platform/users/{id}/privileges`, `POST /platform/users/bulk-privileges`). Nothing prevented an `account_type` and `plan_id` from disagreeing (e.g. a `personal` account assigned an `essential` business plan, or a `storefront_buyer` assigned any plan), which produced contradictory account state and a hidden workspace/top bar on the frontend.

## Decision

Add `PlatformUserController::validateAccountPlanPairing()`, called by both single and bulk update after request validation:

- `storefront_buyer` account type + any subscription field → `422` "Storefront buyer accounts cannot have a subscription."
- `account_type` + `plan_id` where `plan.type !== account_type` → `422` "Selected plan is for {type} accounts, not {account_type}."

Plans carry a `type` field (`business` for Essential/Professional/Enterprise, `personal` for Personal, per `PlanSeeder`). The rule mirrors the frontend guard (`docs/adr/2026-08-05-account-type-plan-guard-on-privileges.md` on the FE) so non-UI clients cannot create inconsistent subscriptions.

## Consequences

- Single and bulk endpoints now reject contradictory account-type/plan requests.
- UI can still send a bare `account_type` (no subscription) or a bare `plan_id` (account defaults to `business`) without tripping the guard.
- Guard tests live in `tests/Feature/PlatformPrivilegeAccountTypeTest.php` (new) to keep the main privilege test file ≤ 500 lines (Vera gate).

**Tests:** `PlatformUserPrivilegesTest` (15) + `PlatformPrivilegeAccountTypeTest` (5) all pass. `composer vera:fast` passed (php -l + logic incl. file-size-500).