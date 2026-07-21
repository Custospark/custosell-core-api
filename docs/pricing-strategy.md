# Custosell — Pricing Strategy

**Date:** 2026-07-21  
**Author:** Mike (Orchestrator) — with input from Sales, Marketing, CEO, and Product Engineering perspectives  
**Status:** Draft — awaiting Oscar's feedback before implementation

---

## 1. Product Positioning

Custosell is not "just a POS" — it is a **Business Operating System** for Ugandan and African SMEs. It competes with:

- **Local POS-only solutions** (UGX 100k–200k/month) — narrow feature set
- **International ERPs** (QuickBooks $15–$100/mo, Zoho $0–$30/mo, Sage 50 $50–$100/mo) — expensive, no offline, no local compliance
- **Manual/book entry** — free but inefficient, error-prone, no analytics

Custosell's edge: offline-first, all-in-one (POS + Accounting + HR + CRM + e-commerce + Inventory), EFRIS-compliant, built for Uganda.

---

## 2. Module Inventory (Complete Feature Map)

| # | Module | Slug | Description |
|---|--------|------|-------------|
| 1 | POS / Sales | `sales` | Cart, orders, refunds, receipts, shift management, EFRIS fiscalization, offline completion |
| 2 | Customer Management | `customers` | CRM database, purchase history, balance tracking, offline creation |
| 3 | Products & Inventory | `inventory` | Products, services, categories (up to tier limit), stock ledger, barcodes, low-stock alerts |
| 4 | Expenses | `expenses` | Expense recording, categories, receipt upload, input VAT, offline recording |
| 5 | Dashboard & Reports | `dashboard` | KPI dashboards, sales charts, VAT summaries, report exports (PDF/CSV/XLSX) |
| 6 | Invoicing | `invoices` | Sales & supplier invoices, line items, PDF/email, payment recording |
| 7 | Sales Pipeline / CRM | `pipeline` | Kanban boards, leads, activities, reminders, automation, public booking, insights |
| 8 | Estimates & Projects | `estimates` | Quotes, project management, tasks, timesheets, budget tracking, profitability, templates |
| 9 | Purchase Orders & Supply | — | PO management, incoming orders, supplier management |
| 10 | B2B Marketplace | `marketplace` | Supplier discovery, shortlisting, supply-side presence |
| 11 | Storefront / E-commerce | `storefront` | Public shop, multi-business cart, customer orders, wishlist, QR sharing |
| 12 | Document Management | `documents` | Cabinets, folders, ACL, tags, cross-module linking, search |
| 13 | Accounting (Double-Entry) | `accounting` | COA, journals, trial balance, P&L, balance sheet, ratios, fixed assets, period close, financial statements |
| 14 | HR & Payroll | `hr` | Employees, attendance, leave, payroll (Uganda-compliant), talent/recruitment, reports |
| 15 | Financial Forecasting | `forecasting` | Budgets (BvA), KPIs, what-if scenarios, cash runway projections |
| 16 | Roles & Permissions | `settings` | Role-based access control, per-module staff access toggles |
| 17 | Multi-Business / Multi-Location | — | Multiple businesses under one account with separate data |
| 18 | Platform Admin | `platform` | Tenant management, user management, platform-wide analytics |

---

## 3. Tier Structure: Three Plans

### Essential — *"Get Started"*

**Target customer:** Small retail shops, roadside stores, market vendors, single-location businesses  
**Value prop:** Professional POS at a price any shop can afford. Everything you need to ring up sales and track customers.

| Feature | Limit / Detail |
|---------|----------------|
| POS / Sales | Unlimited transactions |
| Customer Management | Full CRM |
| Products & Categories | Up to **500 products** |
| Basic Stock Management | Stock adjustments, low-stock view |
| Expenses | Full expense recording |
| Shift Management | Clock-in/out, shift totals |
| Dashboard & Basic Reports | KPI overview, sales charts |
| Receipt Printing & PDF | Thermal + PDF receipts |
| Offline-First | POS, customers, products, expenses work offline |
| **Staff/Users** | **Up to 3** |
| **Businesses/Locations** | **1** |
| Support | Email (48 hr response) |

| Pricing | UGX | USD |
|---------|-----|-----|
| **Monthly** | **75,000** | **$20** |
| **Yearly** (pay 10, get 12) | **750,000** | **$200** |
| **Onboarding Fee** (one-time) | **150,000** | **$40** |

**Annual commitment saves:** UGX 150,000 ($40) — effectively 2 months free.

---

### Professional — *"Grow Your Business"*

**Target customer:** Growing businesses, wholesalers, restaurants, mid-size operations  
**Value prop:** Full operations suite — inventory, invoices, pipeline, e-commerce, and team management under one roof.

| Feature | Limit / Detail |
|---------|----------------|
| Everything in Essential | ✅ |
| Products & Categories | Up to **5,000 products** |
| Purchase Orders & Supply Chain | PO management, incoming orders, supplier invoices |
| B2B Marketplace | Supplier discovery + shortlisting |
| Sales Pipeline / CRM | Full boards, leads, automation, insights, public booking |
| Estimates & Projects | Quotes, projects, tasks, timesheets, templates |
| Invoicing | Sales + supplier invoices, PDF/email |
| Storefront / E-commerce | Public shop, customer orders, wishlist, QR |
| Document Management | Cabinets, folders, ACL |
| EFRIS Fiscalization | URA-compliant receipts |
| Full Role-Based Access Control | Custom roles, per-module access |
| Advanced Reporting | Exports, filterable reports, VAT summaries |
| Data Export | PDF / CSV / XLSX |
| **Staff/Users** | **Up to 20** |
| **Businesses/Locations** | **1** |
| Support | Email + In-app Chat (24 hr response) |

| Pricing | UGX | USD |
|---------|-----|-----|
| **Monthly** | **200,000** | **$54** |
| **Yearly** (pay 10, get 12) | **2,000,000** | **$540** |
| **Onboarding Fee** (one-time) | **350,000** | **$95** |

**Annual commitment saves:** UGX 400,000 ($108).

---

### Enterprise — *"Command Your Empire"*

**Target customer:** Chains, multi-location operations, large distributors, franchise networks  
**Value prop:** The complete ERP. Accounting, HR, payroll, forecasting, and multi-location management with white-glove support.

| Feature | Limit / Detail |
|---------|----------------|
| Everything in Professional | ✅ |
| Products | **Unlimited** |
| Full Accounting (Double-Entry) | COA, journals, trial balance, P&L, balance sheet, cash flow, equity statement, ratios, fixed assets, period close, inventory reconciliation |
| HR & Payroll | Full suite: employees, attendance, leave, Uganda-compliant payroll, talent, reports, payroll-accounting bridge |
| Financial Forecasting | Budgets (BvA), KPIs, what-if scenarios, cash runway |
| Multi-Business / Multi-Location | Up to **5 businesses/locations** under one account |
| Platform Admin Visibility | Global reporting across all locations |
| Custom Integrations | API access, webhook support, custom connector development |
| **Staff/Users** | **Unlimited** |
| **Businesses/Locations** | **Up to 5** |
| Support | Phone + Chat + Dedicated Account Manager (4 hr response) |

| Pricing | UGX | USD |
|---------|-----|-----|
| **Monthly** | **500,000** | **$135** |
| **Yearly** (pay 10, get 12) | **5,000,000** | **$1,350** |
| **Onboarding Fee** (one-time) | **750,000** | **$200** |

**Annual commitment saves:** UGX 1,000,000 ($270).

---

## 4. Pricing Rationale by Discipline

### 👔 CEO Perspective

- **Recurring revenue:** Three tiers create a predictable revenue funnel. Essential is the volume driver, Professional is the margin engine, Enterprise captures flagship clients.
- **Onboarding fees are mandatory:** They cover activation cost (account setup, product configuration, staff training material) and create financial commitment from the customer — reducing churn.
- **Yearly discounts:** Pay-10-get-12 model drives annual commitment, improves cash flow predictability, and reduces billing overhead.
- **Upgrade path:** Each tier naturally exposes features the next tier has — small shops grow into Professional when they need pipeline and invoicing; growing businesses grow into Enterprise when they need accounting and multi-location.

### 📈 Sales Perspective

- **Essential at UGX 75k/month is a no-brainer.** That's UGX 2,500/day — less than a lunch in Kampala. No shop owner thinks twice about that. The onboarding fee (150k) is roughly the cost of a single bulk sale — recouped immediately.
- **Professional (UGX 200k) is the sweet spot.** It's 2.7x Essential but unlocks 10x the value (pipeline, invoicing, storefront, PO management, full CRM). The jump feels justified.
- **Enterprise (UGX 500k) prices for value, not cost.** A multi-location business doing UGX 50M+/month in revenue will happily pay 1% for their entire operating system. The onboarding fee (750k) is less than a single day of lost productivity from system downtime.
- **Onboarding fee as a qualification tool:** If a prospect hesitates at a one-time 150k fee, they're likely not serious. It filters out tire-kickers.

### 🎯 Marketing Perspective

- **Positioning:** "Your Business Operating System" — not "POS software." We're competing with QuickBooks, not just the local POS guy.
- **Early Access launch strategy:** Current "Coming Soon" / "Free for all" phase is grandfather opportunity. First 100-200 businesses get locked-in founder pricing (e.g., Essential at 50k/month for life). Creates urgency and a testimonial base.
- **Tier naming:** Essential → Professional → Enterprise is universally understood. No need to educate the market on clever names.
- **Price anchoring:** Display all three tiers together. Essential at 75k makes Professional at 200k feel reasonable. Professional at 200k makes Enterprise at 500k feel premium but justified.
- **UGX-first pricing for local, USD for diaspora/international.** Ugandan businesses think in UGX. Display UGX prominently; show USD as equivalent.

### 🛠️ Product Engineering Perspective

**What needs to change in the Plan entity:**

| Current Field | Issue | Proposed Change |
|---------------|-------|-----------------|
| `price_monthly` | Single currency, ambiguous | Rename to `price_monthly_ugx`, add `price_monthly_usd` |
| `price_yearly` | Single currency, ambiguous | Rename to `price_yearly_ugx`, add `price_yearly_usd` |
| *(missing)* | No onboarding fee | Add `onboarding_fee_ugx` and `onboarding_fee_usd` |
| *(missing)* | No trial configuration | Add `trial_days` (defaults to 14 for Essential, 7 for Pro/Enterprise) |
| `features` | `Record<string, boolean>` | Good — maps to module slugs. Keep as-is. |
| `limits` | `Record<string, number\|null>` | Good — stores max_staff, max_products, max_businesses, etc. Keep as-is. |
| *(missing)* | No billing cycle field | Add `billing_cycle` — `monthly`, `yearly`, or `both` |

**Feature-to-module mapping strategy:**

The `features` JSON column will store `Record<string, boolean>` where keys are **module slugs** from the frontend:

```json
{
  "sales": true,
  "customers": true,
  "inventory": true,
  "expenses": true,
  "dashboard": true,
  "pipeline": false,
  "estimates": false,
  "invoices": false,
  "storefront": false,
  "documents": false,
  "accounting": false,
  "hr": false,
  "forecasting": false,
  "marketplace": false
}
```

The `limits` JSON column will store numeric caps:

```json
{
  "max_staff": 3,
  "max_products": 500,
  "max_businesses": 1,
  "max_locations": 1,
  "storage_mb": 100
}
```

**Required migration:** A new migration that modifies the `plans` table to support dual-currency pricing and onboarding fees. The existing `price_monthly` and `price_yearly` columns get deprecated (or converted with a rollover migration).

---

## 5. Subscription Lifecycle (Future State)

When Pesapal integration is added later, the subscription lifecycle will mirror Custocare's pattern:

```
Registration → Free Trial (14 days) → Payment → Active
                                                      ↓
                                               Renewal (monthly/yearly)
                                                      ↓
                                      Cancellation → Ends at period end
                                      Failed payment → Past due (7-day grace)
                                                          ↓
                                                    Suspended (no access)
                                                          ↓
                                                    Cancelled after 30 days
```

---

## 6. Questions for Oscar

- Pricing numbers: too high, too low, or just right?
- Essential at UGX 75k — should there be a Free tier (e.g., 50 transactions/month, 1 user) to drive adoption?
- Onboarding fee structure — flat per tier, or negotiable for Enterprise?
- Feature grouping — any module you'd move to a different tier?
- Annual pricing structure — pay-10-get-12, or a different discount model (e.g., 15% off)?

---

## 7. Next Steps (Pending Approval)

1. ✅ Pricing strategy documented (this file)
2. Update Plan model and migration for dual-currency + onboarding fees
3. Seed Essential / Professional / Enterprise plans
4. Update Subscription entity for lifecycle statuses (trial, active, past_due, suspended, cancelled)
5. Implement Pesapal payment integration
6. Wire up frontend pricing page + subscription settings
7. Launch Early Access with founder pricing
