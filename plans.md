# Custosell Backend — Execution Plan

**Project:** Custosell (Lightweight POS)  
**Stack:** Laravel 12 + MySQL (cloud) / SQLite (desktop local)  
**Location:** `C:/Dev/CustoSell/Backend`  
**Date:** 2026-06-02

---

## Phase 0: Project Scaffolding

### Task 0.1 — Create Laravel Project
```bash
composer create-project laravel/laravel Backend
```

Configure `.env`:
```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=custosell
DB_USERNAME=root
DB_PASSWORD=

SANCTUM_STATEFUL_DOMAINS=localhost
SESSION_DRIVER=cookie
```

### Task 0.2 — Install Dependencies (from Custocare)

**Production:**
| Package | Version | Purpose |
|---------|---------|---------|
| `laravel/framework` | ^12.0 | Core framework |
| `laravel/sanctum` | ^4.0 | API token auth (SPA + tokens) |
| `laravel/tinker` | ^2.10.1 | Artisan REPL |
| `spatie/laravel-permission` | ^6.24 | Role/permission management (optional — may use JSON on Role instead) |
| `barryvdh/laravel-dompdf` | ^3.1 | PDF receipt generation |
| `paragonie/constant_time_encoding` | ^3.1 | Constant-time encoding (security) |
| `pragmarx/google2fa` | ^9.0 | 2FA support |
| `php` | ^8.2 | |

**Dev:**
| Package | Version |
|---------|---------|
| `fakerphp/faker` | ^1.23 |
| `laravel/pail` | ^1.2.2 |
| `laravel/pint` | ^1.24 |
| `laravel/sail` | ^1.41 |
| `mockery/mockery` | ^1.6 |
| `nunomaduro/collision` | ^8.6 |
| `phpunit/phpunit` | ^11.5.3 |

### Task 0.3 — Set Up Project Structure
- Remove default migrations (keep users, cache, jobs)
- Create directory structure:
  ```
  app/
    Repositories/
      Contracts/
      Eloquent/
    Services/
      Contracts/
    Http/
      Controllers/Api/
      Requests/
      Resources/
  routes/
    api/
      v1/
  ```
- Set up `bootstrap/providers.php` for Service Provider registration
- Configure CORS for Electron app

### Task 0.4 — Create Vera Scripts
Copy from Custocare:
- `scripts/vera-fast.php` — `php -l` on changed PHP files
- `scripts/vera-extended.php` — vera-fast + migrate --pretend + filtered PHPUnit

Add to `composer.json`:
```json
"scripts": {
    "vera:fast": "@php scripts/vera-fast.php",
    "vera:extended": "@php scripts/vera-extended.php",
    ...
}
```

---

## Phase 1: Foundation Entities

Each entity follows: **Sage → Blue → Rex → Vera → Quill**
All entities produce 12 files: migration, model, repository interface, repository, service interface, service, request, resource, collection, controller, routes, provider.

---

### Order 1: Plan

**Table:** `plans`
**Purpose:** Define subscription tiers. Seeded at setup.

| Column | Type | Modifiers | Notes |
|--------|------|-----------|-------|
| id | increments | PK | |
| name | string(100) | required | e.g. "Free", "Pro", "Premium" |
| slug | string(100) | unique, required | e.g. "free", "pro", "premium" |
| description | text | nullable | Marketing copy |
| price_monthly | decimal(10,2) | default 0 | UGX |
| price_yearly | decimal(10,2) | nullable | Discounted yearly rate |
| features | json | required | Feature flags JSON |
| limits | json | required | Numerical limits JSON |
| is_active | boolean | default true | |
| sort_order | integer | default 0 | Display order |
| timestamps | | nullable | created_at, updated_at |

**Seed data:** Free (UGX 0), Pro (UGX 30,000), Premium (UGX 100,000)
**Endpoints:** CRUD (admin only)
**Dependencies:** None

---

### Order 2: User

**Table:** `users`
**Purpose:** Staff/users of a business. Password-based auth. Business owner can add/remove staff.

| Column | Type | Modifiers | Notes |
|--------|------|-----------|-------|
| id | bigIncrements | PK | |
| business_id | bigInteger | FK→businesses.id, nullable | Null for system-level |
| role_id | bigInteger | FK→roles.id, nullable | Business-scoped role |
| name | string(255) | required | |
| email | string(255) | unique, required | Login credential |
| password | string(255) | required | Hashed |
| is_active | boolean | default true | Owner can deactivate |
| phone | string(50) | nullable | |
| created_by | bigInteger | FK→users.id, nullable | Who added this user |
| email_verified_at | timestamp | nullable | |
| remember_token | string(100) | nullable | |
| timestamps | | nullable | |
| soft_deletes | | nullable | deleted_at |

**Indexes:** unique on `email`, index on `business_id`, index on `role_id`, index on `created_by`
**Important:** All FKs nullable — circular dependency User↔Business handled at app level
**Endpoints:** Register, Login (Sanctum), Profile, List (business-scoped), Delete (owner only)
**Dependencies:** Laravel default auth migration

---

### Order 3: Role

**Table:** `roles`
**Purpose:** Business-scoped roles with JSON-based permissions.

| Column | Type | Modifiers | Notes |
|--------|------|-----------|-------|
| id | bigIncrements | PK | |
| business_id | bigInteger | FK→businesses.id, nullable | Null for system defaults |
| name | string(100) | required | e.g. "Admin", "Staff" |
| slug | string(100) | required | e.g. "admin", "staff" |
| description | text | nullable | |
| permissions | json | required | JSON permission flags |
| is_default | boolean | default false | Auto-assign to new users? |
| timestamps | | nullable | |

**Indexes:** unique on `(business_id, slug)`
**Seed per business:** Admin (all permissions true), Staff (limited)
**Endpoints:** CRUD (business owner only)
**Dependencies:** businesses

---

### Order 4: Business

**Table:** `businesses`
**Purpose:** Each tenant/business that uses Custosell.

| Column | Type | Modifiers | Notes |
|--------|------|-----------|-------|
| id | bigIncrements | PK | |
| owner_id | bigInteger | FK→users.id, required | The user who owns this business |
| name | string(255) | required | |
| slug | string(255) | unique, required | URL-friendly |
| email | string(255) | nullable | Contact email |
| phone | string(50) | nullable | Contact phone |
| address | text | nullable | |
| currency | string(10) | default 'UGX' | Ugandan Shillings |
| receipt_footer | text | nullable | Custom receipt message |
| logo_path | string(255) | nullable | |
| status | enum('active','suspended') | default 'active' | |
| trial_ends_at | datetime | nullable | |
| timestamps | | nullable | |
| soft_deletes | | nullable | deleted_at |

**Indexes:** unique on `slug`, index on `owner_id`
**Endpoints:** Register, Show, Update (owner only), Settings
**Dependencies:** users

---

### Order 5: Category

**Table:** `categories`
**Purpose:** Group products.

| Column | Type | Modifiers | Notes |
|--------|------|-----------|-------|
| id | bigIncrements | PK | |
| business_id | bigInteger | FK→businesses.id, required | |
| name | string(255) | required | |
| description | text | nullable | |
| sort_order | integer | default 0 | |
| timestamps | | nullable | |

**Indexes:** unique on `(business_id, name)`, index on `business_id`
**Endpoints:** CRUD (scoped to business)
**Dependencies:** businesses

---

## Phase 2: Inventory & Customers

### Order 6: Product

**Table:** `products`
**Purpose:** Inventory items for sale.

| Column | Type | Modifiers | Notes |
|--------|------|-----------|-------|
| id | bigIncrements | PK | |
| business_id | bigInteger | FK→businesses.id, required | |
| category_id | bigInteger | FK→categories.id, nullable | |
| name | string(255) | required | |
| description | text | nullable | |
| sku | string(100) | nullable | Stock keeping unit |
| barcode | string(100) | nullable | Scanner barcode |
| unit_price | decimal(10,2) | required, >= 0 | Selling price (UGX) |
| cost_price | decimal(10,2) | nullable, >= 0 | Purchase cost |
| stock_quantity | integer | default 0, >= 0 | Live stock count |
| low_stock_threshold | integer | default 5 | Alert threshold |
| tax_percentage | decimal(5,2) | default 0 | |
| is_active | boolean | default true | |
| timestamps | | nullable | |
| soft_deletes | | nullable | deleted_at |

**Indexes:** unique on `(business_id, sku)` (null allowed), index on `business_id`, index on `category_id`
**Endpoints:** CRUD, Low Stock Alert, Stock Count
**Dependencies:** businesses, categories

---

### Order 7: Customer

**Table:** `customers`
**Purpose:** Buyer tracking with purchase history.

| Column | Type | Modifiers | Notes |
|--------|------|-----------|-------|
| id | bigIncrements | PK | |
| business_id | bigInteger | FK→businesses.id, required | |
| name | string(255) | required | |
| phone | string(50) | required | |
| email | string(255) | nullable | |
| total_purchases | decimal(12,2) | default 0 | Lifetime spend (UGX) |
| last_purchase_at | timestamp | nullable | |
| timestamps | | nullable | |

**Indexes:** unique on `(business_id, phone)`, index on `business_id`
**Endpoints:** CRUD, Purchase History
**Dependencies:** businesses

---

## Phase 3: Sales & Shift Tracking

### Order 8: Shift

**Table:** `shifts`
**Purpose:** Track user work sessions — clock in/out.

| Column | Type | Modifiers | Notes |
|--------|------|-----------|-------|
| id | bigIncrements | PK | |
| business_id | bigInteger | FK→businesses.id, required | |
| user_id | bigInteger | FK→users.id, required | Which staff |
| clock_in | datetime | required | |
| clock_out | datetime | nullable | |
| total_sales | decimal(12,2) | default 0 | Cached sum |
| total_cash | decimal(12,2) | default 0 | |
| total_mobile_money | decimal(12,2) | default 0 | |
| total_card | decimal(12,2) | default 0 | |
| status | enum('active','completed') | default 'active' | |
| notes | text | nullable | |
| timestamps | | nullable | |

**Indexes:** index on `business_id`, index on `user_id`, index on `(business_id, user_id, status)`, index on `clock_in`
**Endpoints:** Clock In, Clock Out, Active Shift, Shift History
**Dependencies:** businesses, users

---

### Order 9: Sale

**Table:** `sales`
**Purpose:** Transaction header — one sale = one receipt.

| Column | Type | Modifiers | Notes |
|--------|------|-----------|-------|
| id | bigIncrements | PK | |
| business_id | bigInteger | FK→businesses.id, required | |
| user_id | bigInteger | FK→users.id, required | Who processed |
| customer_id | bigInteger | FK→customers.id, nullable | Walk-in if null |
| shift_id | bigInteger | FK→shifts.id, nullable | |
| receipt_number | string(50) | required | Auto-generated |
| subtotal | decimal(12,2) | required | |
| tax_total | decimal(12,2) | default 0 | |
| discount_amount | decimal(12,2) | default 0 | |
| total_amount | decimal(12,2) | required | subtotal + tax - discount |
| payment_method | enum('cash','mobile_money','card','other') | required | |
| payment_status | enum('paid','partially_refunded','refunded') | default 'paid' | |
| notes | text | nullable | |
| sale_date | datetime | required | For reporting |
| timestamps | | nullable | |
| soft_deletes | | nullable | deleted_at |

**Indexes:** unique on `(business_id, receipt_number)`, index on `business_id`, index on `user_id`, index on `customer_id`, index on `shift_id`, index on `sale_date`
**Endpoints:** Create (POS checkout), List (with filters), Show (receipt), Daily Sales
**Dependencies:** businesses, users, customers, shifts

---

### Order 10: SaleItem

**Table:** `sale_items`
**Purpose:** Line items per sale with refund tracking.

| Column | Type | Modifiers | Notes |
|--------|------|-----------|-------|
| id | bigIncrements | PK | |
| sale_id | bigInteger | FK→sales.id, required | |
| product_id | bigInteger | FK→products.id, nullable | Nullable on product delete |
| product_name | string(255) | required | Snapshot at sale time |
| product_price | decimal(10,2) | required | Snapshot at sale time |
| quantity | integer | required, > 0 | |
| unit_price | decimal(10,2) | required | After line discount |
| subtotal | decimal(12,2) | required | qty × unit_price |
| tax_amount | decimal(12,2) | default 0 | |
| discount_amount | decimal(12,2) | default 0 | |
| refunded_quantity | integer | default 0 | |
| refunded_amount | decimal(12,2) | default 0 | |
| timestamps | | nullable | |

**Indexes:** index on `sale_id`, index on `product_id`
**Endpoints:** Nested under Sale
**Dependencies:** sales, products

---

## Phase 4: Inventory Ledger & Subscriptions

### Order 11: StockMovement

**Table:** `stock_movements`
**Purpose:** Inventory ledger — every stock change with before/after snapshot.

| Column | Type | Modifiers | Notes |
|--------|------|-----------|-------|
| id | bigIncrements | PK | |
| business_id | bigInteger | FK→businesses.id, required | |
| product_id | bigInteger | FK→products.id, required | |
| sale_item_id | bigInteger | FK→sale_items.id, nullable | From sale/refund |
| type | enum('purchase','sale','adjustment','return','initial') | required | |
| quantity_change | integer | required | + or - |
| stock_before | integer | required | Snapshot |
| stock_after | integer | required | Snapshot |
| reference | string(255) | nullable | e.g. PO-001 |
| notes | text | nullable | |
| created_by | bigInteger | FK→users.id, nullable | |
| timestamps | | nullable | |

**Indexes:** index on `business_id`, index on `product_id`, index on `sale_item_id`, index on `created_by`, index on `type`
**Endpoints:** List (per product), Create (adjustments)
**Dependencies:** businesses, products, sale_items, users

---

### Order 12: Subscription

**Table:** `subscriptions`
**Purpose:** Track which plan each business is on.

| Column | Type | Modifiers | Notes |
|--------|------|-----------|-------|
| id | increments | PK | |
| business_id | bigInteger | FK→businesses.id, unique | One active sub per business |
| plan_id | bigInteger | FK→plans.id, required | |
| status | enum('active','trialing','cancelled','expired') | required | |
| starts_at | datetime | required | |
| trial_ends_at | datetime | nullable | |
| ends_at | datetime | nullable | |
| cancelled_at | datetime | nullable | |
| timestamps | | nullable | |

**Indexes:** unique on `business_id`, index on `plan_id`, index on `status`
**Endpoints:** Current Plan, Upgrade/Downgrade, Cancel
**Dependencies:** businesses, plans

---

## Phase 5: Expenses

### Order 13: ExpenseCategory

**Table:** `expense_categories`
**Purpose:** Categorise business expenses.

| Column | Type | Modifiers | Notes |
|--------|------|-----------|-------|
| id | bigIncrements | PK | |
| business_id | bigInteger | FK→businesses.id, required | |
| name | string(255) | required | e.g. "Rent", "Electricity" |
| description | text | nullable | |
| sort_order | integer | default 0 | |
| timestamps | | nullable | |

**Indexes:** unique on `(business_id, name)`, index on `business_id`
**Endpoints:** CRUD
**Dependencies:** businesses

---

### Order 14: Expense

**Table:** `expenses`
**Purpose:** Record expenses for net sales calc (Revenue − Expenses = Net).

| Column | Type | Modifiers | Notes |
|--------|------|-----------|-------|
| id | bigIncrements | PK | |
| business_id | bigInteger | FK→businesses.id, required | |
| expense_category_id | bigInteger | FK→expense_categories.id, nullable | |
| recorded_by | bigInteger | FK→users.id, nullable | |
| amount | decimal(12,2) | required, > 0 | UGX |
| description | text | required | |
| reference | string(255) | nullable | |
| expense_date | datetime | required | |
| timestamps | | nullable | |
| soft_deletes | | nullable | deleted_at |

**Indexes:** index on `business_id`, index on `expense_category_id`, index on `recorded_by`, index on `expense_date`
**Endpoints:** CRUD
**Dependencies:** businesses, expense_categories, users

---

## Phase 6: Sync API (for Electron Desktop)

### Task 6.1 — Sync Endpoints
- `POST /api/v1/sync/push` — Accept bulk JSON of new/changed records
- `GET /api/v1/sync/pull?since={timestamp}` — Return records updated after timestamp
- `GET /api/v1/sync/full` — Full data dump for first-time setup

### Task 6.2 — Sync Logic
- Each table uses `updated_at` for delta sync
- Receipt numbers: `{business_slug}-{increment}` — collision-free across devices
- Push validates `business_id` matches authenticated user's business
- Sync queue table (local SQLite only) tracks pending changes

### Task 6.3 — Plan Enforcement on Sync
- Free plan: block expense sync, reject if product/customer count exceeds limits
- Pro/Premium: full sync

---

## Migration Execution Order

| Seq | Migration | FKs |
|-----|-----------|-----|
| 1 | `create_plans_table` | — |
| 2 | `create_users_table` | Laravel default (business_id/role_id nullable) |
| 3 | `create_businesses_table` | owner_id → users |
| 4 | `create_roles_table` | business_id → businesses |
| 5 | `add_business_and_role_to_users` | business_id → businesses, role_id → roles, created_by → users |
| 6 | `create_categories_table` | business_id → businesses |
| 7 | `create_products_table` | business_id → businesses, category_id → categories |
| 8 | `create_customers_table` | business_id → businesses |
| 9 | `create_shifts_table` | business_id → businesses, user_id → users |
| 10 | `create_sales_table` | business_id → businesses, user_id → users, customer_id → customers, shift_id → shifts |
| 11 | `create_sale_items_table` | sale_id → sales, product_id → products |
| 12 | `create_stock_movements_table` | business_id → businesses, product_id → products, sale_item_id → sale_items, created_by → users |
| 13 | `create_subscriptions_table` | business_id → businesses, plan_id → plans |
| 14 | `create_expense_categories_table` | business_id → businesses |
| 15 | `create_expenses_table` | business_id → businesses, expense_category_id → expense_categories, recorded_by → users |

---

## API Route Structure

```
/api/v1/auth/register
/api/v1/auth/login
/api/v1/auth/logout
/api/v1/auth/me

/api/v1/plans                          (admin only)
/api/v1/subscriptions                  (current)
/api/v1/subscriptions/upgrade
/api/v1/subscriptions/cancel

/api/v1/businesses                     (register, show)
/api/v1/businesses/{business}/settings

/api/v1/roles                          (CRUD)
/api/v1/users                          (staff management)

/api/v1/categories                     (CRUD)
/api/v1/products                       (CRUD)
/api/v1/products/low-stock
/api/v1/products/{product}/stock-movements

/api/v1/customers                      (CRUD)
/api/v1/customers/{customer}/purchases

/api/v1/shifts/clock-in
/api/v1/shifts/clock-out
/api/v1/shifts/active
/api/v1/shifts

/api/v1/sales                          (CRUD)
/api/v1/sales/daily
/api/v1/sales/{sale}/items
/api/v1/sales/{sale}/refund

/api/v1/expense-categories             (CRUD)
/api/v1/expenses                       (CRUD)

/api/v1/sync/push
/api/v1/sync/pull
/api/v1/sync/full
```

---

## Entity Dependency Graph

```
Plan ────┐
          ├── Subscription
User ────┤
          ├── Business ──┬── Role
          │              ├── Category ──┐
          │              │              ├── Product
          │              ├── Customer
          │              ├── Shift ─────┐
          │              │             ├── Sale ─── SaleItem ─── StockMovement
          │              ├── ExpenseCategory ── Expense
          │              └── Subscription (already created)
```

**Total:** 14 entities × 12 files each = **168 generated files** + sync endpoints + config

---

## Key Design Decisions

| Decision | Rationale |
|----------|-----------|
| MySQL for cloud | Per Oscar's direction |
| SQLite for desktop local | Embedded, zero-config, perfect for offline POS |
| All FKs nullable on User | Circular dependency User↔Business; code enforces at app level |
| Receipt snapshots on SaleItem | Receipts survive product changes/deletion |
| StockMovement before/after snapshots | Full audit trail |
| JSON permissions on Role | Flexible, no extra tables, easy to seed |
| JSON features/limits on Plan | Same — one column, no schema changes for new tiers |
| Shift cached totals | Avoids aggregate queries on reconciliation |
| Auto-sync (no manual button) | Sync on reconnect + periodic background sync |
| Vera scripts (from Custocare) | `vera:fast` = php -l on changed files; `vera:extended` includes --pretend migrate |

---

## Dependencies (from Custocare, latest versions)

```json
{
  "require": {
    "php": "^8.2",
    "laravel/framework": "^12.0",
    "laravel/sanctum": "^4.0",
    "laravel/tinker": "^2.10.1",
    "spatie/laravel-permission": "^6.24",
    "barryvdh/laravel-dompdf": "^3.1",
    "paragonie/constant_time_encoding": "^3.1",
    "pragmarx/google2fa": "^9.0"
  },
  "require-dev": {
    "fakerphp/faker": "^1.23",
    "laravel/pail": "^1.2.2",
    "laravel/pint": "^1.24",
    "laravel/sail": "^1.41",
    "mockery/mockery": "^1.6",
    "nunomaduro/collision": "^8.6",
    "phpunit/phpunit": "^11.5.3"
  }
}
```

---

## Ready

All 14 entity definitions match the notebook exactly. MySQL configured. Dependencies listed from Custocare. Sync API planned.

Next step: scaffold Laravel project and begin **Entity 1: Plan**, Oscar.
