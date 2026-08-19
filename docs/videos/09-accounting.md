# 09 - Accounting

The books. Chart of accounts, journals, and financial reports that stay in
sync with everything you do.

## Video: Your books stay in sync automatically
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok / YouTube
- Tagline: "Sell once. Your books update themselves."
- Description: See how every sale, expense, and payment on Custosell writes
  itself into proper double-entry accounting - no accountant required at the
  till.
- What it's about: Automatic journal posting from business actions.
- Script beats:
  - Beat 1 (Hook): "Your books are writing themselves."
  - Beat 2 (Problem): "Manual bookkeeping means double work and mistakes."
  - Beat 3 (Action): Make a sale -> open the general ledger -> show the journal
    entry -> make an expense -> show the matching entry.
  - Beat 4 (Aha): "Every action has a journal entry - automatically."
  - Beat 5 (CTA): "Try it free at custosell.com."
- Screen flow: /accounting -> general ledger -> recent entries from live
  actions.
- On-screen text / captions:
  - "Sell -> Journal. Automatically."
- Demo data needed: A sale and an expense just recorded.
- CTA: Try free + subscribe
- Related videos: [03-sales-pos.md](./03-sales-pos.md)

## Video: Read the general ledger
- Format: 45-90s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok
- Tagline: "Every transaction, one ledger."
- Description: Open the general ledger on Custosell and find any transaction -
  search by account, date, or amount. Full audit trail, always there.
- What it's about: General ledger browsing/search.
- Script beats:
  - Beat 1 (Hook): "Every shilling has a trail."
  - Beat 2 (Problem): "Auditors love trails. Messy books hate them."
  - Beat 3 (Action): /accounting -> general ledger -> filter by account/date ->
    open a transaction.
  - Beat 4 (Aha): "Complete trail, searchable in seconds."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /accounting -> general ledger -> filters -> transaction detail.
- On-screen text / captions:
  - "The whole trail"
- Demo data needed: A month of transactions.
- CTA: Try free
- Related videos: [09-accounting.md](./09-accounting.md)

## Video: Understand the chart of accounts
- Format: 3-6min deep dive
- Priority: P3
- Platforms: YouTube
- Tagline: "Chart of accounts, explained for shop owners."
- Description: A plain-English tour of the chart of accounts on Custosell -
  assets, liabilities, equity, income, expenses - and why it matters for your
  reports.
- What it's about: Chart of accounts structure + how reports use it.
- Script beats:
  - Beat 1 (Hook): "Accounting words, explained in shop language."
  - Beat 2 (Problem): "Reports feel foreign when the accounts do."
  - Beat 3 (Action): /accounting -> chart of accounts -> walk the five types ->
    show how a sale hits each.
  - Beat 4 (Aha): "Reports are just your accounts, summarized."
  - Beat 5 (CTA): "Try it free at custosell.com."
- Screen flow: /accounting -> chart of accounts -> type tour -> report link.
- On-screen text / captions:
  - "Assets. Income. Expenses. It's that simple."
- Demo data needed: A full chart of accounts.
- CTA: Try free + subscribe
- Related videos: [02-dashboard-reports.md](./02-dashboard-reports.md)

## Video: Your profit & loss is always ready
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "Hand your accountant a clean P&L - anytime."
- Description: Generate a correct profit & loss statement on Custosell for any
  period - built from real, double-entry data.
- What it's about: P&L generation from the ledger.
- Script beats:
  - Beat 1 (Hook): "Accountant needs numbers? Here's the button."
  - Beat 2 (Problem): "Building a P&L by hand takes a weekend."
  - Beat 3 (Action): /accounting -> P&L -> pick period -> review -> export.
  - Beat 4 (Aha): "Real, correct, exportable."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /accounting -> P&L -> period -> export.
- On-screen text / captions:
  - "Clean P&L, on demand"
- Demo data needed: Full business activity in the period.
- CTA: Try free
- Related videos: [08-expenses-budgets-income.md](./08-expenses-budgets-income.md)

---

## Technical reference (source of truth)

**Screens:** `/accounting` [M], `/accounting/general-ledger`,
`/accounting/chart-of-accounts`, `/accounting/reports` (P&L, balance, trial)

**User actions (FE hooks):** `useGeneralLedger` · `useChartOfAccounts` ·
`useProfitLoss` · `useBalanceSheet` · `useTrialBalance` ·
`useJournalEntries` · `useExportLedger`

**API endpoints (BE):** `/general-ledger` (entries, search, export) ·
`/chart-of-accounts` · `/reports/profit-loss` · `/reports/balance-sheet` ·
`/reports/trial-balance` · `/journal`