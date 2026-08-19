# Custosell — Video Content Map

**Purpose:** Derive every short, focused tutorial video from the product's actual
user actions. The source of truth is the code itself — frontend query/mutation
hooks (`Frontend/src/renderer/...`) and backend routes
(`Backend/routes/api/v1/*.php`) — because **queries and routes are the user's
verbs**: every hook and every endpoint is something a user can do, which means
it is a teachable moment. Frontend route paths map each verb to the screen the
video must show.

**Audience:** shop owners / managers running a till on Custosell — busy, not
technical, mostly on phones. One job per video, one promise per video.

**Format:** 45–90s focused how-tos (workhorse) + ≤30s hook clips (sliced from
them) + 3–6min evergreen deep dives. Record vertical 9:16, captions always,
demo data throughout.

---

## 0. How to read this document

Each module has:

- **Screens** — the frontend routes (`app/routes/index.tsx` +
  `shared.paths.ts`) a user navigates to; these are the *filming locations*.
- **User actions** — the FE query/mutation hooks; these are the *verbs* (the
  "you can do X" statements).
- **API endpoints** — the BE routes backing those verbs (useful for accuracy
  when scripting and for the help-center text).
- **Video concepts** — the derived 45–90s tutorial titles, prioritized.

Legend: `[M]` module-gated screen · `[L]` lazy-loaded screen · `[P]` priority
(1 = film first, 2 = wave two, 3 = deep-dive/evergreen).

**Priority logic:** film the money loop and the differentiators first (sales,
shift close, offline sync, dashboard), then daily workflows, then
configuration, then expert/evergreen content.

---

## 1. Account, Auth & Security

**Screens:** `/login` `[L]` · `/register` `[L]` · `/forgot-password` `[L]` ·
`/reset-password` `[L]` · `/verify-code` `[L]` · `/onboarding` `[L]` ·
`/account/profile` · `/account/security` `[L]` · `/account/notifications` ·
`/account/referrals` `[L]`

**User actions (FE hooks):** `useRegister` · `useRegisterBusiness` ·
`useLogin` · `useLogout` · `useForgotPassword` · `useResetPassword` ·
`useProfile` · `useSendVerificationCode` · `useVerifyCode` ·
`useToggleTwoFactor` · `useAccountActivity` · `useInitiateProfileChange` ·
`useConfirmProfileChange` · `useLinkedAccounts` · `useInitiateLinkAccount` ·
`useConfirmLinkAccount` · `useSwitchAccount` · `useSetPrimary` ·
`useInitiateUnlinkAccount` · `useConfirmUnlinkAccount`

**API endpoints (BE):** `/auth/register` · `/auth/login` · `/auth/logout` ·
`/auth/forgot-password` · `/auth/reset-password` · `/auth/verify/send` ·
`/auth/verify` · `/auth/me` · `/auth/profile` · `/auth/two-factor` ·
`/auth/password/initiate|confirm` · `/auth/profile/initiate|confirm` ·
`/auth/activity` · `/auth/onboarding` · `/users/lookup|attach|detach` ·
`/linked-accounts` (+ confirm/switch/set-primary/unlink)

**Video concepts:**
- `[P1]` **"Create your shop in 5 minutes"** — register → business → onboarding
  → first sale ready.
- `[P1]` **"Run two shops with one login"** — linked accounts: link, switch,
  set primary (you just shipped the avatar fix — great moment to film).
- `[P2]` **"Lock your account"** — 2FA toggle + security activity log.
- `[P2]` **"Lost your password?"** — forgot/reset flow (calms the #1 support
  question).
- `[P3]` **"Change your email safely"** — profile change with verification code.

---

## 2. Dashboard & Reports

**Screens:** `/dashboard` `[M]` · `/settings/data-export` (always reachable)

**User actions (FE hooks):** `useDashboardSummary` ·
`useBranchPerformance(dateFrom, dateTo)` · `useReportDownload`

**API endpoints (BE):** `/dashboard/summary` · `/reports/business-summary` ·
`/reports/daily-sales` · `/reports/sales-trend` · `/reports/expenses` ·
`/reports/inventory` · `/reports/payment-breakdown` ·
`/reports/product-performance` · `/reports/branch-performance` ·
`/reports/vat-summary`

**Video concepts:**
- `[P1]` **"Know your numbers at a glance"** — dashboard KPIs: sales,
  expenses, net, per branch.
- `[P2]` **"Your top products (and dead ones)"** — product-performance report.
- `[P2]` **"Compare your branches"** — branch performance.
- `[P3]` **"Month-end in one screen"** — VAT summary + business summary report.

---

## 3. Sales & POS (the money loop)

**Screens:** `/sales` → `/sales/new` `[M]` · `/sales/orders` `[M]` ·
`/sales/history` `[M]` · `/sales/refunds` `[M]` · `/sales/my-shift` `[M]` ·
`/invoices` · `/invoices/supplier`

**User actions (FE hooks):** `useCreateSale` (cart checkout) · `useRefund` ·
`useRecordSalePayment` · `useAssignSaleCustomer` ·
`useResolveCustomerContact` · `useEmailSaleReceipt` · `usePaymentPopup` ·
`useCreateOrder` / `useUpdateOrder` / `useCancelOrder` (hold/draft orders) ·
`useCreateInvoice` · `useSendInvoice` · `useRecordPayment` · `useEmailInvoice`

**API endpoints (BE):** `/sales` CRUD · `/sales/daily` · `/sales/batch`
(offline sync) · `/sales/{id}/refund` · `/sales/{id}/payment` ·
`/sales/{id}/customer` · `/sales/{id}/pdf` · `/sales/{id}/email` ·
`/orders` CRUD + `/orders/{id}/cancel` · `/invoices` CRUD + `/send` `/payment`
`/email` `/pdf`

**Video concepts:**
- `[P1]` **"Sell your first item"** — the whole POS loop in 60s.
- `[P1]` **"Cash drawer at end of shift"** — shift close + reconciliation
  (see Shift section).
- `[P1]` **"It works with no internet"** — sell offline, auto-syncs (your
  moat; competitors cannot film this).
- `[P2]` **"Hold an order for a customer"** — draft/hold orders.
- `[P2]` **"Refund a sale cleanly"** — refund flow + ledger impact.
- `[P2]` **"Email a receipt in one tap"** — receipts + PDFs.
- `[P3]` **"Invoice your B2B customers"** — invoices: create, send, record
  payment.

---

## 4. Shifts (cash control)

**Screens:** `/sales/my-shift` `[M]`

**User actions (FE hooks):** `useClockIn` · `useClockOut` ·
`useEndShiftAction` · `useUpdateShiftOpeningBalance` ·
`useUpdateShiftBalance` · `useActiveShift` · `useShiftSales` ·
`useShiftExpenses` · `useShiftPayments`

**API endpoints (BE):** `/shifts` CRUD + `/shifts/active` +
`/shifts/{id}/payments` · `/reports/shift-close` ·
`/reports/shift-reconciliation` · `/expenses/by-shift/{shiftId}`

**Video concepts:**
- `[P1]` **"Start your day on the till"** — clock in with opening balance.
- `[P1]` **"Close the shift, balance the drawer"** — end-shift action with
  reconciliation report (sales vs payments vs expenses).
- `[P2]` **"Fix a wrong opening balance"** — adjust float mid-shift.

---

## 5. Inventory — Products & Categories

**Screens:** `/inventory` → `/inventory/overview` `[M]` ·
`/inventory/products` `[M]` · `/inventory/categories` `[M]` ·
`/inventory/stock` `[M]` · `/inventory/marketplace` `[M]` ·
`/inventory/purchase-orders` `[M]` · `/inventory/incoming-orders` `[M]`

**User actions (FE hooks):** `useCreateProduct` · `useUpdateProduct` ·
`useDeleteProduct` · `useUploadProductImage` · `useCreateCategory` ·
`useUpdateCategory` · `useDeleteCategory` · `useLowStockProducts` ·
`useProductStockMovements` · `useStockMovements` · `useCreateStockMovement` ·
`useStockTransfer` · `useLocationStock` · `useInventoryOverview` ·
`useUpdateSupplyListing` · `useUpdateStorefrontListing`

**API endpoints (BE):** `/products` CRUD · `/products/active` ·
`/products/low-stock` · `/products/import|export|import-template` ·
`/products/stock/{locationId}` · `/products/bulk-delete|bulk-listing` ·
`/products/{id}/image` · `/products/{id}/stock-movements` ·
`/categories` CRUD · `/stock-movements` CRUD + `/transfer` ·
`/inventory/overview`

**Video concepts:**
- `[P1]` **"Add your first product"** — product + category + price + image.
- `[P1]` **"Know when to restock"** — low-stock alerts.
- `[P2]` **"Stock transfer between branches"** — move stock across locations.
- `[P2]` **"Import your whole catalog from Excel"** — CSV import/export.
- `[P2]` **"Correct a stock count"** — stock adjustments (in/out).
- `[P3]` **"Track every unit"** — stock movement ledger per product.

---

## 6. Marketplace & Purchase Orders (buyer + supplier)

**Screens:** `/inventory/marketplace` `[M]` · `/inventory/purchase-orders`
`[M]` · `/inventory/incoming-orders` `[M]`

**User actions (FE hooks):** `useMarketplaceBusinesses` ·
`useMarketplaceProducts` · `useAddSupplier` · `useRemoveSupplier` ·
`useCreatePurchaseOrder` · `useSubmitPurchaseOrder` ·
`useAcceptPurchaseOrder` · `useRejectPurchaseOrder` ·
`useFulfillPurchaseOrder` · `useReceivePurchaseOrder` ·
`useIncomingPurchaseOrders`

**API endpoints (BE):** `/marketplace/businesses|products|suppliers` ·
`/purchase-orders` CRUD + `/submit|accept|reject|fulfill|receive|cancel` +
`/incoming`

**Video concepts:**
- `[P2]` **"Buy from the marketplace"** — browse suppliers, create + submit a
  PO, receive stock.
- `[P2]` **"Sell to other shops"** — list on the marketplace, handle incoming
  orders, fulfill.
- `[P3]` **"The PO lifecycle"** — draft → submit → approve → receive (deep
  dive, 3–6 min).

---

## 7. Customers

**Screens:** `/customers` `[M]` · `/customers/overview` `[M]`

**User actions (FE hooks):** `useCreateCustomer` · `useUpdateCustomer` ·
`useDeleteCustomer` · `useResolveCustomerContact` ·
`useCustomerPurchases` · `useCustomerOverview`

**API endpoints (BE):** `/customers` CRUD · `/customers/resolve` ·
`/customers/overview` · `/customers/{id}/purchases`

**Video concepts:**
- `[P2]` **"Know your best customers"** — customer list + purchase history.
- `[P2]` **"Attach a sale to a customer"** — resolve-by-phone at the till.
- `[P3]` **"Customer dashboard"** — overview counts and trends.

---

## 8. Expenses, Budgets & Income

**Screens:** `/expenses` → `/expenses/overview` `[L]` · `/expenses/list` ·
`/expenses/categories` · `/expenses/income` `[L]` · `/expenses/budgets` `[L]`

**User actions (FE hooks):** `useCreateExpense` · `useUpdateExpense` ·
`useDeleteExpense` · `useUploadExpenseAttachment` · `useExpenseCategories`
CRUD · `useBudgetsIndex` / `useCreateBudget` / `useUpdateBudget` /
`useDeleteBudget` / `useSyncBudgetLines` / `usePurchaseLine` /
`useBudgetAffordability` / `useBudgetAlerts` · `useCreateIncome` /
`useUpdateIncome` / `useDeleteIncome` / `useIncomeOverview` /
`useUploadIncomeAttachment` · `useMoneySummary`

**API endpoints (BE):** `/expenses` CRUD + `/overview|summary|export` ·
`/expense-categories` CRUD · `/budgets` CRUD + `/lines` + `/affordability` +
`/download` · `/income-sources` CRUD + `/summary` · `/money/summary|alerts`

**Video concepts:**
- `[P1]` **"Record a business expense"** — expense + category + receipt photo.
- `[P2]` **"See where your money goes"** — expense summary by category/period.
- `[P2]` **"Budget a project and track it"** — budgets + budget lines +
  affordability check.
- `[P3]` **"Track money in"** — income sources + overview.
- `[P3]` **"Receipts, filed"** — expense attachments (receipt images).

---

## 9. Accounting (the GL layer)

**Screens:** `/accounting` → `/accounting/chart-of-accounts` `[M]` ·
`/accounting/journal-entries` `[M]` · `/accounting/periods` `[M]` ·
`/accounting/statements` `[M]` · `/accounting/trial-balance` `[M]` ·
`/accounting/income-statement` `[M]` · `/accounting/balance-sheet` `[M]` ·
`/accounting/fixed-assets` `[M]` · `/accounting/ratios` `[M]` ·
`/accounting/settings` `[M]`

**User actions (FE hooks):** `useChartOfAccounts` (list/tree/CRUD) ·
`useAccountingPeriods` / `useClosePeriod` · `useJournalEntries` +
`usePostJournalEntry` + `useReverseJournalEntry` · `useFixedAssets` +
`useRunDepreciation` · `useTrialBalance` · `useIncomeStatement` ·
`useBalanceSheet` · `useCashFlow` · `useRatios` / `useRatioTrends` ·
`useInventoryReconciliation` · `usePostOpeningInventory`

**API endpoints (BE):** `/chart-of-accounts` CRUD + `/import|export|tree` ·
`/accounting-periods` CRUD + `/close|reopen` · `/journal-entries` CRUD +
`/post|reverse|import|export` · `/general-ledger/*` (trial-balance,
profit-loss, balance-sheet, cash-flow, equity) · `/fixed-assets` CRUD +
`/run-depreciation|schedule` · `/ratios` + `/trends` ·
`/inventory/reconciliation` · `/inventory/opening-balance` ·
`/accounting/export/{type}`

**Video concepts:**
- `[P2]` **"Your accounting is automatic"** — selling posts to the GL
  automatically; revenue recognition runs on schedule (differentiator).
- `[P2]` **"Read your profit & loss"** — income statement, plain-English tour.
- `[P3]` **"Close the month"** — accounting periods: open, close, reopen.
- `[P3]` **"Depreciate your assets"** — fixed assets + depreciation run.
- `[P3]` **"Balance the books"** — trial balance + GL reconciliation
  (evergreen deep dive).

---

## 10. Documents Vault

**Screens:** `/documents` → `/documents/cabinets/:cabinetId` `[M]`

**User actions (FE hooks):** `useCreateDocumentCabinet` (CRUD) ·
`useUploadDocument` · `useCreateDocumentLink` · `useCreateDocumentFolder`
(CRUD/tree) · `useDocumentTags` · `useDocumentActivity` ·
`useEmailVaultFile` · `useEmailVaultFolder` ·
`useDocumentsVaultAppearance` · `useDocumentAccessibleMembers`

**API endpoints (BE):** `/documents/cabinets` CRUD + `/activity` +
`/vault-appearance` + `/accessible-members` · `/documents/folders` tree/CRUD +
`/export|email` · `/documents` CRUD + `/upload|link|content|view|download`

**Video concepts:**
- `[P2]` **"Your paperwork, in one cabinet"** — cabinets, folders, uploads.
- `[P3]` **"Share a file with a client"** — email a file/folder from the vault.
- `[P3]` **"Vault security"** — access control + activity log.

---

## 11. Pipeline / CRM (leads, boards, collaboration)

**Screens:** `/pipeline` → `/pipeline/boards` `[M]` ·
`/pipeline/boards/:boardId` `[M]` · `/pipeline/my-work` `[M]` ·
`/pipeline/leads` `[M]` · `/pipeline/insights` `[M]` · `/pipeline/settings`
`[M]`

**User actions (FE hooks):** boards (CRUD/duplicate/background) · leads
(CRUD/move/convert/links) · stages/sources/labels (CRUD/reorder) ·
activities/checklists/attachments · announcements/polls/board-chat/reminders ·
templates · resources (shared drive) · meta-fields · automations ·
targets/progress · booking (settings, approve/complete/reject, public booking)
· meetings · wall of fame

**API endpoints (BE):** `/pipeline/boards` CRUD + `/duplicate|kanban|calendar`
+ `/import` · `/pipeline/leads` CRUD + `/convert|stage` ·
`/pipeline/stages|sources|labels` CRUD · `/pipeline/insights` ·
`/pipeline/.../checklists|attachments|activities|reactions` ·
`/pipeline/boards/{id}/announcements|polls|conversation|resources|targets|
progress|automations|booking-settings|meta-fields` ·
`/pipeline/wall-of-fame` · `/public/book/{token}` (+ slots/check)

**Video concepts:**
- `[P2]` **"Track your deals on a board"** — kanban: stages, drag, convert to
  customer/sale.
- `[P2]` **"Never lose a lead"** — add a lead with source + label, follow up.
- `[P3]` **"Let clients book you"** — public booking link + meetings.
- `[P3]` **"Keep the team in the loop"** — board chat, announcements, polls.
- `[P3]` **"Win-rate insights"** — pipeline insights + targets/progress
  (deep dive).

---

## 12. Estimates, Templates & Projects

**Screens:** `/estimates` `[L]` · `/estimates/:id` `[L]` ·
`/estimates/projects` `[L]` · `/estimates/boards` `[L]` ·
`/estimates/boards/:boardId` `[L]` · `/estimates/projects/:id` `[L]` ·
`/estimates/projects/:id/board` `[L]` · `/estimates/insights` `[L]` ·
`/estimates/templates` `[L]`

**User actions (FE hooks):** `useCreateEstimate` (CRUD) · `useSendEstimate` ·
`useApproveEstimate` / `useRejectEstimate` · `useEmailEstimate` ·
`useDuplicateEstimate` · `useEstimateVersions` ·
`useConvertEstimateToInvoice` · `useConvertEstimateToProject` ·
`useEstimateTemplates` (CRUD) · `useEstimateAnalytics` · `useProjects`
(CRUD) · `useCreateProjectTask` · `useCreateTimesheetEntry` ·
`useCreateCostAllocation` · `useProjectBudgetSummary` ·
`useProjectProfitability` · `useProjectBoardKanban` · `useProjectMembers`

**API endpoints (BE):** `/estimates` CRUD + `/send|approve|reject|email|pdf|
duplicate|versions|revision|convert-to-invoice|convert-to-project` +
`/analytics` · `/estimates/templates` CRUD · `/projects` CRUD + `/board|
kanban|members|budget-summary|profitability` + `/tasks|timesheets|allocations`

**Video concepts:**
- `[P2]` **"Quote a job in minutes"** — estimate → send → client approves.
- `[P2]` **"Turn a quote into an invoice"** — convert estimate to invoice.
- `[P3]` **"Run a project end-to-end"** — project board, tasks, timesheets,
  profitability (deep dive).

---

## 13. Forecasting

**Screens:** `/forecasting` → `/forecasting/overview` `[L]` ·
`/forecasting/budgets` `[L]` · `/forecasting/budgets/:budgetId` `[L]` ·
`/forecasting/kpis` `[L]` · `/forecasting/scenarios` `[L]`

**User actions (FE hooks):** `useForecastingOverview` · `useCashForecast` ·
`useBudgetVsActual` · `useForecastKpis` · forecast budgets (CRUD/lines/
justify/approve/roll) · `useForecastSnapshots` · scenarios (CRUD/run)

**API endpoints (BE):** `/forecasting/overview|cash-forecast|budget-vs-actual|
kpis` · `/forecasting/budgets` CRUD + `/lines|justify|approve|roll` ·
`/forecasting/snapshots` · `/forecasting/scenarios` CRUD + `/run`

**Video concepts:**
- `[P3]` **"See your cash 90 days ahead"** — cash forecast + KPIs.
- `[P3]` **"Budget vs reality"** — budget-vs-actual + snapshots.
- `[P3]` **"Model a what-if scenario"** — run scenarios (evergreen).

---

## 14. Storefront / B2C Shopping

**Screens:** `/discover` `[L]` (layout) · `/discover/my-orders` `[L]` ·
`/discover/wishlist` `[L]` · `/discover/favorites` `[L]` ·
`/discover/shop/:slug` `[L]` · `/book/:token` `[L]` · share-link redirects
(`/:shopHandle`)

**User actions (FE hooks):** `useStorefrontDiscover` (browse) ·
`useStorefrontShop` (shop detail) · `usePlaceStorefrontOrder` (checkout) ·
`useMyStorefrontOrders` + cancel/delete · `useWishlist` / `useFavorites`
(add/remove/count) · `useRateStorefrontProduct` / `useRateStorefrontShop` ·
cart slice (addProduct/updateQty/removeLine/setBagContact/clearBag)

**API endpoints (BE):** `/storefront/discover|categories|facets|shops` ·
`/storefront/{slug}` + `/products` + `/orders` + `/ratings` ·
`/storefront/my-orders` (+ sale/invoice/pdf/cancel/delete) ·
`/storefront/wishlist|favorites`

**Video concepts:**
- `[P2]` **"Open your online shop"** — storefront profile, slug, listing
  products (B2C differentiator).
- `[P3]` **"Shop on Custosell"** — discover, cart, checkout, order tracking
  (buyer POV).
- `[P3]` **"Let customers rate you"** — ratings + favorites/wishlist.

---

## 15. HR (people, attendance, payroll)

**Screens:** `/hr` `[L]` · `/hr/overview` `[L]` · `/hr/people` `[L]` ·
`/hr/people/:employeeId` `[L]` · `/hr/departments` `[L]` ·
`/hr/company-assets` `[L]` · `/hr/company-assets/:assetId` `[L]` ·
`/hr/attendance` `[L]` · `/hr/leave` `[L]` · `/hr/payroll` `[L]` ·
`/hr/payroll/runs/:payRunId` `[L]` · `/hr/talent` `[L]` · `/hr/transfers`
`[L]` · `/hr/reports` `[L]` · `/hr/settings` `[L]`

**User actions (FE hooks):** departments/positions CRUD · employees (CRUD +
with-account + link/unlink user + create/remove account) · attendance
(clock/events/register/correct-day/import-timesheets/pos-shifts) · leave
(types/balances/requests/approve/reject/cancel) · payroll (structures/
compensations/pay-runs + calculate/approve/post/settle/void/remit-statutory/
payslips) · talent (onboarding templates/tasks, performance reviews, roster) ·
company assets (assign/transfer/return/maintenance) · reports (PAYE/NSSF/
affordability/audit)

**API endpoints (BE):** `/hr/departments|positions` CRUD ·
`/hr/employees` CRUD + `/sync-staff|with-account|link-user|unlink-user|
create-account|remove-account|account-options` · `/hr/attendance/clock|events|
register|days|import-timesheets|pos-shifts` · `/hr/leave/types|balances|
requests` (+ approve/reject/cancel) · `/hr/payroll/structures|compensations|
pay-runs` (+ calculate/approve/post/settle/remit-statutory/void) ·
`/hr/talent/*` (onboarding, performance, reviews) ·
`/hr/company-assets` CRUD (+ assign/transfer/return) · `/hr/reports/*` ·
`/hr/audit-logs`

**Video concepts:**
- `[P2]` **"Add your staff and roles"** — employee + account + department.
- `[P2]` **"Clock in, clock out"** — attendance register linked to POS shifts.
- `[P3]` **"Run payroll in one click"** — pay run: calculate → approve →
  post → settle, with PAYE/NSSF schedules.
- `[P3]` **"Track company assets"** — assign/transfer/return laptops, etc.

---

## 16. Subscriptions & Billing

**Screens:** `/settings/subscription` (RequireOnline, always reachable) ·
`/register/payment` `[L]` · `/pricing` (public)

**User actions (FE hooks):** `useSubscribe` · `useInitiatePayment` ·
`useConfirmPayment` · `useBillingHistory` · `useUpgradeQuote` · `useUpgrade` /
`useDowngrade` · `useCancelScheduledChange` · `useChangeBillingCycle` ·
`useSubscriptionChanges` · `useEmailReceipt` · `downloadReceiptPdf`

**API endpoints (BE):** `/subscriptions/current|access` ·
`/subscriptions/subscribe` · `/subscriptions/{id}/cancel|upgrade|downgrade|
proration-quote|changes|cancel-change|billing-cycle` · `/billing/payments`
CRUD + `/initiate|confirm|receipt|receipt/email` + `/history` +
`/gateway/{gw}/webhook|callback` + `/pesapal/ipn`

**Video concepts:**
- `[P2]` **"Choose the right plan"** — plans/pricing + subscribe.
- `[P3]` **"Upgrade without surprises"** — proration quote + scheduled
  changes + cycle switch.
- `[P3]` **"Find any receipt"** — billing history, receipt PDF + email.

---

## 17. Settings (business, roles, staff, locations, tax)

**Screens:** `/settings/business` (always reachable) · `/settings/sales-
channels` · `/settings/tax` · `/settings/staff` · `/settings/roles` ·
`/settings/modules` · `/settings/locations` · `/settings/data-export`
(always) · `/account/profile` · `/account/security`

**User actions (FE hooks):** `useUpdateBusiness` · `useUpdateSupplyProfile` ·
`useUpdateStorefrontProfile` · `useCheckSlugAvailable` ·
`useBusinessSocialLinks` (CRUD) · `useBusinessExport` ·
`useInitiateBusinessDelete` / `useConfirmBusinessDelete` · staff CRUD +
attach/detach + transfers · roles CRUD · locations CRUD + set-default ·
`useVatSummary` · `useEfrisStatus` · `useBusinessTaxSettings`

**API endpoints (BE):** `/businesses/register|mine|profile|settings|export|
slug-available|storefront-profile|supply-profile|account/initiate|account/
confirm` · `/staff-transfers` · `/roles` CRUD · `/locations` CRUD + `/active`
+ `/{id}/default` · `/efris/status` · `/business-social-links` CRUD ·
`/plans/active`

**Video concepts:**
- `[P2]` **"Brand your shop"** — business profile, logo, social links.
- `[P2]` **"Give staff the right access"** — roles + staff + module access.
- `[P2]` **"Multiple branches, one system"** — locations + set default.
- `[P3]` **"Tax compliance"** — VAT summary + EFRIS status + settings.
- `[P3]` **"Export your data"** — data export (trust signal for signups).

---

## 18. Quick Notes

**Screens:** `/notes` (QuickNotesMiddleware) · `/your-tools`

**User actions (FE hooks):** `useCreateQuickNote` / `useUpdateQuickNote` /
`useDeleteQuickNote` · `useReorderQuickNotes` · `useSaveNotesBackground` ·
`useRenameQuickNoteTag` / `useRemoveQuickNoteTag`

**API endpoints (BE):** `/quick-notes` CRUD + `/reorder|background` +
`/tags/rename|remove`

**Video concepts:**
- `[P3]` **"Notes that stick"** — quick notes board, drag-reorder, tags.

---

## 19. Notifications & Web Push

**Screens:** `/account/notifications` (redirect from `/notifications`)

**User actions (FE hooks):** `useNotifications` · `useNotificationUnreadCount`
· `useMarkNotificationRead` / `useMarkAllNotificationsRead` ·
`useDeleteNotification` / `useBulkDeleteNotifications` · `useWebPush`

**API endpoints (BE):** `/notifications` + `/unread-count` + `/read-all` +
`/bulk-delete` + `/delete-all` + `/{id}/read|destroy` · `/webpush/status|
subscribe|unsubscribe`

**Video concepts:**
- `[P3]` **"Get notified when it matters"** — enable web push, manage
  notifications.

---

## 20. Referrals, Credits & Sales Reps

**Screens:** `/account/referrals` `[L]` · `/referral` (public entry)

**User actions (FE hooks):** `useGenerateReferralCode` ·
`useValidateReferralCode` · `useApplyReferralCode` · `useReferralEarnings` ·
`usePaymentInfo` / `useUpdatePaymentInfo` · `usePayoutHistory`

**API endpoints (BE):** `/referrals` + `/earnings/me` + `/apply` ·
`/referral-codes` CRUD + `/validate` · `/credits/balance|history` ·
`/sales-reps` CRUD + `/import|import-template` + `/earnings|payouts` +
`/payouts/record` · `/account/payment-info` · `/payouts/my-history` ·
`/currencies/convert`

**Video concepts:**
- `[P2]` **"Earn by inviting shops"** — referral code, credits, payouts
  (growth loop — film for the acquisition funnel, not just existing users).

---

## 21. Offline-First / Sync (the moat)

**Screens:** everywhere (implicit) — best filmed inside Sales and Inventory

**User actions (FE hooks):** implicit in the offline engine
(`app/store/offline/*`) — sells, stock moves, expenses queue locally and sync.

**API endpoints (BE):** `/sync/push` · `/sync/pull` · `/sync/full`

**Video concepts:**
- `[P1]` **"Works with no internet"** — record sales offline, everything syncs
  when you're back online (flagship marketing video; film once, reuse in every
  funnel).
- `[P3]` **"How sync really works"** — honest deep dive (push/pull/full) for
  tech-savvy prospects.

---

## 22. Platform Admin (internal — not for public tutorials)

**Screens:** `/platform/*` (PlatformAdminRoute): overview, plans,
subscriptions, businesses, users, roles, sent-messages, sales-reps, payouts,
campaign-codes, conversions, guide admin

**Note:** internal tooling. Exclude from public tutorial content. Useful only
for onboarding internal staff (screen-recorded walkthroughs, not marketing
videos).

---

## 23. Suggested filming order (batched playlists)

| Wave | Playlist | Episodes | Why first |
|---|---|---|---|
| **1 — Sell & cash** | Setup, First sale, Shift close, Offline | 4 | Money loop + differentiator |
| **2 — Run daily** | Products, Customers, Expenses, Marketplace/POs, Invoices, Estimates | 6 | Daily workflows, fastest time-to-value |
| **3 — Manage** | Dashboard/Reports, Accounting, Documents, HR, Forecasting, Storefront | 8 | Depth + feature adoption |
| **4 — Grow** | Referrals, Billing, Settings, Tax, Notifications, Quick Notes | 6 | Growth + retention/trust |

**Batching rule:** record all episodes of one playlist in a single sitting
(same demo data, same cursor/zoom, same intro) — then slice each into a ≤30s
hook clip for Reels/Shorts/TikTok.

---

## 24. House style (recording checklist)

- Vertical 9:16, browser zoom ~120% so UI stays legible cropped.
- Captions on every video (most watch muted).
- Demo data: a realistic UGX business (products like "Blue Band 500g", real
  customer names, an actual supplier) — never empty screens.
- One job per video, one promise: "By the end of this, you can ___."
- Cursor highlighted; no stray tabs/notifications during takes.
- End every video with a CTA: comment / subscribe / try free (link with
  `?utm` per playlist for measurement).

---

## 25. Source-of-truth files (keep this doc in sync)

- FE routes: `Frontend/src/renderer/app/routes/index.tsx` +
  `Frontend/src/renderer/app/routes/constants/shared.paths.ts`
- FE actions: `Frontend/src/renderer/modules/**/api/*Queries.ts` +
  `Frontend/src/renderer/shared/api/**`
- BE endpoints: `Backend/routes/api.php` + `Backend/routes/api/v1/*.php`

When a new feature ships (new hooks/routes), add it to the relevant module
section above with its video concept.