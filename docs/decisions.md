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
