# ADR-024: Third account type — Shopping (storefront_buyer)

**Date:** 2026-08-01
**Status:** Accepted

## Context

Custosell had two account types: **Business** (POS/inventory/storefront sellers) and **Personal** (freelancers/individuals buying modules à la carte). Online storefront buyers — visitors who just want to browse Discover, add to cart, and place orders — were lumped into the Personal flow. The backend even flattened `storefront_buyer` into `account_type = 'personal'` at registration, and the personal flow auto-creates a workspace + Personal-plan subscription. That meant every shopper silently became a workspace owner with a dashboard, plans, and billing surface they never asked for.

Oscar's requirement: a third, distinct **shopping account** type — Discover & My Orders only, storefront bottom nav hides Dashboard, register page shows it as an option, public-store signup modals create shopping accounts, and the FAQ seeder must document it.

## Decision

- **Canonical type is `storefront_buyer`** (already accepted by `RegisterRequest`), exposed in the API as `account_type: 'storefront_buyer'`. The frontend labels it "Shopping" in UI copy.
- **`UserService::register` preserves `storefront_buyer`** instead of flattening it to `personal`. It stays in the no-business branch: `role_id = null`, `modules = []`, no business record, no subscription, no referral-workspace coupling. Personal still gets its minimal workspace + subscription.
- **`UserResource`:** `active_plans` is `[]` for `storefront_buyer` (shoppers don't buy Custosell plans — they buy from shops). `modules` stays `[]` (matching the no-subscription convention; the FE `getAccessibleModules` seeds `account/guide/discover` client-side, and a new `isStorefrontBuyer()` helper drives the Discover-only experience).
- **Migration `2026_08_01_000100_backfill_storefront_buyer_account_type.php`** restores the distinct type for legacy storefront buyers: `account_type = 'personal' AND business_id IS NULL` → `storefront_buyer`. This predicate is reliable because the personal flow always sets `business_id` in the same transaction.
- **`SendWelcomeEmail`** branches on `storefront_buyer` with shopping-specific intro, showcase, quick-start, and tip copy.
- **FAQ seeder** gains a "For Shopping Accounts" section (What is a Shopping account / does it cost anything / can I upgrade) and the Personal-vs-Business entries now mention Shopping.
- **Frontend:**
  - `AccountTypeSelector` + `RegisterPage` offer a third "For shopping" option (emerald, ShoppingBag) rendered through a new reusable `SimpleAccountForm` (`account_type: 'storefront_buyer'`); RegisterPage stays ≤500 lines.
  - `StorefrontAuthPanel` (public store signup modal) already sent `storefront_buyer` — unchanged.
  - `ConnectedStorefrontStrip` passes `onHome={undefined}` for shopping accounts so the **Dashboard/Home tab is hidden** in the bottom nav (StorefrontActionStrip already renders the tab only when `onHome` is set).
  - `DiscoverAccountMenu` hides the "App home / Account home" menu item for shopping accounts (Wishlist/Orders/Account/Guide remain).
  - `Navbar` brand label shows **"Shopping"** for `storefront_buyer`.
  - `getDefaultRoute` already lands shopping users on `ROUTES.DISCOVER_MY_ORDERS` (no business_id + discover accessible); `useRegister` redirect uses it, so new shopping accounts land on Discover.

## Consequences

- Shopping accounts are visibly distinct (`account_type`), have no dashboard, no plans, no workspace, and no billing surface.
- Legacy storefront buyers are re-classified on migrate; the predicate (`personal + null business_id`) cannot collide with genuine personal accounts.
- Tests: `StorefrontBuyerAccountTypeTest` (new, separate from the >500-line `StorefrontTest`) locks the register contract: `account_type=storefront_buyer`, null `business_id`/`role_id`, `modules=[]`, `active_plans=[]`, login works.
- FE type surface: `RegisterRequest` already permitted `'storefront_buyer'`; no contract change.

## Test results

- Backend: `composer vera:fast` passed (php -l + logic incl. file-size-500); `StorefrontBuyerAccountTypeTest` 2/2; `StorefrontTest` storefront-buyer register test still green.
- Frontend: `npx tsc --noEmit` clean; `npm run vera:fast` passed.
