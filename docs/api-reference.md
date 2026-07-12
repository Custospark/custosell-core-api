# Custosell — API Reference

**Version:** 1.0  
**Base URL:** `http://localhost:8000/api/v1`  
**Auth:** Bearer token via `Authorization: Bearer {token}` header  
**Content-Type:** `application/json`  
**Currency:** UGX (Ugandan Shillings, all monetary values in decimal)

---

## Conventions

### Authentication
- Public endpoints: no token required
- Protected endpoints: require `Authorization: Bearer {token}` — returns 401 if missing/expired

### Business Scoping
All scoped entities (categories, products, customers, sales, etc.) automatically filter by the authenticated user's `business_id`. Staff users only see their own business's data.

### Pagination
All list endpoints return Laravel pagination format:
```json
{
  "data": [...],
  "links": {
    "first": "...",
    "last": "...",
    "prev": null,
    "next": "..."
  },
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "per_page": 15,
    "to": 10,
    "total": 10
  }
}
```

### Error Response Format
```json
{
  "message": "Error description",
  "errors": {
    "field_name": ["Validation error message"]
  }
}
```

### Status Codes Used
| Code | Meaning |
|------|---------|
| 200 | Success (GET, PUT) |
| 201 | Created (POST) |
| 204 | No content (DELETE) |
| 401 | Unauthenticated (missing/expired token) |
| 403 | Forbidden (no permission) |
| 404 | Not found |
| 409 | Conflict (e.g. duplicate active shift) |
| 422 | Validation error |
| 500 | Server error |

### Enum Values
| Field | Values |
|-------|--------|
| `payment_method` | `cash`, `mobile_money`, `card`, `other` |
| `payment_status` | `paid`, `partially_refunded`, `refunded` |
| `stock_movement_type` | `purchase`, `sale`, `adjustment`, `return`, `initial` |
| `shift_status` | `active`, `completed` |
| `subscription_status` | `active`, `trialing`, `cancelled`, `expired` |
| `business_status` | `active`, `suspended` |

---

## 1. Authentication

### POST `/auth/register`
**Auth:** None  
**Description:** Create a new user account. Returns user data + API token.

**Request Body:**
| Field | Type | Required | Rules |
|-------|------|----------|-------|
| name | string | Yes | max:255 |
| email | string | Yes | valid email, unique, max:255 |
| password | string | Yes | min:6 |
| password_confirmation | string | Yes | must match password |
| phone | string | No | max:50 |
| account_type | string | No | `storefront_buyer` — customer-only (`business_id` null, empty modules, no shift). Merchants use `POST /businesses/register`. |

**Response (201):**
```json
{
  "user": {
    "data": {
      "id": 1,
      "business_id": null,
      "role_id": null,
      "name": "John Doe",
      "email": "john@example.com",
      "phone": "+256701234567",
      "is_active": true,
      "role": null,
      "created_at": "2026-06-02T12:00:00.000000Z",
      "updated_at": "2026-06-02T12:00:00.000000Z"
    }
  },
  "token": "1|abc123tokenstring..."
}
```

**Errors:** 422 — email already taken, password too short, confirmation mismatch

---

### POST `/auth/login`
**Auth:** None  
**Description:** Authenticate and receive API token.

**Request Body:**
| Field | Type | Required | Rules |
|-------|------|----------|-------|
| email | string | Yes | valid email |
| password | string | Yes | - |

**Response (200):**
```json
{
  "user": {
    "data": {
      "id": 1,
      "business_id": 1,
      "role_id": 1,
      "name": "John Doe",
      "email": "john@example.com",
      "phone": "+256701234567",
      "is_active": true,
      "role": {
        "data": {
          "id": 1,
          "name": "Admin",
          "slug": "admin",
          "permissions": { "sales.create": true, "sales.view": true, ... }
        }
      },
      "created_at": "2026-06-02T12:00:00.000000Z",
      "updated_at": "2026-06-02T12:00:00.000000Z"
    }
  },
  "token": "1|abc123tokenstring..."
}
```

**Errors:** 401 — invalid credentials

---

### POST `/auth/logout`
**Auth:** Required  
**Description:** Revoke current API token.

**Headers:** `Authorization: Bearer {token}`

**Response (200):**
```json
{
  "message": "Logged out"
}
```

**Errors:** 401 — no token

---

### GET `/auth/me`
**Auth:** Required  
**Description:** Get currently authenticated user with role.

**Headers:** `Authorization: Bearer {token}`

**Response (200):**
```json
{
  "data": {
    "id": 1,
    "business_id": 1,
    "role_id": 1,
    "name": "John Doe",
    "email": "john@example.com",
    "phone": "+256701234567",
    "is_active": true,
    "role": {
      "data": {
        "id": 1,
        "business_id": 1,
        "name": "Admin",
        "slug": "admin",
        "description": "Full access to all features",
        "permissions": {
          "sales.create": true,
          "sales.view": true,
          "sales.refund": true,
          "sales.discount": true,
          "sales.delete": true,
          "inventory.view": true,
          "inventory.create": true,
          "inventory.edit": true,
          "inventory.delete": true,
          "customers.view": true,
          "customers.create": true,
          "customers.edit": true,
          "expenses.view": true,
          "expenses.create": true,
          "expenses.edit": true,
          "expenses.delete": true,
          "users.view": true,
          "users.create": true,
          "users.edit": true,
          "users.delete": true,
          "reports.view": true,
          "settings.view": true,
          "settings.edit": true
        },
        "is_default": true,
        "created_at": "2026-06-02T12:00:00.000000Z",
        "updated_at": "2026-06-02T12:00:00.000000Z"
      }
    },
    "created_at": "2026-06-02T12:00:00.000000Z",
    "updated_at": "2026-06-02T12:00:00.000000Z"
  }
}
```

**Errors:** 401 — no token

---

## 2. Plans

### GET `/plans`
**Auth:** None  
**Description:** List all plans ordered by sort_order.

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Free",
      "slug": "free",
      "description": "For small vendors testing the waters",
      "price_monthly": "0.00",
      "price_yearly": null,
      "features": {
        "expenses": false,
        "shift_tracking": false,
        "discounts": false,
        "refunds": false,
        "export_data": false
      },
      "limits": {
        "staff_users": 1,
        "products": 50,
        "monthly_sales": 100,
        "customers": 50,
        "categories": 5
      },
      "is_active": true,
      "sort_order": 1,
      "created_at": "2026-06-02T12:00:00.000000Z",
      "updated_at": "2026-06-02T12:00:00.000000Z"
    },
    {
      "id": 2,
      "name": "Pro",
      "slug": "pro",
      "price_monthly": "30000.00",
      "price_yearly": "300000.00",
      "features": { "expenses": true, "shift_tracking": true, "discounts": true, "refunds": true, "export_data": false },
      "limits": { "staff_users": 5, "products": 1000, "monthly_sales": null, "customers": 1000, "categories": 30 },
      "sort_order": 2
    },
    {
      "id": 3,
      "name": "Premium",
      "slug": "premium",
      "price_monthly": "100000.00",
      "features": { "expenses": true, "shift_tracking": true, "discounts": true, "refunds": true, "export_data": true },
      "limits": { "staff_users": null, "products": null, "monthly_sales": null, "customers": null, "categories": null },
      "sort_order": 3
    }
  ]
}
```

**Frontend notes:**
- `null` limit means unlimited
- Use `features` object to conditionally show/hide UI elements
- Use `limits` object to enforce max counts on the frontend

---

### POST `/plans`
**Auth:** Required  
**Description:** Create a new plan (admin only).

**Request Body:**
| Field | Type | Required | Rules |
|-------|------|----------|-------|
| name | string | Yes | max:100 |
| slug | string | Yes | unique, max:100 |
| description | string | No | - |
| price_monthly | number | Yes | min:0 |
| price_yearly | number | No | min:0 |
| features | object | Yes | JSON of feature flags |
| limits | object | Yes | JSON of numerical limits |
| is_active | boolean | No | default true |
| sort_order | integer | No | min:0 |

**Response (201):** Single plan resource (same structure as above)

---

### GET `/plans/{id}`
**Auth:** None  
**Description:** Get single plan by ID.

**Response (200):** Single plan resource  
**Errors:** 404 — plan not found

---

### PUT `/plans/{id}`
**Auth:** Required  
**Description:** Update a plan.

**Request Body:** Same fields as POST (all optional for PUT)

**Response (200):** Updated plan resource  
**Errors:** 404 — plan not found, 422 — validation

---

### DELETE `/plans/{id}`
**Auth:** Required  
**Description:** Delete a plan.

**Response:** 204 No Content  
**Errors:** 404 — plan not found

---

## 3. Businesses

### POST `/businesses/register`
**Auth:** None  
**Description:** Register a new business with its owner user. Creates both user and business in one transaction.

**Request Body:**
| Field | Type | Required | Rules |
|-------|------|----------|-------|
| name | string | Yes | Business name, max:255 |
| slug | string | No | Auto-generated from name if omitted |
| email | string | No | Business contact email |
| phone | string | No | Business contact phone |
| address | string | No | Physical address |
| currency | string | No | Default: "UGX" |

**Response (201):**
```json
{
  "data": {
    "id": 1,
    "owner_id": 1,
    "name": "My Shop",
    "slug": "my-shop",
    "email": "shop@example.com",
    "phone": "+256701234567",
    "address": "123 Kampala Road",
    "currency": "UGX",
    "receipt_footer": null,
    "logo_path": null,
    "status": "active",
    "trial_ends_at": null,
    "subscription": null,
    "created_at": "2026-06-02T12:00:00.000000Z",
    "updated_at": "2026-06-02T12:00:00.000000Z"
  }
}
```

**Frontend notes:**
- After registration, the user is created and linked as business owner
- The response returns only the business. User is authenticated — use `/auth/login` with the password to get a token
- Default currency is `UGX`

---

### GET `/businesses/mine`
**Auth:** Required  
**Description:** Get the business owned by the authenticated user.

**Response (200):** Business resource (see above)  
**Errors:** 404 — user doesn't own a business

---

### GET `/businesses/{id}`
**Auth:** Required  
**Description:** Get a specific business by ID.

**Response (200):** Business resource  
**Errors:** 404 — not found

---

### PUT `/businesses/{id}`
**Auth:** Required  
**Description:** Update business details.

**Request Body:**
| Field | Type | Required | Rules |
|-------|------|----------|-------|
| name | string | No | max:255 |
| email | string | No | email |
| phone | string | No | max:50 |
| address | string | No | - |
| currency | string | No | max:10 |
| receipt_footer | string | No | Custom message on receipts |
| logo_path | string | No | max:255 |

**Response (200):** Updated business resource

---

### GET `/businesses/settings`
**Auth:** Required  
**Description:** Get the authenticated user's business settings.

**Response (200):** Business resource

---

### PUT `/businesses/settings`
**Auth:** Required  
**Description:** Update business settings. Same fields as `PUT /businesses/{id}`.

**Response (200):** Updated business resource

---

## 4. Roles

### GET `/roles`
**Auth:** Required  
**Description:** List all roles for the authenticated user's business.

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "business_id": 1,
      "name": "Admin",
      "slug": "admin",
      "description": "Full access to all features",
      "permissions": { "sales.create": true, "sales.view": true, "sales.refund": true, ... },
      "is_default": true,
      "created_at": "2026-06-02T12:00:00.000000Z",
      "updated_at": "2026-06-02T12:00:00.000000Z"
    },
    {
      "id": 2,
      "business_id": 1,
      "name": "Staff",
      "slug": "staff",
      "permissions": { "sales.create": true, "sales.view": true, "sales.refund": false, ... },
      "is_default": true,
      ...
    }
  ]
}
```

**Frontend notes:**
- Two default roles seeded per business: Admin (all permissions true), Staff (limited)
- Use `permissions` object to conditionally show/hide UI features per user:
  ```
  if (user.role.permissions['sales.refund']) showRefundButton()
  if (user.role.permissions['expenses.view']) showExpensesMenuItem()
  ```

**Permission flags:**
```json
{
  "sales.create": true,
  "sales.view": true,
  "sales.refund": false,
  "sales.discount": false,
  "sales.delete": false,
  "inventory.view": true,
  "inventory.create": false,
  "inventory.edit": false,
  "inventory.delete": false,
  "customers.view": true,
  "customers.create": true,
  "customers.edit": false,
  "expenses.view": false,
  "expenses.create": false,
  "expenses.edit": false,
  "expenses.delete": false,
  "users.view": false,
  "users.create": false,
  "users.edit": false,
  "users.delete": false,
  "reports.view": false,
  "settings.view": false,
  "settings.edit": false
}
```

---

### POST `/roles`
**Auth:** Required  
**Description:** Create a new role for the business.

**Request Body:**
| Field | Type | Required | Rules |
|-------|------|----------|-------|
| name | string | Yes | max:100 |
| slug | string | Yes | max:100 |
| description | string | No | - |
| permissions | object | Yes | JSON of permission booleans |
| is_default | boolean | No | - |

**Response (201):** Single role resource

---

### GET `/roles/{id}`
**Auth:** Required  
**Description:** Get a role by ID.

### PUT `/roles/{id}`
**Auth:** Required  
**Description:** Update role permissions.

### DELETE `/roles/{id}`
**Auth:** Required  
**Description:** Delete a role.  
**Response:** 204

---

## 5. Users (Staff Management)

### GET `/users`
**Auth:** Required  
**Description:** List all staff users for the authenticated user's business.

**Response (200):**
```json
{
  "data": [
    {
      "id": 2,
      "business_id": 1,
      "role_id": 2,
      "name": "Jane Staff",
      "email": "jane@example.com",
      "phone": "+256701234568",
      "is_active": true,
      "role": { "data": { "id": 2, "name": "Staff", "slug": "staff" } },
      "created_at": "2026-06-02T12:00:00.000000Z",
      "updated_at": "2026-06-02T12:00:00.000000Z"
    }
  ]
}
```

---

### POST `/users`
**Auth:** Required  
**Description:** Create a new staff user under the business.

**Request Body:**
| Field | Type | Required | Rules |
|-------|------|----------|-------|
| name | string | Yes | max:255 |
| email | string | Yes | unique, valid email |
| password | string | Yes | min:6 |
| password_confirmation | string | Yes | must match |
| phone | string | No | max:50 |
| role_id | integer | No | FK to roles table |

**Response (201):** User resource

**Frontend notes:**
- `created_by` is auto-set to the authenticated admin who creates the staff
- `business_id` is auto-set from the authenticated user's business

---

### GET `/users/{id}`
**Auth:** Required  
**Description:** Get a single staff user.

### DELETE `/users/{id}`
**Auth:** Required  
**Description:** Soft-delete a staff user (they can no longer log in).  
**Response:** 204

---

## 6. Categories

### GET `/categories`
**Auth:** Required  
**Description:** List product categories for the business.

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "business_id": 1,
      "name": "Beverages",
      "description": "Drinks and refreshments",
      "sort_order": 0,
      "created_at": "2026-06-02T12:00:00.000000Z",
      "updated_at": "2026-06-02T12:00:00.000000Z"
    }
  ]
}
```

---

### POST `/categories`
**Auth:** Required  
**Description:** Create a category.

**Request Body:**
| Field | Type | Required | Rules |
|-------|------|----------|-------|
| name | string | Yes | max:255, unique per business |
| description | string | No | - |
| sort_order | integer | No | default 0 |

**Response (201):** Category resource  
**Errors:** 422 — duplicate name within same business

---

### GET/PUT/DELETE `/categories/{id}`
Standard CRUD. PUT accepts same fields as POST (all optional).

---

## 7. Products

### GET `/products`
**Auth:** Required  
**Description:** List products for the business.

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "business_id": 1,
      "category_id": 1,
      "name": "Coca Cola 500ml",
      "description": "Chilled bottle",
      "sku": "COKE-500",
      "barcode": null,
      "unit_price": "3000.00",
      "cost_price": "2000.00",
      "stock_quantity": 50,
      "low_stock_threshold": 5,
      "tax_percentage": "0.00",
      "is_active": true,
      "category": { "data": { "id": 1, "name": "Beverages" } },
      "created_at": "2026-06-02T12:00:00.000000Z",
      "updated_at": "2026-06-02T12:00:00.000000Z"
    }
  ]
}
```

**Frontend notes:**
- `unit_price` is the selling price in UGX
- `cost_price` is optional — used for profit calculation
- `stock_quantity` is the live count (updated by sales + stock movements)

---

### POST `/products`
**Auth:** Required  
**Description:** Create a product.

**Request Body:**
| Field | Type | Required | Rules |
|-------|------|----------|-------|
| name | string | Yes | max:255 |
| category_id | integer | No | FK to categories |
| description | string | No | - |
| sku | string | No | unique per business |
| barcode | string | No | - |
| unit_price | number | Yes | min:0 |
| cost_price | number | No | min:0 |
| stock_quantity | integer | No | default 0, min:0 |
| low_stock_threshold | integer | No | default 5 |
| tax_percentage | number | No | default 0 |
| is_active | boolean | No | default true |

**Response (201):** Product resource

---

### GET `/products/low-stock`
**Auth:** Required  
**Description:** Get products where `stock_quantity <= low_stock_threshold`.

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Coca Cola 500ml",
      "stock_quantity": 3,
      "low_stock_threshold": 5,
      "unit_price": "3000.00"
    }
  ]
}
```

**Frontend notes:** Use this endpoint to show low-stock alerts on the dashboard.

---

### GET `/products/{id}/stock-movements`
**Auth:** Required  
**Description:** Get the inventory ledger for a specific product.

**Response (200):** Paginated list of stock movements (see StockMovement section)

---

### GET/PUT/DELETE `/products/{id}`
Standard CRUD. PUT accepts same fields as POST. DELETE is soft delete (204).

---

## 8. Customers

### GET `/customers`
**Auth:** Required  
**Description:** List customers for the business.

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "business_id": 1,
      "name": "Alice Mukisa",
      "phone": "+256701234569",
      "email": "alice@example.com",
      "total_purchases": "15000.00",
      "last_purchase_at": "2026-06-02T12:00:00.000000Z",
      "created_at": "2026-06-02T12:00:00.000000Z",
      "updated_at": "2026-06-02T12:00:00.000000Z"
    }
  ]
}
```

---

### POST `/customers`
**Auth:** Required  
**Description:** Create a customer.

**Request Body:**
| Field | Type | Required | Rules |
|-------|------|----------|-------|
| name | string | Yes | max:255 |
| phone | string | Yes | unique per business, max:50 |
| email | string | No | - |

**Response (201):** Customer resource

---

### GET `/customers/{id}/purchases`
**Auth:** Required  
**Description:** Get all sales made by this customer.

**Response (200):** Paginated list of Sales (see Sale section)

---

### GET/PUT/DELETE `/customers/{id}`
Standard CRUD.

---

## 9. Shifts

### POST `/shifts/clock-in`
**Auth:** Required  
**Description:** Start a new shift for the authenticated user.

**Request Body:**
| Field | Type | Required | Rules |
|-------|------|----------|-------|
| notes | string | No | Optional shift notes |

**Response (201):**
```json
{
  "data": {
    "id": 1,
    "business_id": 1,
    "user_id": 1,
    "clock_in": "2026-06-02T08:00:00.000000Z",
    "clock_out": null,
    "total_sales": "0.00",
    "total_cash": "0.00",
    "total_mobile_money": "0.00",
    "total_card": "0.00",
    "status": "active",
    "notes": "Morning shift",
    "created_at": "2026-06-02T08:00:00.000000Z",
    "updated_at": "2026-06-02T08:00:00.000000Z"
  }
}
```

**Frontend notes:**
- Staff can only have one active shift at a time
- If already clocked in, returns 409 with existing active shift

---

### POST `/shifts/{id}/clock-out`
**Auth:** Required  
**Description:** End an active shift.

**Request Body:**
| Field | Type | Required | Rules |
|-------|------|----------|-------|
| notes | string | No | End-of-shift notes |

**Response (200):** Shift resource with `clock_out` set and `status: "completed"`

---

### GET `/shifts/active`
**Auth:** Required  
**Description:** Get the currently active shift for the authenticated user.

**Response (200):** Shift resource  
**Errors:** 404 — no active shift

---

### GET `/shifts`
**Auth:** Required  
**Description:** List shift history.

**Query params:** `?status=active|completed`, `?date=2026-06-02`

**Response (200):** Paginated list of shifts

---

## 10. Sales

### GET `/sales`
**Auth:** Required  
**Description:** List sales for the business.

**Query params:** `?date=2026-06-02`, `?customer_id=1`, `?user_id=1`, `?payment_method=cash`

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "business_id": 1,
      "user_id": 1,
      "customer_id": 1,
      "shift_id": 1,
      "receipt_number": "my-shop-0001",
      "subtotal": "3000.00",
      "tax_total": "0.00",
      "discount_amount": "0.00",
      "total_amount": "3000.00",
      "payment_method": "cash",
      "payment_status": "paid",
      "notes": null,
      "sale_date": "2026-06-02T12:00:00.000000Z",
      "items": [
        {
          "id": 1,
          "product_id": 1,
          "product_name": "Coca Cola 500ml",
          "product_price": "3000.00",
          "quantity": 1,
          "unit_price": "3000.00",
          "subtotal": "3000.00",
          "tax_amount": "0.00",
          "discount_amount": "0.00",
          "refunded_quantity": 0,
          "refunded_amount": "0.00"
        }
      ],
      "created_at": "2026-06-02T12:00:00.000000Z",
      "updated_at": "2026-06-02T12:00:00.000000Z"
    }
  ]
}
```

**Frontend notes:**
- `items` array is loaded with each sale
- `receipt_number` is auto-generated as `{business-slug}-{increment}`
- Use `sale_date` for display, not `created_at`

---

### POST `/sales`
**Auth:** Required  
**Description:** Create a new sale (POS checkout). 

**Request Body:**
| Field | Type | Required | Rules |
|-------|------|----------|-------|
| customer_id | integer | No | FK to customers |
| shift_id | integer | No | FK to shifts |
| payment_method | string | Yes | one of: cash, mobile_money, card, other |
| notes | string | No | - |
| items | array | Yes | Array of sale items (see below) |

**Each item:**
| Field | Type | Required | Rules |
|-------|------|----------|-------|
| product_id | integer | Yes | FK to products |
| quantity | integer | Yes | > 0 |
| unit_price | number | Yes | Price at time of sale |
| discount_amount | number | No | default 0 |

**Response (201):** Sale resource with items

**Side effects (auto, no additional API calls needed):**
1. Sale items created with product_name/product_price snapshots
2. Product stock_quantity decreased
3. StockMovement created with type 'sale' and before/after snapshots
4. Receipt number generated

---

### GET `/sales/daily`
**Auth:** Required  
**Description:** Get daily sales report.

**Query params:** `?date=2026-06-02` (defaults to today)

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "receipt_number": "my-shop-0001",
      "total_amount": "3000.00",
      "payment_method": "cash",
      "user_id": 1,
      "sale_date": "2026-06-02T12:00:00.000000Z"
    }
  ],
  "meta": {
    "total_revenue": "3000.00",
    "total_sales": 1,
    "total_items_sold": 1,
    "total_cash": "3000.00",
    "total_mobile_money": "0.00",
    "total_card": "0.00"
  }
}
```

**Frontend notes:**
- `meta` contains computed aggregates for the day
- Used for the Dashboard daily stats

---

### GET `/sales/{id}`
**Auth:** Required  
**Description:** Get single sale with items (for receipt printing).

**Response (200):** Sale resource with items loaded

---

### POST `/sales/{id}/refund`
**Auth:** Required (requires `sales.refund` permission)  
**Description:** Process a refund on a sale.

**Request Body:**
| Field | Type | Required | Rules |
|-------|------|----------|-------|
| items | array | Yes | Array of {id, quantity, amount} |
| notes | string | No | - |

**Response (200):** Updated sale with refunded amounts

**Side effects:**
1. `sale_items.refunded_quantity` and `refunded_amount` updated
2. `sales.payment_status` set to `partially_refunded` or `refunded`
3. StockMovement created with type 'return'

---

### DELETE `/sales/{id}`
**Auth:** Required  
**Description:** Soft-delete a sale.  
**Response:** 204

---

## 11. Sale Items

### GET `/sale-items?sale_id={id}`
**Auth:** Required  
**Description:** List items for a specific sale.

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "sale_id": 1,
      "product_id": 1,
      "product_name": "Coca Cola 500ml",
      "product_price": "3000.00",
      "quantity": 2,
      "unit_price": "3000.00",
      "subtotal": "6000.00",
      "tax_amount": "0.00",
      "discount_amount": "0.00",
      "refunded_quantity": 0,
      "refunded_amount": "0.00",
      "created_at": "2026-06-02T12:00:00.000000Z",
      "updated_at": "2026-06-02T12:00:00.000000Z"
    }
  ]
}
```

**Frontend notes:**
- `product_name` and `product_price` are snapshots frozen at sale time — they don't change if the product is later edited
- `refunded_quantity` tracks partial refunds per line item
- Use `product_name` for display on receipts (not a live product lookup)

---

### POST `/sale-items`
**Auth:** Required  
**Description:** Create a sale item (usually done via Sale creation, but available standalone).

**Request Body:**
| Field | Type | Required | Rules |
|-------|------|----------|-------|
| sale_id | integer | Yes | - |
| product_id | integer | Yes | - |
| quantity | integer | Yes | > 0 |
| unit_price | number | Yes | - |

### PUT/DELETE `/sale-items/{id}`
Standard CRUD.

---

## 12. Stock Movements

### GET `/stock-movements`
**Auth:** Required  
**Query params:** `?product_id=1`, `?type=purchase`

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "business_id": 1,
      "product_id": 1,
      "sale_item_id": null,
      "type": "purchase",
      "quantity_change": 100,
      "stock_before": 50,
      "stock_after": 150,
      "reference": "PO-001",
      "notes": "Restock from supplier",
      "created_by": 1,
      "created_at": "2026-06-02T12:00:00.000000Z",
      "updated_at": "2026-06-02T12:00:00.000000Z"
    }
  ]
}
```

**Frontend notes:**
- This is the audit trail. Every stock change is recorded with before/after snapshots
- `type` values: `purchase` (+), `sale` (-), `adjustment` (+/-), `return` (+), `initial` (+)
- Sale movements are created automatically during checkout
- Use this to display stock history on the product detail page

---

### POST `/stock-movements`
**Auth:** Required  
**Description:** Create a manual stock adjustment (e.g. purchase, adjustment, initial stock).

**Request Body:**
| Field | Type | Required | Rules |
|-------|------|----------|-------|
| product_id | integer | Yes | - |
| type | string | Yes | one of: purchase, adjustment, return, initial |
| quantity_change | integer | Yes | positive for additions, negative for reductions |
| reference | string | No | e.g. PO number |
| notes | string | No | Reason for adjustment |

**Response (201):** StockMovement resource

**Side effect:** Product `stock_quantity` is updated accordingly.

---

## 13. Subscriptions

### GET `/subscriptions`
**Auth:** Required  
**Description:** Get the current business's subscription.

**Response (200):**
```json
{
  "data": {
    "id": 1,
    "business_id": 1,
    "plan_id": 1,
    "status": "active",
    "starts_at": "2026-06-02T12:00:00.000000Z",
    "trial_ends_at": null,
    "ends_at": null,
    "cancelled_at": null,
    "plan": {
      "data": {
        "id": 1,
        "name": "Free",
        "slug": "free",
        "price_monthly": "0.00",
        "features": { "expenses": false, ... },
        "limits": { "staff_users": 1, ... }
      }
    },
    "created_at": "2026-06-02T12:00:00.000000Z",
    "updated_at": "2026-06-02T12:00:00.000000Z"
  }
}
```

**Frontend notes:**
- Each business has one subscription (enforced by unique constraint)
- Load subscription + plan on app init to determine feature availability
- Use `plan.features` to conditionally show/hide features (Expenses, Shift Tracking, etc.)
- Use `plan.limits` to enforce max counts on the frontend

---

### POST `/subscriptions`
**Auth:** Required  
**Description:** Create a subscription for the business.

**Request Body:**
| Field | Type | Required | Rules |
|-------|------|----------|-------|
| plan_id | integer | Yes | FK to plans |
| status | string | No | default 'active' |
| trial_ends_at | string | No | ISO datetime |

**Response (201):** Subscription resource

---

### PUT `/subscriptions/upgrade`
**Auth:** Required  
**Description:** Change the business's plan.

**Request Body:**
| Field | Type | Required | Rules |
|-------|------|----------|-------|
| plan_id | integer | Yes | New plan ID |

**Response (200):** Updated subscription resource

---

### POST `/subscriptions/cancel`
**Auth:** Required  
**Description:** Cancel the subscription.

**Response (200):** Subscription with `status: "cancelled"` and `cancelled_at` set

---

## 14. Expense Categories

### GET `/expense-categories`
**Auth:** Required  

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "business_id": 1,
      "name": "Rent",
      "description": "Shop rent payments",
      "sort_order": 0,
      "created_at": "2026-06-02T12:00:00.000000Z",
      "updated_at": "2026-06-02T12:00:00.000000Z"
    }
  ]
}
```

Standard CRUD: POST, GET, PUT, DELETE `/expense-categories/{id}`

---

## 15. Expenses

### GET `/expenses`
**Auth:** Required  
**Query params:** `?category_id=1`, `?date=2026-06-02`

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "business_id": 1,
      "expense_category_id": 1,
      "recorded_by": 1,
      "amount": "500000.00",
      "description": "Monthly shop rent",
      "reference": "REC-001",
      "expense_date": "2026-06-01T00:00:00.000000Z",
      "category": {
        "data": { "id": 1, "name": "Rent" }
      },
      "created_at": "2026-06-02T12:00:00.000000Z",
      "updated_at": "2026-06-02T12:00:00.000000Z"
    }
  ]
}
```

**Frontend notes:**
- Expenses are used to calculate Net Sales: `Net = Revenue - Expenses`
- Display on Dashboard for Pro+ plans

---

### POST `/expenses`
**Auth:** Required  
**Description:** Record a new expense.

**Request Body:**
| Field | Type | Required | Rules |
|-------|------|----------|-------|
| expense_category_id | integer | No | FK to expense_categories |
| amount | number | Yes | > 0 |
| description | string | Yes | - |
| reference | string | No | e.g. invoice number |
| expense_date | string | Yes | ISO datetime |

**Response (201):** Expense resource

### GET/PUT/DELETE `/expenses/{id}`
Standard CRUD.

---

## 16. Sync (Electron Desktop)

### POST `/sync/push`
**Auth:** Required  
**Description:** Push local changes from the desktop app to the cloud server.

**Request Body:**
```json
{
  "categories": [
    { "id": 1, "name": "Beverages", "description": "Drinks" }
  ],
  "products": [
    { "id": 1, "name": "Coke", "unit_price": 3000, "stock_quantity": 50 }
  ],
  "customers": [...],
  "expenses": [...],
  "sales": [...],
  "sale_items": [...],
  "stock_movements": [...]
}
```

**Response (200):**
```json
{
  "imported": {
    "categories": 5,
    "products": 10,
    "customers": 3,
    "expenses": 0,
    "sales": 8,
    "sale_items": 15,
    "stock_movements": 8
  },
  "synced_at": "2026-06-02T12:00:00.000000Z"
}
```

**Frontend notes:**
- Send all changed records since last sync in one request
- Sales matched by `receipt_number` to avoid duplicates
- Stock movements are append-only (not matched)

---

### GET `/sync/pull?since=2026-06-01T00:00:00Z`
**Auth:** Required  
**Description:** Pull changes from the cloud that occurred after the given timestamp.

**Query params:**
| Param | Required | Description |
|-------|----------|-------------|
| since | No | ISO datetime. Omit for all data |

**Response (200):**
```json
{
  "categories": [...],
  "products": [...],
  "customers": [...],
  "expense_categories": [...],
  "expenses": [...],
  "roles": [...],
  "shifts": [...],
  "sales": [...],
  "sale_items": [...],
  "stock_movements": [...],
  "users": [...],
  "synced_at": "2026-06-02T12:00:00.000000Z"
}
```

**Frontend notes:**
- Use for periodic background sync (every 30s or on key actions)
- Only records with `updated_at > since` are returned
- Store `synced_at` locally and send it next time

---

### GET `/sync/full`
**Auth:** Required  
**Description:** Full data dump for first-time setup (seeding the local SQLite database).

**Response (200):** Same structure as pull but returns ALL data for the business.

**Frontend notes:**
- Call ONCE when user first logs in on a new device
- Use to populate the local SQLite database
- After initial full sync, use `/sync/pull?since=` for delta updates

---

## Error Reference

### 401 Unauthenticated
```json
{
  "message": "Unauthenticated."
}
```
**When:** Missing `Authorization` header, expired token, or invalid token.

### 422 Validation Error
```json
{
  "message": "The name field is required. (and 2 more errors)",
  "errors": {
    "name": ["The name field is required."],
    "email": ["The email has already been taken."],
    "password": ["The password must be at least 6 characters."]
  }
}
```
**When:** Required fields missing, format invalid, or unique constraint violated.

### 404 Not Found
```json
{
  "message": "Plan not found"
}
```
**When:** Resource ID doesn't exist.

### 409 Conflict
```json
{
  "message": "You already have an active shift. Clock out first."
}
```
**When:** Business rule violation (e.g. clocking in twice).

### 500 Server Error
```json
{
  "message": "Server Error"
}
```
**When:** Unexpected exception. Check Laravel logs.

---

## Quick Reference: Auth Token Usage

```javascript
// Login → store token
const login = async (email, password) => {
  const res = await fetch('/api/v1/auth/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email, password })
  });
  const data = await res.json();
  localStorage.setItem('token', data.token);
  return data.user;
};

// All authenticated requests
const api = (path, options = {}) => {
  const token = localStorage.getItem('token');
  return fetch(`/api/v1${path}`, {
    ...options,
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${token}`,
      ...options.headers,
    },
  });
};

// Usage
const products = await api('/products').then(r => r.json());
const sale = await api('/sales', {
  method: 'POST',
  body: JSON.stringify(saleData),
}).then(r => r.json());
```
