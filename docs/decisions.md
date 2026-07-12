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
