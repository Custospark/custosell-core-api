# Custosell — API Documentation Plan

**Goal:** Create a complete API reference that frontend (Electron + React) developers can build against without guessing. One source of truth for all request/response contracts.

---

## Format

Single markdown file: `docs/api-reference.md`

Structure per endpoint:

```
## [Module] — [Entity]

### [HTTP Method] `[URL]`
**Auth:** [required/optional]
**Plan gate:** [Free/Pro+/None]

**Request Body:**
| Field | Type | Required | Rules |
|-------|------|----------|-------|

**Response (200/201):**
```json
{ example response }
```

**Error Responses:**
| Status | When |
|--------|------|

**Notes:** [important context for frontend dev]
```

---

## Modules & Endpoints to Document

### 1. Authentication (6 endpoints)
| Method | Path | Purpose |
|--------|------|---------|
| POST | `/api/v1/auth/register` | Create account |
| POST | `/api/v1/auth/login` | Get token |
| POST | `/api/v1/auth/logout` | Revoke token |
| GET  | `/api/v1/auth/me` | Current user profile |

### 2. Plans (5 endpoints)
| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/v1/plans` | List all plans |
| POST | `/api/v1/plans` | Create plan (admin) |
| GET | `/api/v1/plans/{id}` | Get plan |
| PUT | `/api/v1/plans/{id}` | Update plan |
| DELETE | `/api/v1/plans/{id}` | Delete plan |

### 3. Businesses (7 endpoints)
| Method | Path | Purpose |
|--------|------|---------|
| POST | `/api/v1/businesses/register` | Register business + user |
| GET | `/api/v1/businesses/mine` | Get owner's business |
| GET | `/api/v1/businesses/{id}` | Get business |
| PUT | `/api/v1/businesses/{id}` | Update business |
| GET | `/api/v1/businesses/settings` | Get settings |
| PUT | `/api/v1/businesses/settings` | Update settings |

### 4. Roles (5 endpoints)
| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/v1/roles` | List roles |
| POST | `/api/v1/roles` | Create role |
| GET | `/api/v1/roles/{id}` | Get role |
| PUT | `/api/v1/roles/{id}` | Update role |
| DELETE | `/api/v1/roles/{id}` | Delete role |

### 5. Users / Staff (5 endpoints)
| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/v1/users` | List staff |
| POST | `/api/v1/users` | Create staff |
| GET | `/api/v1/users/{id}` | Get staff |
| DELETE | `/api/v1/users/{id}` | Remove staff |

### 6. Categories (5 endpoints)
Standard CRUD: `/api/v1/categories`

### 7. Products (7 endpoints)
| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/v1/products` | List (with filters) |
| POST | `/api/v1/products` | Create |
| GET | `/api/v1/products/{id}` | Get |
| PUT | `/api/v1/products/{id}` | Update |
| DELETE | `/api/v1/products/{id}` | Soft delete |
| GET | `/api/v1/products/low-stock` | Low stock alert list |
| GET | `/api/v1/products/{id}/stock-movements` | Stock ledger |

### 8. Customers (7 endpoints)
| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/v1/customers` | List |
| POST | `/api/v1/customers` | Create |
| GET | `/api/v1/customers/{id}` | Get |
| PUT | `/api/v1/customers/{id}` | Update |
| DELETE | `/api/v1/customers/{id}` | Delete |
| GET | `/api/v1/customers/{id}/purchases` | Purchase history |

### 9. Shifts (5 endpoints)
| Method | Path | Purpose |
|--------|------|---------|
| POST | `/api/v1/shifts/clock-in` | Start shift |
| POST | `/api/v1/shifts/{id}/clock-out` | End shift |
| GET | `/api/v1/shifts/active` | Current active shift |
| GET | `/api/v1/shifts` | Shift history |

### 10. Sales (9 endpoints)
| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/v1/sales` | List sales |
| POST | `/api/v1/sales` | Create sale (POS checkout) |
| GET | `/api/v1/sales/{id}` | Get sale with items |
| DELETE | `/api/v1/sales/{id}` | Delete sale |
| GET | `/api/v1/sales/daily` | Daily sales report |
| POST | `/api/v1/sales/{id}/refund` | Process refund |

### 11. Sale Items (5 endpoints)
Standard CRUD: `/api/v1/sale-items`

### 12. Stock Movements (2 endpoints)
| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/v1/stock-movements` | List all |
| POST | `/api/v1/stock-movements` | Create adjustment |

### 13. Subscriptions (5 endpoints)
| Method | Path | Purpose |
|--------|------|---------|
| GET | `/api/v1/subscriptions` | Current subscription |
| POST | `/api/v1/subscriptions` | Create |
| PUT | `/api/v1/subscriptions/upgrade` | Change plan |
| POST | `/api/v1/subscriptions/cancel` | Cancel |

### 14. Expense Categories (5 endpoints)
Standard CRUD: `/api/v1/expense-categories`

### 15. Expenses (5 endpoints)
Standard CRUD: `/api/v1/expenses`

### 16. Sync (3 endpoints)
| Method | Path | Purpose |
|--------|------|---------|
| POST | `/api/v1/sync/push` | Push local changes |
| GET | `/api/v1/sync/pull?since=` | Pull changes |
| GET | `/api/v1/sync/full` | First-time setup dump |

---

## Per-Endpoint Detail

Each endpoint will document:

| Section | What's Included |
|---------|----------------|
| **Auth** | Whether Bearer token is required |
| **Plan gate** | Free/Pro+/None (frontend should check plan features) |
| **Request body** | Table of fields: name, type, required, validation rules |
| **Response body** | JSON example of success response |
| **Error codes** | 401 (unauth), 403 (forbidden), 404, 422 (validation), 409 (conflict) |
| **Business scoping** | Note that all data is scoped to authenticated user's business |
| **Frontend notes** | Specific guidance (e.g. "send quantity as integer", "receipt_number is auto-generated") |

---

## Enum Values to Document

Frontend needs to know exact enum strings:

| Field | Values |
|-------|--------|
| `businesses.status` | `active`, `suspended` |
| `shifts.status` | `active`, `completed` |
| `subscriptions.status` | `active`, `trialing`, `cancelled`, `expired` |
| `sales.payment_method` | `cash`, `mobile_money`, `card`, `other` |
| `sales.payment_status` | `paid`, `partially_refunded`, `refunded` |
| `stock_movements.type` | `purchase`, `sale`, `adjustment`, `return`, `initial` |

---

## Pagination Format

All list endpoints return:

```json
{
  "data": [...],
  "links": { "first": "...", "last": "...", "prev": null, "next": "..." },
  "meta": { "current_page": 1, "from": 1, "last_page": 1, "path": "...", "per_page": 15, "to": 10, "total": 10 }
}
```

---

## Output

Single file: **`docs/api-reference.md`** (~80 endpoints documented)

---

Does this look good to you, Oscar? Approve and I'll generate the full reference.
