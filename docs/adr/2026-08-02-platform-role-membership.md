# Platform Role Membership Management

**Date:** 2026-08-02
**Status:** Accepted
**Scope:** Frontend + Backend

## Context

Platform operator roles previously relied on `.env` (`PLATFORM_ADMIN_EMAILS`) to auto-assign
`platform-admin` at login, which is not scalable. Access to platform features is now driven by
modules (`EnsureModuleAccess` / `ModuleAccessService`), so fine-grained role *permissions* no
longer gate routes. The Roles UI still exposed a permission-checkbox editor that implied
permission-based access.

## Decision

- **Role form is name-only.** The permission editor was removed from `PlatformRoleFormModal`.
  Creating/renaming a role no longer configures Spatie permissions (still accepted optionally
  by the API for backwards compatibility).
- **Role membership is managed on the Roles page.** Each role row shows a member count and a
  "manage members" action that opens `PlatformRoleMembersModal`:
  - Lists current members (searchable) with per-member remove.
  - Adds members by searching users by name/email.
  - Add/remove reuse the existing `POST /platform/users/bulk-assign-roles` endpoint
    (`action: assign|revoke`), preserving the last-platform-admin guard in
    `PlatformUserService::revokePlatformRole`.
- **New API surface:**
  - `GET /platform/roles` now returns `users_count` per role.
  - `GET /platform/roles/{id}/members?search=&per_page=` returns role members
    (`PlatformUserResource`), guarded by `platform.roles.manage`.
  - `PUT /platform/roles/{id}` accepts `name` to rename custom roles
    (`platform-admin` still locked).
- **Edit correctness fix:** the role form modal is keyed by `role.id` so switching targets
  resets form state (previously stale between edits).

## Trade-offs

- Users without a `business_id` won't surface in the add-member search because candidate
  search reuses `GET /platform/users` (tenant-scoped). Platform operators are business owners
  today, so this is acceptable.
- Built-in roles (`platform-admin`, `platform-analyst`, `platform-support`) still cannot be
  renamed or deleted, but their membership can be managed.
