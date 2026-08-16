# Linked Accounts (Account Switching) - Implementation Plan

**Status:** PLAN - awaiting Oscar's approval before implementation.
**Stack:** Backend (Laravel) + Frontend (React/Redux, offline-first Electron + web).

---

## 1. Problem & goal

Today a user is bound to **one active account** at a time (`users.business_id` +
`account_type`). A user who owns several businesses, or has a personal + business
account, must log out and log back in to switch - there is no "linked accounts"
concept.

**Goal:** let a user link several of their own accounts under one login, pick one
as the **primary/default**, switch between them from a profile dropdown, and
unlink any secondary account - with each account's **exact access rights
(role permissions, modules, locations, subscription, data scope)** intact.

---

## 2. Concept (the "linked accounts" model)

- **Linked account** = another account the same person owns, added by
  authenticating its credentials once.
- **Primary account** = the account used to link the others (or explicitly set).
  It is the default session on next login and the anchor for the switch list.
- **Secondary accounts** = the rest of the linked accounts.
- Switching = re-fetch the auth payload for the chosen account and swap the
  active session (same shape as `/auth/me` → what the auth slice already holds).

> Wording suggestion (optional, cleaner than "linked accounts"):
> **"Accounts"** with a **"Default"** badge, and actions
> **"Add account"**, **"Switch to…"**, **"Remove"**.

---

## 3. Backend

### 3.1 New table: `linked_accounts`

| column | type | notes |
|---|---|---|
| `id` | bigint PK | |
| `owner_user_id` | FK → users | the user who linked the account |
| `linked_user_id` | FK → users | the account being linked (the switch target) |
| `relation` | enum `'primary' \| 'secondary'` | primary = default account |
| `created_at` / `updated_at` | timestamps | |
| unique | `(owner_user_id, linked_user_id)` | prevent double-linking |
| index | `owner_user_id` | list lookup |

**Notes**
- `owner_user_id` and `linked_user_id` can point at the same record for a
  user's own account only when they are their own primary (see seed/self rule).
- Linking is **one-way per pair** (A links B). If B also links A, that is a
  separate row - acceptable; the switch list is per-owner.
- Soft-delete not needed (unlink = hard delete row).

### 3.2 Migration
- New migration `2026_08_16_000000_create_linked_accounts_table.php`.
- Additive only; no `--fresh`/`refresh` ever.

### 3.3 Service: `LinkedAccountService`
Methods:
- `link(int $ownerUserId, string $email, string $password): array` -
  authenticates `$email`/`$password` against a **real user account**; on success
  creates the `linked_accounts` row (first link becomes `primary`).
- `listFor(int $ownerUserId): array` - owner's accounts with per-account:
  user id, name, email, avatar, business name/slug/logo, account_type,
  subscription status, role slug/name, is_business_owner, is_platform_admin,
  location, and whether it's primary.
- `switchTo(int $ownerUserId, int $linkedUserId): array` - builds the **full
  auth payload** for the target account (reusing `UserResource` shape) so the
  frontend auth slice can hydrate exactly like `/auth/me`.
- `unlink(int $ownerUserId, int $linkedUserId): void` - removes a secondary
  account; primary cannot be unlinked (must set another primary first, or the
  row is kept as the owner's own record).
- `setPrimary(int $ownerUserId, int $linkedUserId): void` - demote current
  primary → secondary, promote chosen → primary.

### 3.4 Controller + routes (`routes/api/v1/linked_accounts.php`)
| method | endpoint | body | returns |
|---|---|---|---|
| GET | `/linked-accounts` | - | list for the authenticated owner |
| POST | `/linked-accounts` | `{ email, password }` | created link + updated list |
| POST | `/linked-accounts/{id}/switch` | - | full auth payload for target account |
| POST | `/linked-accounts/{id}/set-primary` | - | updated list |
| DELETE | `/linked-accounts/{id}` | - | 204 + updated list |

Wired into `routes/api.php` inside the auth middleware group. Controller uses
`LinkedAccountService`, returns 422 with a clear message when credentials are
invalid.

### 3.5 Security guardrails
- **Never store or return passwords.** Credentials are used once at link time,
  discarded.
- **Authorization:** every action checks `owner_user_id === auth()->id()`.
- **Cross-account switching must not leak tokens:** switching returns an auth
  payload but does **not** mint a new bearer token for the target account on
  the device; the client keeps its own session token and only swaps the cached
  user context + re-scopes subsequent requests. (Alternative: issue a scoped
  token - decide at implementation; default = no new token, reuse session.)
- **Rate-limit** the link endpoint (credentials brute force) - reuse existing
  login throttle if available.
- **Platform admins:** switching respects `is_platform_admin` on the target, and
  platform admins are not linkable as secondary from a normal account unless
  they are already part of the owner's business (avoid privilege escalation).

### 3.6 Backend tests (`LinkedAccountTest`)
- link success → row + primary default
- link with wrong password → 422, no row
- link same account twice → idempotent/409
- list scoped to owner only
- switch returns full auth payload (user, business, subscription, role, modules)
- unlink secondary → gone; unlink primary → blocked
- set-primary demotes/promotes
- non-owner cannot switch/unlink someone else's link

---

## 4. Frontend

### 4.1 API (`linkedAccountQueries.ts` + endpoints)
- `useLinkedAccounts()` - GET list.
- `useLinkAccount()` - POST; on success refresh list.
- `useSwitchAccount()` - POST switch; on success **hydrate the auth slice** with
  the returned payload (see 4.3) then clear/normalize dependent caches.
- `useSetPrimary()` / `useUnlinkAccount()`.

### 4.2 Profile dropdown - "Add account" + switcher
- Add an **Accounts** entry in the profile dropdown (where logout lives).
- **Add account** opens a modal: email + password, submit → `useLinkAccount`.
  Validation errors shown in-modal (uses the same error-toast/message pattern).
- Dropdown lists all linked accounts with:
  - avatar/initial, name, email
  - business name + `is_business_owner` / role badge
  - **Default** badge on primary
  - actions per row: **Switch** (click the row), **Make default**, **Remove**
- Removing shows a confirm; the primary cannot be removed (or prompts to pick a
  new default first).

### 4.3 Switching - hydrating the auth slice
- `switchAccount` action in `authSlice` mirrors `loginSuccess`/`hydrateAuth`:
  sets `user`, `businessId`, `activeLocationId`, `plans`, `isAuthenticated`,
  `isLocalSession`, `pendingAuthSync` from the returned payload.
- On switch success:
  - `persistAuthSnapshot()` (reuse existing) so the switched account survives
    reload and offline.
  - Invalidate React Query caches that are business-scoped
    (`['accounting']`, sales, inventory, staff, etc.) - reuse the existing cache
    reset helper used on login/logout.
- **Offline guard:** switching requires connectivity (it re-fetches the auth
  payload). The dropdown is marked online-only like other online-only surfaces.

### 4.4 Offline implications (important)
- The device keeps **one** active session/token (unchanged). Switching swaps the
  cached `AuthUser`/context - it does not create parallel offline stores.
- Offline data (IndexedDB) is keyed by business; on switch, offline stores are
  reset/normalized for the new business (reuse existing logout/clear path) so a
  user never sees another account's offline data.
- `persistAuthSnapshot` writes the switched account so cold-start restores the
  last active account.

### 4.5 Frontend tests
- linked-account query hooks return/hydrate correctly.
- switch hydrates auth slice (reducer unit test).
- dropdown renders primary/secondary with badges and unlink disabled on primary.
- cache invalidation fires on switch.

---

## 5. Scope boundaries (what we will NOT build now)

- No re-login/multi-token per account - one device session, one active account.
- No invitation-based account linking (only self-owned credentials).
- No cross-account notifications/inbox.
- No merging of data between accounts.

---

## 6. Rollout / deploy guardrails (per DEPLOYMENT.md)

- Backend: additive migration only, `migrate --force` on staging + prod.
- Frontend: build → `Backend/public/<target>`, backup before wipe, `cp -rT`,
  verify asset count + MIME + no missing refs.
- Approval gate: this plan is approved by Oscar before implementation; each
  deploy follows the runbook.

---

## 7. Open questions for Oscar (approval notes)

> **APPROVED 2026-08-16.** Decisions:
> 1. **Token model:** one device session token; switching swaps cached user context only.
> 2. **Primary removal:** blocked until another account is set as default.
> 3. **Wording:** "Linked Accounts" with "Primary" / "Secondary" labels.
> 4. **Placement:** profile dropdown in the top navbar.
> 5. **Offline switching:** always online-first (switching requires connectivity).

*Prepared by Mike. Awaiting approval before any code is written.*
