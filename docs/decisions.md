# Custosell — Architecture Decision Records

## ADR-001: Offline-First Desktop Architecture

**Date:** 2026-06-02  
**Status:** Accepted  

**Context:** POS system for Uganda where internet is unreliable. Users need to continue selling even when offline.

**Decision:** Desktop app (Electron + React + SQLite) is the primary source of truth. The Laravel cloud API is a backup/sync hub. All features work offline. Auto-sync happens on reconnect and periodically in the background.

**Consequences:**
- No dependency on internet for daily POS operations
- Sync queue in local SQLite tracks pending changes
- Cloud API acts as auth server + sync hub, not real-time backend

---

## ADR-002: Business-Scoped Multi-Tenancy

**Date:** 2026-06-02  
**Status:** Accepted  

**Context:** Each business is a tenant. Staff belong to one business and should never see data from other businesses.

**Decision:** Every entity carries `business_id` FK. All queries are scoped by the authenticated user's `business_id`. No separate database per tenant (simpler for MVP).

**Consequences:**
- Simpler deployment (single MySQL database)
- `business_id` index on every scoped table
- Middleware/controller always filters by `auth()->user()->business_id`

---

## ADR-003: JSON Permissions on Roles

**Date:** 2026-06-02  
**Status:** Accepted  

**Context:** Need role-based access control scoped to each business without complexity of a full RBAC package.

**Decision:** Roles store permissions as a JSON column with boolean flags for each permission. Two default roles seeded per business: Admin (all true) and Staff (limited).

**Consequences:**
- No separate permissions table
- Easy to seed and customise per business
- Checked via `$user->role->permissions['sales.create'] ?? false`

---

## ADR-004: Plan-Based Feature Restriction

**Date:** 2026-06-02  
**Status:** Accepted  

**Context:** Future monetisation requires restricting features per plan tier.

**Decision:** Plans table stores features and limits as JSON columns. Middleware checks `$business->canFeature('expenses')` on route groups. Limits checked at creation/update time.

**Consequences:**
- Three seeded tiers: Free (UGX 0), Pro (UGX 30,000), Premium (UGX 100,000)
- Feature gates already in place, no controller refactoring when paid plans launch
- `null` limit = unlimited

---

## ADR-005: Receipt Snapshots on SaleItems

**Date:** 2026-06-02  
**Status:** Accepted  

**Context:** Receipts must be reproducible even if product names or prices change later.

**Decision:** SaleItems store `product_name` and `product_price` as snapshots at time of sale, frozen permanently.

**Consequences:**
- Receipts survive product edits and deletions
- Slight data duplication but guarantees accurate historical records

---

## ADR-006: SOLID Repository + Service Pattern

**Date:** 2026-06-02  
**Status:** Accepted  

**Context:** Need maintainable, testable code that follows Laravel best practices.

**Decision:** Every entity has: Migration → Model → RepositoryInterface → Repository → ServiceInterface → Service → Request → Resource → Collection → Controller → Routes → ServiceProvider. All bindings in `bootstrap/providers.php`.

**Consequences:**
- 168 files generated across 14 entities
- Clear separation of concerns (Controller = HTTP, Service = business logic, Repository = data access)
- Provider bindings make testing/swapping implementations trivial

---

## ADR-007: B2B Inventory & Supply Chain (online-only)

**Date:** 2026-07-11  
**Status:** Accepted  

**Context:** Businesses need to buy stock from other tenants. POS Orders already mean held carts.

**Decision:** Opt-in marketplace (`is_open_for_supply`, `listed_for_supply`) and purchase-order lifecycle under `module:inventory`. Online-only (no sync queue). Fulfill stocks out seller; receive requires buyer product mapping then stocks in. Payments off-platform in v1.

**API:** `marketplace.php`, `purchase_orders.php`, `PATCH /businesses/supply-profile`, `PATCH /products/{id}/supply-listing`.

**Tests:** `tests/Feature/SupplyChainTest.php`

**Frontend ADR:** `Frontend/docs/adr/2026-07-11-inventory-supply-chain-b2b.md`

---

## ADR-008: URA EFRIS fiscalization (v1)

**Date:** 2026-07-12  
**Status:** Accepted (config + procedures; API client follows)  

**Context:** Ugandan VAT businesses need EFRIS e-receipts/e-invoices without breaking offline POS or forcing every country onto URA.

**Decision:**
- Uganda-first (`EFRIS_COUNTRY=UG`); country-configurable later
- Direct URA **API** only in v1 (no hardware EFD)
- Fiscalize **both** POS sales and sales invoices
- Offline: **sync later** (never block checkout waiting for URA)
- Master switch `EFRIS_ENABLED` (default `false`) gates all EFRIS behaviour
- Credentials in `.env`; procedures in Frontend `docs/compliance/efris-setup.md`

**Config:** `config/efris.php` · **Env template:** `.env.example` (EFRIS section)

**Frontend ADR:** `Frontend/docs/adr/2026-07-12-efris-fiscalization.md`

---

## ADR-009: Storefront buyer accounts → seller customers

**Date:** 2026-07-12  
**Status:** Accepted  

**Context:** Discover shoppers need accounts without creating a business. Storefront orders tracked `storefront_buyer_user_id` but never created a seller `Customer`.

**Decision:**
- `POST /auth/register` with `account_type=storefront_buyer` → User `business_id=null`, `modules=[]`, no Shift
- Login/register skip Shift when `business_id` is null
- `customers.user_id` FK + unique `(business_id, user_id)`
- `CustomerContactService::attachStorefrontBuyer` on storefront place-order sets `order.customer_id`

**Tests:** `StorefrontTest` (buyer register + customer attach)

**Frontend ADR:** `Frontend/docs/adr/2026-07-12-storefront-buyer-customer-accounts.md`

---

## ADR-010: B2C storefront buyer receipts & invoices

**Date:** 2026-07-12  
**Status:** Accepted  

**Context:** Discover buyers need sale receipts / invoices after shops fulfill storefront orders, without business-scoped `/sales` or `/invoices` access.

**Decision:**
- Enrich `GET /storefront/my-orders` with `sale_id` / `invoice_id` / receipt fields
- `GET /storefront/my-orders/{id}/sale` and `.../invoice` authorized by `storefront_buyer_user_id`
- FE reuses `ReceiptPreviewModal` + `ViewInvoiceModal` (`role=storefront_buyer`)

**Frontend ADR:** `Frontend/docs/adr/2026-07-12-storefront-buyer-receipts-invoices.md`

---

## ADR-011: Storefront buyer phone reuse + My Orders items

**Date:** 2026-07-12  
**Status:** Accepted  

**Context:** Buyers retyped phone on every reorder; My Orders lacked a line-item preview before fulfillment.

**Decision:**
- On place-order, update buyer `User.phone` when a non-empty phone is submitted
- `buyerOrderPayload` includes `items[]`, `customer_name`, `customer_phone`
- FE persists last contact in localStorage and shows Eye → order items modal on My Orders

**Frontend ADR:** `Frontend/docs/adr/2026-07-12-storefront-buyer-phone-and-order-eye.md`

---

## ADR-012: Storefront polish gaps

**Date:** 2026-07-12  
**Status:** Accepted  

**Context:** Discover needed category filters, stock signals, buyer cancel/delete, delivery address, and buyer notify on fulfill.

**Decision:**
- Buyer cancel/delete on my-orders; stock fields + place-order stock check; delivery_address/city on orders
- Notify buyer (email + in-app) when storefront order completed/invoiced
- FE: categories, Online filter, product detail, self-hosted QR, Public shop logo

**Frontend ADR:** `Frontend/docs/adr/2026-07-12-storefront-polish-gaps.md`

---

## ADR-013: Tabbed subscription settings with decision matrix

**Date:** 2026-07-23  
**Status:** Accepted  

**Context:** The subscription settings page showed a dead-end "No active subscription" when the user had no subscription record, and rendered everything in a single scroll. Users needed a clearer experience with plan selection, payment history, and change history in separate tabs, plus context-aware action buttons (Subscribe / Upgrade / Downgrade Now / Schedule Downgrade).

**Decision:**
- Three-tab layout: **Plans**, **Payments**, **History**
- Plans tab reuses the existing plan card visual style with a decision matrix:
  - No subscription → **Subscribe** (navigates to `/onboarding`)
  - Current plan → **Current Plan** badge
  - Higher sort_order → **Upgrade** (immediate, POST `/subscriptions/{id}/upgrade`)
  - Lower sort_order → **Downgrade** → inline choice: **Downgrade Now** (immediate) / **Schedule Downgrade** (end of period)
- Backend: downgrade endpoint accepts optional `effective` param (`immediate`|`end_of_period`), matching the upgrade endpoint pattern
- Backend: new `GET /subscriptions/{id}/changes` endpoint returns scheduled change history
- Frontend: `useUpgrade`, `useDowngrade`, `useSubscriptionChanges` hooks; `DOWNGRADE(id)` and `CHANGES(id)` endpoint constants

**API:**
- `POST /subscriptions/{id}/downgrade { to_plan_id, effective? }`
- `GET /subscriptions/{id}/changes`

**Consequences:**
- Single scroll page replaced with focused tabbed layout (457 lines, under 500 limit)
- Consistent UX: same card styling as PlanCards but with context-aware action buttons
- Downgrade now offers both immediate and end-of-period options
- Payment history and change history moved to their own tabs, reducing cognitive load

---

## ADR-014: Referral reward = % of amount actually paid (10% off / 15% reward)

**Date:** 2026-07-31  
**Status:** Accepted  

**Context:** The default referral program was referee 10% off / referrer 20% reward, both on the undiscounted base. The reward could exceed what the platform actually collected (e.g., free-month codes), and cost more per acquisition than the discount it drove.

**Decision:**
- Default program stays **referee 10% off**; referrer reward default drops **20% → 15%**
- Reward (and sales-rep commission) is a % of the **amount actually paid** = `max(0, base − discount_applied)`, not the undiscounted base
- Flat-amount rewards/commissions unchanged; free-month codes yield $0 referrer reward (the cap working as designed)
- Platform net per referral: 90% collected − 13.5% rewarded ≈ **76.5%**
- Full option analysis (3 options + revisit triggers) in `docs/adr/2026-07-31-referral-reward-economics.md`

**Consequences:**
- `ReferralService::markActive()` now computes `$paidBase` and applies it to PERCENTAGE/FREE_MONTH rewards and PERCENTAGE commissions
- Defaults updated in `UserService`, `BusinessService`, `SimulateCreditDeduction`
- Referrer incentive slightly reduced (20% → ~13.5% of base) but still exceeds the referee's 10% saving

---

## ADR-015: Welcome email on account creation

**Date:** 2026-08-01
**Status:** Accepted

**Context:** New users got no email after registering. The platform already had a standard transactional email (`emails.standard` + `StandardEmail` mailable) used for password resets, dormant-account warnings, and notification digests, but nothing fired on account creation.

**Decision:**
- `UserRegistered` domain event carrying `User` + optional `Business`, dispatched from `UserService::register` and `BusinessService::register` (the latter after the transaction commits)
- Synchronous `SendWelcomeEmail` listener sends the existing `StandardEmail` mailable — brand name, logo, personalised greeting, feature list, "Get Started" CTA to `FRONTEND_URL`, offline-first pro tip
- Email failures are caught and logged; they never fail or roll back registration
- Fixed `StandardEmail::content()` passing `logoPath` where the view reads `logoUrl`, so the header logo now renders

**Tests:** `tests/Feature/SendWelcomeEmailTest.php` (with/without business, `Mail::assertSent`)

**Full detail:** `docs/adr/2026-08-01-account-welcome-email.md`

---

## ADR-016: New products default to listed; bulk list/unlist endpoint

**Date:** 2026-08-01
**Status:** Accepted

**Context:** Products created or imported were unlisted by default on both the B2B supply marketplace (`listed_for_supply`) and the public storefront (`listed_for_storefront`). New inventory therefore never appeared on either shop until manually listed, and listing could only be toggled one product at a time.

**Decision:**
- **Default on** for new/imported products: migration sets both flags to `true` (with `change()`); `ProductService::create` sets `(bool)($data[$flag] ?? true)`; `ProductImportService` sets both flags `true` per row. Existing products keep current state (opt-in via bulk List).
- **Bulk list/unlist**: `ProductService::bulkUpdateListing($ids, $businessId, $channel, $listed)` — business-scoped, channel `supply|storefront`. Supply list auto-fills `supply_price` from `wholesale_price ?? unit_price` (when null) and `supply_min_qty` to `1`; sets/clears `listed_at` / `storefront_listed_at`. Returns updated count.
- `POST /products/bulk-listing` → `{ updated: int }`, validated by `ProductBulkListingRequest` (ids 1..5000, channel enum, listed boolean).
- Listing mutations remain online-only (no offline queueing).

**Tests:** `tests/Feature/ProductListingTest.php` — 6 tests, 31 assertions.

**Full detail:** `docs/adr/2026-08-01-default-listed-products-bulk-listing.md`
