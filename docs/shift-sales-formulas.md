# Shift & Dashboard Sales Formulas (Canonical)

> Scope: shift reconciliation, cash drawer handover, and dashboard day metrics.
> This is the single source of truth - the backend (`ComputesShiftMetrics`,
> `ComputesSaleMetrics`, `ComputesDashboardAndVat`) and the frontend
> (`accounting.ts`, `buildShiftCloseReportData.ts`, My Shift page) must both
> implement exactly these formulas. If they drift, this document wins.

## The variables

| Variable | Formula | Notes |
|----------|---------|-------|
| `gross_sales` | Σ `sale.total_amount` | All payment types combined |
| `refunds` | Σ `sale_items.refunded_amount` | Line-item refunds are authoritative (header `refunds` is stale after offline refunds) |
| `shift_expenses` | Σ `expense.amount` where `shift_id = X` | Expenses are paid from the cash drawer |
| `cash_receipts` | Σ collected on **cash** sales | Each cash sale contributes what was actually collected (see Partial payments below) |
| `mobile_receipts` | Σ collected on **mobile_money** sales | |
| `card_receipts` | Σ collected on **card/other** sales | |
| `net_after_refunds` | `gross_sales − refunds` | Per receipt / sales headline |
| `net_sales` | `gross_sales − refunds − shift_expenses` | Canonical, used on dashboard + shift report |
| `cash_collected` | `cash_receipts − expenses` | Net cash actually taken in (refunds already netted per sale) |
| `cash_at_handover` | `opening_balance + cash_collected` | **The expected cash in the drawer at close** |
| `variance` | `counted_cash − cash_at_handover` | Positive = over, negative = short |

> **Partial payments.** A sale that is not fully paid only contributes what was
> actually collected, capped at its net: `collected = min(amount_paid, net)` when
> `payment_status !== 'paid'`, else `net`. This keeps cash in the drawer honest -
> a UGX 100k sale with only 40k paid counts 40k in `cash`, not 100k. The backend
> `saleCollected()` and the frontend `collectionsForSale()` implement the same rule.
>
> **Partial example:** cash sale worth 100k, only 40k paid → `cash_receipts`
> contributes 40k. If the same sale also had 20k refunded, net is 80k but only
> 50k was paid → contributes 50k (min of paid and net).

> Naming rule: **"Net Sales"** means `gross − refunds − expenses` across ALL payment
> types (cash + mobile + card). It is **not** "cash collected". The My Shift page
> label must be **"Net Sales"**, never "Net sales (cash collected)".

## Worked example (the numbers we test against)

One shift, mixed payments:

- Opening balance (float at clock-in): **UGX 50,000**
- Sale 1  -  **cash** `total_amount = 100,000`, no refunds
- Sale 2  -  **mobile_money** `total_amount = 80,000`, no refunds
- Sale 3  -  **cash** `total_amount = 60,000`, one line item refunded `20,000`
- Sale 4  -  **card** `total_amount = 40,000`, no refunds
- Shift expenses recorded on the shift: **UGX 15,000**
- Counted cash at close: **UGX 180,000**

### Computed

| Metric | Step | Result |
|--------|------|--------|
| `gross_sales` | 100k + 80k + 60k + 40k | **280,000** |
| `refunds` | 20k (only from the cash sale) | **20,000** |
| `shift_expenses` |  -  | **15,000** |
| `cash_receipts` | (100k − 0) + (60k − 20k) = 100k + 40k | **140,000** |
| `mobile_receipts` | 80k − 0 | **80,000** |
| `card_receipts` | 40k − 0 | **40,000** |
| `net_after_refunds` | 280k − 20k | **260,000** |
| `net_sales` | 280k − 20k − 15k | **245,000** |
| `cash_collected` | 140k − 15k | **125,000** |
| `cash_at_handover` | 50k + 125k | **175,000** |
| `variance` | 180k − 175k | **+5,000 (over)** |

### Why refunds are netted per-sale (not subtracted again)

`cash_receipts` is already net of refunds because each cash sale contributes
`total_amount − its refunded_amount`. If we also subtracted the refund total from
cash again, we would double-count. `refunds` is used only once, in `net_sales`
(`gross − refunds − expenses`).

## Dashboard (date-scoped, no shift)

The dashboard is shift-agnostic: it uses `dayMetrics(businessId, date)` which
sums sales and expenses **by calendar date** (`sale_date`, `expense_date`), then
applies the same `net_sales = gross − refunds − expenses`. No `cash_at_handover`
or `variance` exists on the dashboard (no drawer). The dashboard fields:
`today_net_sales`, `today_net_after_refunds`, `today_expenses`, `today_refunds`
all derive from this single formula.

### Dashboard example (single day)

- Cash sale 100k, mobile sale 80k, cash sale 60k with 20k refund, card 40k
- Expenses that day (any shift): 15k

| Metric | Result |
|--------|--------|
| `today_gross_sales` | **280,000** |
| `today_refunds` | **20,000** |
| `today_net_after_refunds` | **260,000** |
| `today_expenses` | **15,000** |
| `today_net_sales` | **245,000** |

Identical arithmetic to the shift - only the scoping differs (date vs shift).

## Implementation contract

Backend (already largely correct):
- `ComputesShiftMetrics::shiftReconciliation`  -  `cash` per-sale net-after-refunds ✓,
  `net_sales` ✓. **Fix:** `cash_handover` must become `opening_balance + cash_collected`
  (currently `cash − expenses`, i.e. missing the opening balance).
- `ComputesSaleMetrics::dayMetrics`  -  `net_sales = gross − refunds − expenses` ✓.

Frontend (needs fixes):
- `computeShiftCollections` must net refunds from cash per sale (currently sums
  gross payment rows when shift payments exist, which inflates cash).
- `buildShiftCloseReportData` / My Shift page / End Shift modal must use
  `cash_collected` and `cash_at_handover = opening_balance + cash_collected`.
- Label: **"Net Sales"** (drop "(cash collected)").
