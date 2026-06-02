# Custosell — Entity Documentation

## Plan - 2026-06-02 08:43:00

### Fields
- id: increments - Primary key
- name: string(100) - e.g. "Free", "Pro", "Premium"
- slug: string(100) - unique, e.g. "free", "pro", "premium"
- description: text - nullable, marketing copy
- price_monthly: decimal(10,2) - UGX
- price_yearly: decimal(10,2) - nullable, discounted yearly rate
- features: json - feature flags
- limits: json - numerical limits
- is_active: boolean - default true
- sort_order: integer - display order
- created_at: timestamp
- updated_at: timestamp

### Files Generated/Updated
- [x] Migration: `database/migrations/2026_06_02_084300_create_plans_table.php`
- [x] Model: `app/Models/Plan.php`
- [x] Repository Interface: `app/Repositories/Contracts/PlanRepositoryInterface.php`
- [x] Repository: `app/Repositories/Eloquent/PlanRepository.php`
- [x] Service Interface: `app/Services/Contracts/PlanServiceInterface.php`
- [x] Service: `app/Services/PlanService.php`
- [x] Request: `app/Http/Requests/PlanRequest.php`
- [x] Resource: `app/Http/Resources/PlanResource.php`
- [x] Collection: `app/Http/Resources/PlanCollection.php`
- [x] Controller: `app/Http/Controllers/Api/PlanController.php`
- [x] API Routes: `routes/api/v1/plans.php`
- [x] Registered in: `routes/api.php`
- [x] Provider: `app/Providers/PlanServiceProvider.php` + registered in `bootstrap/providers.php`
- [x] Seeder: `database/seeders/PlanSeeder.php`

### Provider Bindings
- `PlanRepositoryInterface` → `PlanRepository`
- `PlanServiceInterface` → `PlanService`

### API Endpoints
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/v1/plans` | List all plans |
| GET | `/api/v1/plans/{plan}` | Get one plan |
| POST | `/api/v1/plans` | Create plan |
| PUT | `/api/v1/plans/{plan}` | Update plan |
| DELETE | `/api/v1/plans/{plan}` | Delete plan |

### Seeded Data
- **Free** — UGX 0/mo, 1 staff, 50 products, 100 monthly sales
- **Pro** — UGX 30,000/mo, 5 staff, 1,000 products, unlimited sales
- **Premium** — UGX 100,000/mo, unlimited everything

### Test Results
- Lint: ✅ Passed
- Migration: ✅ Ran successfully

---

## User - 2026-06-02 08:47:37

### Fields
- id: bigIncrements - Primary key
- business_id: unsignedBigInteger - FK to businesses, nullable
- role_id: unsignedBigInteger - FK to roles, nullable
- name: string(255)
- email: string(255) - unique
- password: string(255) - hashed
- is_active: boolean - default true
- phone: string(50) - nullable
- created_by: unsignedBigInteger - FK to users, nullable
- email_verified_at: timestamp - nullable
- remember_token: string(100) - nullable
- timestamps
- deleted_at: soft deletes

### Files Generated/Updated
- [x] Migration: `database/migrations/2026_06_02_084737_add_custosell_fields_to_users_table.php`
- [x] Migration: `database/migrations/2026_06_02_090349_add_foreign_keys_to_users.php`
- [x] Model: `app/Models/User.php`
- [x] Repository Interface: `app/Repositories/Contracts/UserRepositoryInterface.php`
- [x] Repository: `app/Repositories/Eloquent/UserRepository.php`
- [x] Service Interface: `app/Services/Contracts/UserServiceInterface.php`
- [x] Service: `app/Services/UserService.php`
- [x] Request: `app/Http/Requests/RegisterRequest.php`
- [x] Request: `app/Http/Requests/LoginRequest.php`
- [x] Resource: `app/Http/Resources/UserResource.php`
- [x] Collection: `app/Http/Resources/UserCollection.php`
- [x] Controller: `app/Http/Controllers/Api/AuthController.php`
- [x] Controller: `app/Http/Controllers/Api/UserController.php`
- [x] API Routes: `routes/api/v1/users.php`
- [x] Registered in: `routes/api.php`
- [x] Provider: `app/Providers/UserServiceProvider.php` + registered in `bootstrap/providers.php`

### API Endpoints
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/v1/auth/register` | Register user |
| POST | `/api/v1/auth/login` | Login |
| POST | `/api/v1/auth/logout` | Logout (auth) |
| GET | `/api/v1/auth/me` | Current user (auth) |
| GET | `/api/v1/users` | List users (auth) |
| POST | `/api/v1/users` | Create staff (auth) |
| GET | `/api/v1/users/{user}` | Get user (auth) |
| DELETE | `/api/v1/users/{user}` | Delete user (auth) |

---

## Business - 2026-06-02 08:57:01

### Fields
- id: bigIncrements
- owner_id: unsignedBigInteger - FK to users
- name: string(255)
- slug: string(255) - unique
- email: string(255) - nullable
- phone: string(50) - nullable
- address: text - nullable
- currency: string(10) - default 'UGX'
- receipt_footer: text - nullable
- logo_path: string(255) - nullable
- status: enum('active','suspended') - default 'active'
- trial_ends_at: datetime - nullable
- timestamps
- deleted_at: soft deletes

---

## Role - 2026-06-02 09:03:17

### Fields
- id: bigIncrements
- business_id: unsignedBigInteger - FK to businesses, nullable
- name: string(100)
- slug: string(100)
- description: text - nullable
- permissions: json
- is_default: boolean
- timestamps

### Seeded Roles per Business
- **Admin** — all 23 permissions true
- **Staff** — sales.create, sales.view, inventory.view, customers.view, customers.create only

---

## Category - 2026-06-02 09:05:24

### Fields
- id, business_id (FK), name, description (nullable), sort_order, timestamps

---

## Product - 2026-06-02 09:05:25

### Fields
- id, business_id (FK), category_id (FK, nullable), name, description (nullable), sku (nullable, unique per business), barcode (nullable), unit_price, cost_price (nullable), stock_quantity, low_stock_threshold, tax_percentage, is_active, timestamps, soft_deletes

---

## Customer - 2026-06-02 09:05:27

### Fields
- id, business_id (FK), name, phone, email (nullable), total_purchases, last_purchase_at (nullable), timestamps

---

## Shift - 2026-06-02 09:05:28

### Fields
- id, business_id (FK), user_id (FK), clock_in, clock_out (nullable), total_sales, total_cash, total_mobile_money, total_card, status (enum: active/completed), notes (nullable), timestamps

---

## Sale - 2026-06-02 09:05:29

### Fields
- id, business_id (FK), user_id (FK), customer_id (FK, nullable), shift_id (FK, nullable), receipt_number (unique per business), subtotal, tax_total, discount_amount, total_amount, payment_method (enum: cash/mobile_money/card/other), payment_status (enum: paid/partially_refunded/refunded), notes (nullable), sale_date, timestamps, soft_deletes

---

## SaleItem - 2026-06-02 09:05:30

### Fields
- id, sale_id (FK), product_id (FK, nullable), product_name (snapshot), product_price (snapshot), quantity, unit_price, subtotal, tax_amount, discount_amount, refunded_quantity, refunded_amount, timestamps

---

## StockMovement - 2026-06-02 09:05:32

### Fields
- id, business_id (FK), product_id (FK), sale_item_id (FK, nullable), type (enum: purchase/sale/adjustment/return/initial), quantity_change, stock_before, stock_after, reference (nullable), notes (nullable), created_by (FK, nullable), timestamps

---

## Subscription - 2026-06-02 09:05:33

### Fields
- id, business_id (FK, unique), plan_id (FK), status (enum: active/trialing/cancelled/expired), starts_at, trial_ends_at (nullable), ends_at (nullable), cancelled_at (nullable), timestamps

---

## ExpenseCategory - 2026-06-02 09:05:34

### Fields
- id, business_id (FK), name, description (nullable), sort_order, timestamps

---

## Expense - 2026-06-02 09:05:36

### Fields
- id, business_id (FK), expense_category_id (FK, nullable), recorded_by (FK, nullable), amount, description, reference (nullable), expense_date, timestamps, soft_deletes

---

## Sync API - 2026-06-02

### Endpoints
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | `/api/v1/sync/push` | Push local changes to cloud |
| GET | `/api/v1/sync/pull?since=` | Pull changes since timestamp |
| GET | `/api/v1/sync/full` | Full data dump for first-time setup |

### Architecture
- Desktop (Electron + SQLite) = source of truth
- Cloud (Laravel + MySQL) = backup/sync hub
- Auto-sync on reconnect + periodic background
- Delta sync via `updated_at` timestamps
- Receipt numbers: `{business_slug}-{increment}`

---

## SOLID Compliance
- [x] Single Responsibility - Controller (HTTP), Service (business), Repository (data)
- [x] Open/Closed - All repos/services have interfaces
- [x] Liskov Substitution - Any impl can replace interface
- [x] Interface Segregation - Split repo/service interfaces
- [x] Dependency Inversion - Providers bind interfaces, never concretions
