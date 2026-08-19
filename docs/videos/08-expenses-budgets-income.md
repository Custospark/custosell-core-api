# 08 - Expenses, Budgets & Income

Know where money goes. Expenses, budgets, and other income.

## Video: Log an expense in seconds
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok / YouTube
- Tagline: "Log an expense before you forget it."
- Description: Record a business expense on Custosell in seconds - amount,
  category, payment method, date. Your profit numbers stay honest.
- What it's about: Create-expense flow.
- Script beats:
  - Beat 1 (Hook): "This takes 5 seconds - and saves your profit line."
  - Beat 2 (Problem): "Forgotten expenses quietly shrink your profit."
  - Beat 3 (Action): /expenses -> Add -> amount -> category -> payment -> save.
  - Beat 4 (Aha): "It flows straight into your P&L."
  - Beat 5 (CTA): "Try it free at custosell.com."
- Screen flow: /expenses -> Add -> form -> save -> P&L updated.
- On-screen text / captions:
  - "5-second expense"
- Demo data needed: Expense categories set up.
- CTA: Try free + subscribe
- Related videos: [02-dashboard-reports.md](./02-dashboard-reports.md)

## Video: Organize expenses with categories
- Format: 45-90s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok
- Tagline: "Expenses in neat categories - reporting gets easier."
- Description: Manage expense categories on Custosell - add, rename, and sort
  expenses so monthly reports tell a clear story.
- What it's about: Expense category management.
- Script beats:
  - Beat 1 (Hook): "A labeled expense is a learnable expense."
  - Beat 2 (Problem): "One big 'misc' bucket hides the truth."
  - Beat 3 (Action): /expenses -> categories -> add/rename -> assign -> report
    view.
  - Beat 4 (Aha): "Now you see what's actually eating money."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /expenses -> categories -> manage -> report.
- On-screen text / captions:
  - "Categorize. Understand. Act."
- Demo data needed: Uncategorized expenses.
- CTA: Try free
- Related videos: [08-expenses-budgets-income.md](./08-expenses-budgets-income.md)

## Video: Set a monthly budget
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "A budget that warns you before you overspend."
- Description: Set monthly budgets on Custosell and get warned as you approach
  the limit - per category or overall.
- What it's about: Budget creation + tracking vs spend.
- Script beats:
  - Beat 1 (Hook): "Spend less without thinking harder."
  - Beat 2 (Problem): "Budgets are useless if nobody sees them."
  - Beat 3 (Action): /budgets -> create -> amount -> category -> watch progress
    vs spend.
  - Beat 4 (Aha): "Warned before you overspend."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /budgets -> create -> track progress.
- On-screen text / captions:
  - "Budget. Track. Stay under."
- Demo data needed: Expense history to compare against.
- CTA: Try free
- Related videos: [08-expenses-budgets-income.md](./08-expenses-budgets-income.md)

## Video: Record non-sales income
- Format: 45-90s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok
- Tagline: "Not every shilling comes from the till - record it anyway."
- Description: Log other income on Custosell - interest, rent, one-off sales -
  so your real profit picture is complete.
- What it's about: Other income entry.
- Script beats:
  - Beat 1 (Hook): "Your profit is incomplete without this."
  - Beat 2 (Problem): "Off-till income skews your numbers."
  - Beat 3 (Action): /income -> Add -> source -> amount -> date -> save.
  - Beat 4 (Aha): "Complete picture, honest profit."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /income -> Add -> form -> save -> P&L.
- On-screen text / captions:
  - "All income. All tracked."
- Demo data needed: A business profile.
- CTA: Try free
- Related videos: [09-accounting.md](./09-accounting.md)

---

## Technical reference (source of truth)

**Screens:** `/expenses` [M], `/expenses/categories`, `/budgets` [M],
`/income` [M]

**User actions (FE hooks):** `useCreateExpense` · `useUpdateExpense` ·
`useDeleteExpense` · `useExpenseCategories` · `useCreateBudget` ·
`useUpdateBudget` · `useBudgetProgress` · `useCreateIncome` ·
`useUpdateIncome`

**API endpoints (BE):** `/expenses` CRUD + `/expenses/categories` CRUD ·
`/budgets` CRUD + `/budgets/progress` · `/income` CRUD