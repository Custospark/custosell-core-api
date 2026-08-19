# 02 - Dashboard & Reports

Know your numbers at a glance - daily, monthly, and per branch.

## Video: Know your numbers at a glance
- Format: 45-90s how-to
- Priority: P1
- Platforms: Reels / Shorts / TikTok / YouTube
- Tagline: "Know your numbers at a glance."
- Description: Read your Custosell dashboard - today's sales, profit, cash, and
  the KPIs that matter, all on one screen when you open the app.
- What it's about: Reading the main dashboard KPIs.
- Script beats:
  - Beat 1 (Hook): "Open the app and know everything."
  - Beat 2 (Problem): "Owners find out their numbers too late."
  - Beat 3 (Action): /dashboard -> read today's sales, profit, cash, top
    products -> drill into any KPI.
  - Beat 4 (Aha): "Everything from today's till, in one glance."
  - Beat 5 (CTA): "Try it free at custosell.com."
- Screen flow: /dashboard -> KPI cards -> drill down.
- On-screen text / captions:
  - "Today at a glance"
- Demo data needed: A business with sales today.
- CTA: Try free + subscribe
- Related videos: [04-shifts.md](./04-shifts.md) (day close)

## Video: Your top products (and dead ones)
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "Your top products and the ones killing you."
- Description: Use the product-performance report on Custosell to see your
  best sellers and the products that don't move - then stock smarter.
- What it's about: Product performance / sales-by-product report.
- Script beats:
  - Beat 1 (Hook): "Which product pays your rent? This report knows."
  - Beat 2 (Problem): "Guesswork stocking loses money."
  - Beat 3 (Action): /reports -> product performance -> sort by revenue ->
    spot the dead stock.
  - Beat 4 (Aha): "Buy what sells. Stop buying what doesn't."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /reports -> product performance -> sort/compare.
- On-screen text / captions:
  - "Sell more of what sells"
- Demo data needed: Products with varied sales history.
- CTA: Try free
- Related videos: [05-inventory-products.md](./05-inventory-products.md)

## Video: Compare your branches
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "Which branch is winning this week?"
- Description: Compare sales performance across your branches on Custosell and
  see where to push effort or move stock.
- What it's about: Branch performance comparison report.
- Script beats:
  - Beat 1 (Hook): "Two branches. One winner. This report tells you."
  - Beat 2 (Problem): "Running multiple shops blind is expensive."
  - Beat 3 (Action): /reports -> branch performance -> compare period ->
    highlight gap.
  - Beat 4 (Aha): "Now you know where to focus."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /reports -> branch performance -> compare.
- On-screen text / captions:
  - "Branch vs branch"
- Demo data needed: Two branches with different sales.
- CTA: Try free
- Related videos: [01-account-auth-security.md](./01-account-auth-security.md)

## Video: Month-end in one screen
- Format: 3-6min deep dive
- Priority: P3
- Platforms: YouTube
- Tagline: "Month-end in one screen - VAT summary and business summary."
- Description: The full month-end reports on Custosell - VAT summary and
  business summary report - everything your accountant and the taxman need,
  generated automatically.
- What it's about: VAT + business summary month-end reports.
- Script beats:
  - Beat 1 (Hook): "Month-end used to take a day. Now it takes a look."
  - Beat 2 (Problem): "Chasing receipts and totals for tax season."
  - Beat 3 (Action): /reports -> VAT summary -> business summary -> export.
  - Beat 4 (Aha): "Hand your accountant a clean file."
  - Beat 5 (CTA): "Try it free at custosell.com."
- Screen flow: /reports -> VAT summary -> business summary -> export/print.
- On-screen text / captions:
  - "Tax season, handled"
- Demo data needed: A month of full sales + expenses.
- CTA: Try free + subscribe
- Related videos: [09-accounting.md](./09-accounting.md)

## Video: Read the profit & loss line
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "Is the shop actually making money? The P&L tells you."
- Description: Open the profit & loss report on Custosell - revenue minus costs,
  a clear profit line - and understand what drives it.
- What it's about: Profit & loss report reading.
- Script beats:
  - Beat 1 (Hook): "Sales are high. Is profit?"
  - Beat 2 (Problem): "Revenue hides the real story."
  - Beat 3 (Action): /reports -> P&L -> read revenue, cost of goods, expenses,
    net profit.
  - Beat 4 (Aha): "Now you see the real line."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /reports -> P&L -> line items.
- On-screen text / captions:
  - "Sales vs profit - the real line"
- Demo data needed: Sales + expenses + COGS recorded.
- CTA: Try free
- Related videos: [08-expenses-budgets-income.md](./08-expenses-budgets-income.md)

---

## Technical reference (source of truth)

**Screens:** `/dashboard` [M], `/reports` [M] (sales, product performance,
branch performance, VAT, business summary, P&L)

**User actions (FE hooks):** `useDashboardKpis` · `useSalesReport` ·
`useProductPerformance` · `useBranchPerformance` · `useVatSummary` ·
`useBusinessSummary` · `useProfitLoss` · `useExportReport`

**API endpoints (BE):** `/dashboard/kpis` · `/reports/sales` ·
`/reports/product-performance` · `/reports/branch-performance` ·
`/reports/vat` · `/reports/business-summary` · `/reports/profit-loss` ·
`/reports/export`