# Custosell — Backend Test Plan

**Version:** 1.0  
**Date:** 2026-06-02  
**Framework:** PHPUnit (Laravel Tests)  
**Pattern:** Feature tests with `RefreshDatabase`, one test file per entity

---

## Test Structure

```
tests/
  Feature/
    AuthTest.php           — register, login, logout, me
    PlanTest.php           — CRUD + plan seeding
    BusinessTest.php       — registration, settings, owner-only
    RoleTest.php           — CRUD + permission seeding
    UserTest.php           — staff management, business scoped
    CategoryTest.php       — CRUD, business scoped
    ProductTest.php        — CRUD, low stock, stock tracking
    CustomerTest.php       — CRUD, purchase history
    ShiftTest.php          — clock in/out, active shift
    SaleTest.php           — POS checkout, refund, daily sales
    SaleItemTest.php       — nested line items
    StockMovementTest.php  — ledger audit trail
    SubscriptionTest.php   — plan linking, upgrade
    ExpenseTest.php        — CRUD, net sales
    SyncTest.php           — push, pull, full
```

Each test file covers: **Authentication** (auth required), **Happy Path** (create/read/update/delete), **Scoping** (user cannot see other business's data), **Validation** (rejects bad input), **Authorization** (owner vs staff permissions).

---

## 1. AuthTest

### Test Scenarios

| # | Test | Method | Endpoint | Expected |
|---|------|--------|----------|----------|
| 1.1 | Register new user | POST | `/api/v1/auth/register` | 201 + user + token |
| 1.2 | Register with existing email | POST | `/api/v1/auth/register` | 422 validation error |
| 1.3 | Register with short password | POST | `/api/v1/auth/register` | 422 validation error |
| 1.4 | Login with valid credentials | POST | `/api/v1/auth/login` | 200 + user + token |
| 1.5 | Login with wrong password | POST | `/api/v1/auth/login` | 401 |
| 1.6 | Login with non-existent email | POST | `/api/v1/auth/login` | 401 |
| 1.7 | Logout authenticated user | POST | `/api/v1/auth/logout` | 200 + message |
| 1.8 | Logout without token | POST | `/api/v1/auth/logout` | 401 |
| 1.9 | Get current user (authenticated) | GET | `/api/v1/auth/me` | 200 + user |
| 1.10 | Get current user (unauthenticated) | GET | `/api/v1/auth/me` | 401 |

---

## 2. PlanTest

### Test Scenarios

| # | Test | Method | Endpoint | Expected |
|---|------|--------|----------|----------|
| 2.1 | List all plans | GET | `/api/v1/plans` | 200 + paginated plans |
| 2.2 | Verify seeded plans | GET | `/api/v1/plans` | Contains Free, Pro, Premium |
| 2.3 | Get single plan | GET | `/api/v1/plans/{id}` | 200 + plan data |
| 2.4 | Get non-existent plan | GET | `/api/v1/plans/{id}` | 404 |
| 2.5 | Create plan | POST | `/api/v1/plans` | 201 + new plan |
| 2.6 | Create plan with missing name | POST | `/api/v1/plans` | 422 |
| 2.7 | Create plan with duplicate slug | POST | `/api/v1/plans` | 422 |
| 2.8 | Update plan | PUT | `/api/v1/plans/{id}` | 200 + updated plan |
| 2.9 | Update plan price | PUT | `/api/v1/plans/{id}` | Price reflects change |
| 2.10 | Delete plan | DELETE | `/api/v1/plans/{id}` | 204 |
| 2.11 | Delete non-existent plan | DELETE | `/api/v1/plans/{id}` | 404 |
| 2.12 | Verify plan JSON features cast correctly | GET | `/api/v1/plans/{id}` | features is array not string |

---

## 3. BusinessTest

### Test Scenarios

| # | Test | Method | Endpoint | Expected |
|---|------|--------|----------|----------|
| 3.1 | Register business with user | POST | `/api/v1/businesses/register` | 201 + business + user created |
| 3.2 | Register with missing name | POST | `/api/v1/businesses/register` | 422 |
| 3.3 | Get my business | GET | `/api/v1/businesses/mine` | 200 + owner's business |
| 3.4 | Get my business (no auth) | GET | `/api/v1/businesses/mine` | 401 |
| 3.5 | Get specific business | GET | `/api/v1/businesses/{id}` | 200 |
| 3.6 | Update business settings | PUT | `/api/v1/businesses/{id}` | 200 |
| 3.7 | Update business settings (not owner) | PUT | `/api/v1/businesses/{id}` | 403 |
| 3.8 | Get settings | GET | `/api/v1/businesses/settings` | 200 + business |
| 3.9 | Update settings | PUT | `/api/v1/businesses/settings` | 200 |
| 3.10 | Registration creates user with business_id | POST | `/api/v1/businesses/register` | User.business_id = business.id |
| 3.11 | Default currency is UGX | GET | `/api/v1/businesses/mine` | currency = 'UGX' |

---

## 4. RoleTest

### Test Scenarios

| # | Test | Method | Endpoint | Expected |
|---|------|--------|----------|----------|
| 4.1 | List roles for business | GET | `/api/v1/roles` | 200 + roles scoped to business |
| 4.2 | Get single role | GET | `/api/v1/roles/{id}` | 200 |
| 4.3 | Create role | POST | `/api/v1/roles` | 201 |
| 4.4 | Create role with invalid permissions | POST | `/api/v1/roles` | 422 |
| 4.5 | Update role permissions | PUT | `/api/v1/roles/{id}` | 200 |
| 4.6 | Delete role | DELETE | `/api/v1/roles/{id}` | 204 |
| 4.7 | Staff cannot see roles from another business | GET | `/api/v1/roles` | Empty or scoped |
| 4.8 | Verify seeded Admin role exists | GET | `/api/v1/roles` | Contains 'admin' |
| 4.9 | Verify seeded Staff role exists | GET | `/api/v1/roles` | Contains 'staff' |
| 4.10 | Role permissions stored as array | GET | `/api/v1/roles/{id}` | permissions is object |

---

## 5. UserTest (Staff Management)

### Test Scenarios

| # | Test | Method | Endpoint | Expected |
|---|------|--------|----------|----------|
| 5.1 | List staff users | GET | `/api/v1/users` | 200 + users scoped to business |
| 5.2 | Get single user | GET | `/api/v1/users/{id}` | 200 |
| 5.3 | Create staff user | POST | `/api/v1/users` | 201 + user with business_id |
| 5.4 | Create staff with role assignment | POST | `/api/v1/users` | 201 + role_id set |
| 5.5 | Create staff with duplicate email | POST | `/api/v1/users` | 422 |
| 5.6 | Delete staff user (soft delete) | DELETE | `/api/v1/users/{id}` | 204 |
| 5.7 | Deleted staff cannot login | POST | `/api/v1/auth/login` | 401 |
| 5.8 | Cannot see users from other business | GET | `/api/v1/users` | Scoped correctly |
| 5.9 | created_by field set on staff creation | POST | `/api/v1/users` | created_by = admin.id |

---

## 6. CategoryTest

### Test Scenarios

| # | Test | Method | Endpoint | Expected |
|---|------|--------|----------|----------|
| 6.1 | List categories | GET | `/api/v1/categories` | 200 + scoped to business |
| 6.2 | Create category | POST | `/api/v1/categories` | 201 |
| 6.3 | Create category with duplicate name | POST | `/api/v1/categories` | 422 |
| 6.4 | Update category | PUT | `/api/v1/categories/{id}` | 200 |
| 6.5 | Delete category | DELETE | `/api/v1/categories/{id}` | 204 |
| 6.6 | Categories scoped per business | GET | `/api/v1/categories` | Business A ≠ Business B |

---

## 7. ProductTest

### Test Scenarios

| # | Test | Method | Endpoint | Expected |
|---|------|--------|----------|----------|
| 7.1 | List products | GET | `/api/v1/products` | 200 |
| 7.2 | Create product | POST | `/api/v1/products` | 201 |
| 7.3 | Create product with category | POST | `/api/v1/products` | 201 + category_id |
| 7.4 | Create product with negative price | POST | `/api/v1/products` | 422 |
| 7.5 | Update product | PUT | `/api/v1/products/{id}` | 200 |
| 7.6 | Update stock quantity | PUT | `/api/v1/products/{id}` | stock_quantity changes |
| 7.7 | Delete product (soft) | DELETE | `/api/v1/products/{id}` | 204 |
| 7.8 | Get low stock products | GET | `/api/v1/products/low-stock` | 200 + stock <= threshold |
| 7.9 | Get stock movements for a product | GET | `/api/v1/products/{id}/stock-movements` | 200 |
| 7.10 | Product scoped per business | GET | `/api/v1/products` | Business A ≠ Business B |
| 7.11 | Unique SKU per business enforced | POST | `/api/v1/products` | 422 on duplicate SKU |

---

## 8. CustomerTest

### Test Scenarios

| # | Test | Method | Endpoint | Expected |
|---|------|--------|----------|----------|
| 8.1 | List customers | GET | `/api/v1/customers` | 200 |
| 8.2 | Create customer | POST | `/api/v1/customers` | 201 |
| 8.3 | Create with duplicate phone | POST | `/api/v1/customers` | 422 |
| 8.4 | Update customer | PUT | `/api/v1/customers/{id}` | 200 |
| 8.5 | Delete customer | DELETE | `/api/v1/customers/{id}` | 204 |
| 8.6 | Get purchase history | GET | `/api/v1/customers/{id}/purchases` | 200 + sales |
| 8.7 | Customer scoped per business | GET | `/api/v1/customers` | Business A ≠ Business B |

---

## 9. ShiftTest

### Test Scenarios

| # | Test | Method | Endpoint | Expected |
|---|------|--------|----------|----------|
| 9.1 | Clock in | POST | `/api/v1/shifts/clock-in` | 201 + shift with clock_in |
| 9.2 | Clock in twice (active shift exists) | POST | `/api/v1/shifts/clock-in` | 409 or return existing |
| 9.3 | Clock out | POST | `/api/v1/shifts/{id}/clock-out` | 200 + clock_out set |
| 9.4 | Get active shift | GET | `/api/v1/shifts/active` | 200 + current active shift |
| 9.5 | List shifts history | GET | `/api/v1/shifts` | 200 |
| 9.6 | List shifts (with date filter) | GET | `/api/v1/shifts?date=2026-06-02` | 200 + filtered |
| 9.7 | Shift scoped per business | GET | `/api/v1/shifts` | Business A ≠ Business B |

---

## 10. SaleTest

### Test Scenarios

| # | Test | Method | Endpoint | Expected |
|---|------|--------|----------|----------|
| 10.1 | List sales | GET | `/api/v1/sales` | 200 |
| 10.2 | Create sale with items | POST | `/api/v1/sales` | 201 + sale + sale_items |
| 10.3 | Create sale updates stock | POST | `/api/v1/sales` | Stock decreases |
| 10.4 | Create sale creates stock_movement | POST | `/api/v1/sales` | StockMovement type='sale' |
| 10.5 | Create sale generates receipt_number | POST | `/api/v1/sales` | receipt_number not null |
| 10.6 | Get daily sales | GET | `/api/v1/sales/daily` | 200 + today's sales |
| 10.7 | Get daily sales (with date) | GET | `/api/v1/sales/daily?date=2026-06-01` | 200 + filtered |
| 10.8 | Process refund | POST | `/api/v1/sales/{id}/refund` | 200 + refunded |
| 10.9 | Partial refund updates sale_items | POST | `/api/v1/sales/{id}/refund` | refunded_quantity set |
| 10.10 | Refund restocks inventory | POST | `/api/v1/sales/{id}/refund` | StockMovement type='return' |
| 10.11 | Get single sale with items | GET | `/api/v1/sales/{id}` | 200 + items loaded |
| 10.12 | Delete sale | DELETE | `/api/v1/sales/{id}` | 204 |
| 10.13 | Sale scoped per business | GET | `/api/v1/sales` | Business A ≠ Business B |
| 10.14 | Create sale without auth | POST | `/api/v1/sales` | 401 |
| 10.15 | Create sale with invalid payment_method | POST | `/api/v1/sales` | 422 |

---

## 11. SaleItemTest

### Test Scenarios

| # | Test | Method | Endpoint | Expected |
|---|------|--------|----------|----------|
| 11.1 | List items for a sale | GET | `/api/v1/sale-items?sale_id={id}` | 200 |
| 11.2 | Create sale item | POST | `/api/v1/sale-items` | 201 |
| 11.3 | Update sale item | PUT | `/api/v1/sale-items/{id}` | 200 |
| 11.4 | Delete sale item | DELETE | `/api/v1/sale-items/{id}` | 204 |
| 11.5 | Product name snapshot saved | POST | `/api/v1/sale-items` | product_name matches product |

---

## 12. StockMovementTest

### Test Scenarios

| # | Test | Method | Endpoint | Expected |
|---|------|--------|----------|----------|
| 12.1 | List stock movements for product | GET | `/api/v1/products/{id}/stock-movements` | 200 |
| 12.2 | Create manual adjustment | POST | `/api/v1/stock-movements` | 201 |
| 12.3 | Purchase stock movement | POST | `/api/v1/stock-movements` | 201 + type='purchase' |
| 12.4 | Stock before/after snapshots correct | POST | `/api/v1/stock-movements` | stock_after = stock_before + quantity_change |
| 12.5 | Stock movement updates product stock | POST | `/api/v1/stock-movements` | product.stock_quantity matches |
| 12.6 | Cannot create sale movement manually | POST | `/api/v1/stock-movements` | type='sale' only via Sale |

---

## 13. SubscriptionTest

### Test Scenarios

| # | Test | Method | Endpoint | Expected |
|---|------|--------|----------|----------|
| 13.1 | Get current subscription | GET | `/api/v1/subscriptions` | 200 |
| 13.2 | Create subscription | POST | `/api/v1/subscriptions` | 201 |
| 13.3 | Cannot create duplicate subscription | POST | `/api/v1/subscriptions` | 422 (unique business) |
| 13.4 | Upgrade subscription | PUT | `/api/v1/subscriptions/upgrade` | 200 + new plan_id |
| 13.5 | Cancel subscription | POST | `/api/v1/subscriptions/cancel` | 200 + cancelled |
| 13.6 | Subscription links to plan | GET | `/api/v1/subscriptions` | plan_id references plans |
| 13.7 | Subscription scoped to business | GET | `/api/v1/subscriptions` | Only own business |

---

## 14. ExpenseTest

### Test Scenarios

| # | Test | Method | Endpoint | Expected |
|---|------|--------|----------|----------|
| 14.1 | List expense categories | GET | `/api/v1/expense-categories` | 200 |
| 14.2 | Create expense category | POST | `/api/v1/expense-categories` | 201 |
| 14.3 | Create expense category duplicate name | POST | `/api/v1/expense-categories` | 422 |
| 14.4 | List expenses | GET | `/api/v1/expenses` | 200 |
| 14.5 | Create expense | POST | `/api/v1/expenses` | 201 |
| 14.6 | Create expense with negative amount | POST | `/api/v1/expenses` | 422 |
| 14.7 | Update expense | PUT | `/api/v1/expenses/{id}` | 200 |
| 14.8 | Delete expense | DELETE | `/api/v1/expenses/{id}` | 204 |
| 14.9 | Expense scoped per business | GET | `/api/v1/expenses` | Business A ≠ Business B |
| 14.10 | Net sales = revenue − expenses | GET | `/api/v1/sales/daily?include_net=true` | Calculated correctly |

---

## 15. SyncTest

### Test Scenarios

| # | Test | Method | Endpoint | Expected |
|---|------|--------|----------|----------|
| 15.1 | Push categories | POST | `/api/v1/sync/push` | 200 + imported count |
| 15.2 | Push products | POST | `/api/v1/sync/push` | 200 |
| 15.3 | Push sales with items | POST | `/api/v1/sync/push` | 200 |
| 15.4 | Push without auth | POST | `/api/v1/sync/push` | 401 |
| 15.5 | Pull all data | GET | `/api/v1/sync/pull` | 200 + all entities |
| 15.6 | Pull with since filter | GET | `/api/v1/sync/pull?since=2026-01-01` | 200 + filtered |
| 15.7 | Full sync | GET | `/api/v1/sync/full` | 200 + complete dump |
| 15.8 | Full sync without auth | GET | `/api/v1/sync/full` | 401 |
| 15.9 | Pull scoped to business | GET | `/api/v1/sync/pull` | Only own business data |
| 15.10 | Push updates existing records | POST | `/api/v1/sync/push` | Updated, not duplicated |

---

## Test Execution Plan

### Per-Test File Structure
```php
class ProductTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected User $staff;
    protected Business $business;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed: PlanSeeder
        // Create business with admin user
        // Create staff user
        // Seed default roles
    }

    // Test groups:
    // 1. Authentication tests (no token = 401)
    // 2. Business scoping tests (other business = empty/404)
    // 3. CRUD tests (store, index, show, update, destroy)
    // 4. Validation tests (bad input = 422)
    // 5. Authorization tests (staff cannot admin)
}
```

### Running Tests
```bash
# Run all tests
php artisan test

# Run single test file
php artisan test --filter=ProductTest

# Run single test
php artisan test --filter="test_can_create_product"
```

### Assertion Patterns
```php
// Standard CRUD
$response->assertStatus(201);
$response->assertJsonStructure(['data' => ['id', 'name']]);

// Business scoping
$response->assertJsonCount(0, 'data');

// Validation
$response->assertStatus(422);
$response->assertJsonValidationErrors(['name']);

// Auth
$response->assertStatus(401);
```

---

## Approval

| Section | Status |
|---------|--------|
| Auth | ✅ |
| Plan | ✅ |
| Business | ✅ |
| Role | ✅ |
| User | ✅ |
| Category | ✅ |
| Product | ✅ |
| Customer | ✅ |
| Shift | ✅ |
| Sale | ✅ |
| SaleItem | ✅ |
| StockMovement | ✅ |
| Subscription | ✅ |
| Expense | ✅ |
| Sync | ✅ |
