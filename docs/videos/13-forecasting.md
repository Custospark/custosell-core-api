## Video: Read your cash runway and burn
- Format: 45-90s how-to
- Priority: P1
- Platforms: Reels / Shorts / TikTok / YouTube
- Tagline: "How many months can you pay everyone?"
- Description: Open the forecasting overview to see cash available, unpaid
  payroll, monthly burn, and cash runway - with a month-by-month cash ladder
  that shows exactly when you could go short.
- What it's about: ForecastingOverviewPage - cash/burn/runway KPI cards and
  the CashMonthsTable (opening, inflows, payroll, opex, net, closing, cover).
- Script beats:
  - Beat 1 (Hook): "One screen. Your money, month by month."
  - Beat 2 (Problem): "You find out you're short the month you run out."
  - Beat 3 (Action): /forecasting -> pick the horizon (3-24 months) -> read
    Cash available, Monthly burn, Cash runway -> scan the cash ladder table.
  - Beat 4 (Aha): "The Cover column shows every month you can still pay."
  - Beat 5 (CTA): "Try it free at custosell.com."
- Screen flow: /forecasting -> horizon selector -> KPI cards -> cash ladder.
- On-screen text / captions:
  - "Runway: months of cash, visible"
- Demo data needed: An open accounting period, sales history, and payroll.
- CTA: Try free + subscribe
- Related videos: [02-dashboard-reports.md](./02-dashboard-reports.md)

- Published title: How many months can you pay everyone?
- Short-form variant: How many months can you pay everyone?
- Published description: Open the forecasting overview to see cash available, unpaid payroll, monthly burn, and cash runway - with a month-by-month cash ladder that shows exactly when you could go short. Try free + subscribe #custosell #forecasting #budget #cashrunway #Custosell #SmallBusiness
- Tags: custosell, forecasting, budget, cash runway, scenarios, kpi, projections, how to, tutorial, walkthrough, tips
- Video assets:
  - Thumbnail: bold text "Runway: months of cash, visible" over a bright screenshot of the action
  - Screen-record clips: the Screen flow steps, trimmed to the script beats
  - Captions / subtitles: full on-screen captions exported as a caption file
  - Brand overlay / watermark: Custosell logo, corner placement
  - Music: royalty-free background bed, low volume under the voiceover

## Video: Compare budget vs actual spend
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "Every category, planned vs spent."
- Description: See each expense category's budget against what you actually
  spent, with variance percentages and an over/under/on-track status so you
  catch overspend early.
- What it's about: The budget vs actual panel on the overview (and the
  useBudgetVsActual API) - category rows with actual/budget, variance pct, and
  BvaStatusBadge.
- Script beats:
  - Beat 1 (Hook): "Which category is eating your margin?"
  - Beat 2 (Problem): "Budgets on a paper nobody compares to reality."
  - Beat 3 (Action): /forecasting -> Budget vs actual panel -> read the
    variance per category -> open budgets for the detail.
  - Beat 4 (Aha): "Red variance means overspend - today, not at year end."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /forecasting -> budget vs actual -> budgets.
- On-screen text / captions:
  - "Planned vs spent, per category"
- Demo data needed: Expense categories with budgets + recorded expenses.
- CTA: Try free
- Related videos: [08-expenses-budgets-income.md](./08-expenses-budgets-income.md)

- Published title: Every category, planned vs spent.
- Short-form variant: Every category
- Published description: See each expense category's budget against what you actually spent, with variance percentages and an over/under/on-track status so you catch overspend early. Try free #custosell #forecasting #budget #cashrunway #Custosell #SmallBusiness
- Tags: custosell, forecasting, budget, cash runway, scenarios, kpi, projections, how to, tutorial, walkthrough, tips
- Video assets:
  - Thumbnail: bold text "Planned vs spent, per category" over a bright screenshot of the action
  - Screen-record clips: the Screen flow steps, trimmed to the script beats
  - Captions / subtitles: full on-screen captions exported as a caption file
  - Brand overlay / watermark: Custosell logo, corner placement
  - Music: royalty-free background bed, low volume under the voiceover

## Video: Build a zero-based year budget
- Format: 45-90s how-to
- Priority: P1
- Platforms: Reels / Shorts / TikTok
- Tagline: "Every shilling starts at zero and earns its place."
- Description: Create an annual budget for a year, then add zero-based lines -
  each one starts as a draft and every amount can be edited inline.
- What it's about: ForecastingBudgetsPage + ForecastingBudgetDetailPage - year
  budget with draft/active/archived status, add line with label + amount, edit
  amount on blur.
- Script beats:
  - Beat 1 (Hook): "Zero-based budgeting, minus the spreadsheet."
  - Beat 2 (Problem): "Last year's numbers hide this year's waste."
  - Beat 3 (Action): /forecasting/budgets -> Create budget -> name + year ->
    open it -> Add line (e.g. Office rent) -> edit the amount inline.
  - Beat 4 (Aha): "A line starts at draft - nothing is approved by default."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /forecasting/budgets -> create -> detail -> add line.
- On-screen text / captions:
  - "From zero, line by line"
- Demo data needed: A calendar year and a few expense categories.
- CTA: Try free
- Related videos: [13-forecasting.md](./13-forecasting.md)

- Published title: Every shilling starts at zero and earns its place.
- Short-form variant: Every shilling starts at zero and earns its place.
- Published description: Create an annual budget for a year, then add zero-based lines - each one starts as a draft and every amount can be edited inline. Try free #custosell #forecasting #budget #cashrunway #Custosell #SmallBusiness
- Tags: custosell, forecasting, budget, cash runway, scenarios, kpi, projections, how to, tutorial, walkthrough, tips
- Video assets:
  - Thumbnail: bold text "From zero, line by line" over a bright screenshot of the action
  - Screen-record clips: the Screen flow steps, trimmed to the script beats
  - Captions / subtitles: full on-screen captions exported as a caption file
  - Brand overlay / watermark: Custosell logo, corner placement
  - Music: royalty-free background bed, low volume under the voiceover

## Video: Justify and approve budget lines
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "Fund it only if you can argue for it."
- Description: Move each budget line from draft to justified to approved - a
  line can't be approved until it carries a written business justification.
- What it's about: ForecastingBudgetDetailPage - Justify modal, ZbbStatusBadge
  (draft/justified/approved), and the Approve action gated on justification.
- Script beats:
  - Beat 1 (Hook): "A budget you can defend, not just assign."
  - Beat 2 (Problem): "Approvals rubber-stamped without a reason."
  - Beat 3 (Action): /forecasting/budgets/{id} -> open a line -> Justify ->
    write the reason -> Save -> Approve.
  - Beat 4 (Aha): "Approve is locked until the line is justified."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /forecasting/budgets/{id} -> Justify -> approve.
- On-screen text / captions:
  - "Draft -> Justified -> Approved"
- Demo data needed: A draft budget with at least one line.
- CTA: Try free
- Related videos: [13-forecasting.md](./13-forecasting.md)

- Published title: Fund it only if you can argue for it.
- Short-form variant: Fund it only if you can argue for it.
- Published description: Move each budget line from draft to justified to approved - a line can't be approved until it carries a written business justification. Try free #custosell #forecasting #budget #cashrunway #Custosell #SmallBusiness
- Tags: custosell, forecasting, budget, cash runway, scenarios, kpi, projections, how to, tutorial, walkthrough, tips
- Video assets:
  - Thumbnail: bold text "Draft -> Justified -> Approved" over a bright screenshot of the action
  - Screen-record clips: the Screen flow steps, trimmed to the script beats
  - Captions / subtitles: full on-screen captions exported as a caption file
  - Brand overlay / watermark: Custosell logo, corner placement
  - Music: royalty-free background bed, low volume under the voiceover

## Video: Roll a forecast snapshot
- Format: 45-90s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok
- Tagline: "Freeze the forecast so you can compare later."
- Description: Roll a budget to capture a forecast snapshot - budget lines plus
  year-to-date expense actuals - so you have a dated record of your plan.
- What it's about: useRollForecastBudget + useForecastSnapshots - Roll forecast
  button with optional label, snapshot list (label, as-of date, created).
- Script beats:
  - Beat 1 (Hook): "A forecast you can compare against - later."
  - Beat 2 (Problem): "Plans drift and nobody remembers what you planned."
  - Beat 3 (Action): /forecasting/budgets/{id} -> add a snapshot label ->
    Roll forecast -> see it in the Snapshots list.
  - Beat 4 (Aha): "Rolling captures YTD actuals too, not just the plan."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /forecasting/budgets/{id} -> Roll forecast -> snapshots.
- On-screen text / captions:
  - "Plan frozen in time"
- Demo data needed: A budget with lines and some recorded expenses.
- CTA: Try free
- Related videos: [09-accounting.md](./09-accounting.md)

- Published title: Freeze the forecast so you can compare later.
- Short-form variant: Freeze the forecast so you can compare later.
- Published description: Roll a budget to capture a forecast snapshot - budget lines plus year-to-date expense actuals - so you have a dated record of your plan. Try free #custosell #forecasting #budget #cashrunway #Custosell #SmallBusiness
- Tags: custosell, forecasting, budget, cash runway, scenarios, kpi, projections, how to, tutorial, walkthrough, tips
- Video assets:
  - Thumbnail: bold text "Plan frozen in time" over a bright screenshot of the action
  - Screen-record clips: the Screen flow steps, trimmed to the script beats
  - Captions / subtitles: full on-screen captions exported as a caption file
  - Brand overlay / watermark: Custosell logo, corner placement
  - Music: royalty-free background bed, low volume under the voiceover

## Video: Run what-if scenarios
- Format: 45-90s how-to
- Priority: P1
- Platforms: Reels / Shorts / TikTok / YouTube
- Tagline: "What if you hire? What if sales grow?"
- Description: Model a hire, extra monthly opex, or a revenue uplift against
  your baseline cash ladder, and compare scenario vs baseline month by month.
- What it's about: ForecastingScenariosPage - create scenario (name, horizon,
  hire salary, extra opex, uplift %), Run, and the ForecastScenarioRun
  comparison table with delta metrics.
- Script beats:
  - Beat 1 (Hook): "Test the hire before you sign the offer."
  - Beat 2 (Problem): "Decisions on gut feel, not cash math."
  - Beat 3 (Action): /forecasting/scenarios -> Create scenario -> hire salary +
    uplift -> Run -> read burn, inflow, and closing-cash deltas.
  - Beat 4 (Aha): "Baseline vs scenario, month by month, in one table."
  - Beat 5 (CTA): "Try it free at custosell.com."
- Screen flow: /forecasting/scenarios -> create -> run -> comparison.
- On-screen text / captions:
  - "Baseline vs what-if"
- Demo data needed: An overview forecast to use as the baseline.
- CTA: Try free + subscribe
- Related videos: [15-hr.md](./15-hr.md)

- Published title: What if you hire? What if sales grow?
- Short-form variant: What if you hire? What if sales grow?
- Published description: Model a hire, extra monthly opex, or a revenue uplift against your baseline cash ladder, and compare scenario vs baseline month by month. Try free + subscribe #custosell #forecasting #budget #cashrunway #Custosell #SmallBusiness
- Tags: custosell, forecasting, budget, cash runway, scenarios, kpi, projections, how to, tutorial, walkthrough, tips
- Video assets:
  - Thumbnail: bold text "Baseline vs what-if" over a bright screenshot of the action
  - Screen-record clips: the Screen flow steps, trimmed to the script beats
  - Captions / subtitles: full on-screen captions exported as a caption file
  - Brand overlay / watermark: Custosell logo, corner placement
  - Music: royalty-free background bed, low volume under the voiceover

## Video: Read forecast KPIs - pulse, CAC, LTV, churn
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "Growth numbers that tell you if you're healthy."
- Description: See your trailing 30-day sales pulse, customer acquisition cost,
  customer lifetime value, and 90-day churn - with monthly burn and runway.
- What it's about: ForecastingKpisPage - auto/retail/saas mode, retail pulse,
  CAC (spend / new customers), LTV, churn pct, burn + coverage.
- Script beats:
  - Beat 1 (Hook): "How much does a customer cost? And what do they return?"
  - Beat 2 (Problem): "Growth that quietly loses money."
  - Beat 3 (Action): /forecasting/kpis -> read Pulse, CAC, LTV, Churn ->
    check monthly burn and runway.
  - Beat 4 (Aha): "CAC vs LTV tells you if acquisition pays."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /forecasting/kpis -> retail pulse -> burn.
- On-screen text / captions:
  - "CAC in. LTV out. Churn visible."
- Demo data needed: Sales, customers, and acquisition spend data.
- CTA: Try free
- Related videos: [07-customers.md](./07-customers.md)

- Published title: Growth numbers that tell you if you're healthy.
- Short-form variant: Growth numbers that tell you if you're healthy.
- Published description: See your trailing 30-day sales pulse, customer acquisition cost, customer lifetime value, and 90-day churn - with monthly burn and runway. Try free #custosell #forecasting #budget #cashrunway #Custosell #SmallBusiness
- Tags: custosell, forecasting, budget, cash runway, scenarios, kpi, projections, how to, tutorial, walkthrough, tips
- Video assets:
  - Thumbnail: bold text "CAC in. LTV out. Churn visible." over a bright screenshot of the action
  - Screen-record clips: the Screen flow steps, trimmed to the script beats
  - Captions / subtitles: full on-screen captions exported as a caption file
  - Brand overlay / watermark: Custosell logo, corner placement
  - Music: royalty-free background bed, low volume under the voiceover

## Video: Unlock SaaS MRR proxies
- Format: 45-90s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok
- Tagline: "Sell recurring? See MRR and ARR proxies."
- Description: Mark products or services as recurring and the KPIs page shows
  MRR, ARR, active subscribers, and average recurring price as simple proxies
  from your own catalog.
- What it's about: ForecastingKpisPage SaaS section - resolved mode, MRR/ARR,
  active subscribers 60d, avg recurring price, gated on has_recurring_products.
- Script beats:
  - Beat 1 (Hook): "Recurring revenue, counted from your own catalog."
  - Beat 2 (Problem): "MRR hidden in a spreadsheet you never update."
  - Beat 3 (Action): /forecasting/kpis -> switch mode to SaaS -> see MRR/ARR ->
    or open products and mark items is_recurring.
  - Beat 4 (Aha): "MRR is a proxy from your product prices - no billing system."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /forecasting/kpis -> SaaS mode -> recurring products.
- On-screen text / captions:
  - "MRR from your catalog"
- Demo data needed: Recurring products/services with prices.
- CTA: Try free
- Related videos: [05-inventory-products.md](./05-inventory-products.md)

- Published title: Sell recurring? See MRR and ARR proxies.
- Short-form variant: Sell recurring? See MRR and ARR proxies.
- Published description: Mark products or services as recurring and the KPIs page shows MRR, ARR, active subscribers, and average recurring price as simple proxies from your own catalog. Try free #custosell #forecasting #budget #cashrunway #Custosell #SmallBusiness
- Tags: custosell, forecasting, budget, cash runway, scenarios, kpi, projections, how to, tutorial, walkthrough, tips
- Video assets:
  - Thumbnail: bold text "MRR from your catalog" over a bright screenshot of the action
  - Screen-record clips: the Screen flow steps, trimmed to the script beats
  - Captions / subtitles: full on-screen captions exported as a caption file
  - Brand overlay / watermark: Custosell logo, corner placement
  - Music: royalty-free background bed, low volume under the voiceover

---

## Technical reference (source of truth)

**Screens:** `/forecasting` [M] (INDEX -> overview: cash ladder, burn breakdown,
budget vs actual, coverage snapshot, quick links) · `/forecasting/budgets` [M]
(year budget list + create) · `/forecasting/budgets/{id}` [M] (lines with
justify/approve/roll + snapshots) · `/forecasting/kpis` [M] (retail + saas) ·
`/forecasting/scenarios` [M] (what-if create/list/run)

**User actions (FE hooks):** `useForecastingOverview` · `useCashForecast` ·
`useBudgetVsActual` · `useForecastKpis` · `useForecastBudgets` ·
`useForecastBudget` · `useCreateForecastBudget` · `useUpdateForecastBudget` ·
`useDeleteForecastBudget` · `useCreateForecastBudgetLine` ·
`useUpdateForecastBudgetLine` · `useDeleteForecastBudgetLine` ·
`useJustifyForecastBudgetLine` · `useApproveForecastBudgetLine` ·
`useRollForecastBudget` · `useForecastSnapshots` · `useForecastScenarios` ·
`useForecastScenario` · `useCreateForecastScenario` ·
`useUpdateForecastScenario` · `useDeleteForecastScenario` ·
`useRunForecastScenario`

**API endpoints (BE):** `GET /forecasting/overview` · `GET
/forecasting/cash-forecast` · `GET /forecasting/budget-vs-actual` · `GET
/forecasting/kpis` · `/forecasting/budgets` CRUD + `/{id}/lines` CRUD +
`/{id}/lines/{lineId}/justify` + `/{id}/lines/{lineId}/approve` + `/{id}/roll`
· `GET /forecasting/snapshots` · `/forecasting/scenarios` CRUD +
`/{id}/run`

**Route middleware (BE):** `auth:sanctum` · `business.active` ·
`subscription.active` · `module:forecasting` (module must be enabled)

**Key types (FE):** `ForecastCoverageStatus` = healthy | tight | critical |
unknown · `ForecastBudgetStatus` = draft | active | archived ·
`ForecastZbbStatus` = draft | justified | approved · `ForecastBvaStatus` = over
| under | on_track · `ForecastKpiMode` = auto | retail | saas · `CashForecast`
(cash, liabilities incl payroll/PAYE/NSSF payable, burn from HR payroll
affordability + trailing opex, assumed monthly inflow from trailing 30d net
sales, runway coverage, month cash ladder rows with can_cover) ·
`BudgetVsActual` (per-category budget/actual/variance/status) · `ForecastBudget`
+ `ForecastBudgetLine` (label, amount, justification, zbb_status) ·
`ForecastSnapshot` (budget lines + YTD expense actuals captured on roll) ·
`ForecastKpis` (retail: pulse/CAC/LTV/churn; saas: MRR/ARR/subscribers) ·
`ForecastScenario` + `ForecastScenarioRun` (baseline + scenario forecast +
delta)
