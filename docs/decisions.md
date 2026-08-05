# Custosell — Architecture Decision Records

## ADR-001: Offline-First Desktop Architecture

**Date:** 2026-06-02  
**Status:** Accepted  

**Context:** POS system for Uganda where internet is unreliable. Users need to continue selling even when offline.

**Decision:** Desktop app (Electron + React + SQLite) is the primary source of truth. The Laravel cloud API is a backup/sync hub. All features work offline. Auto-sync happens on reconnect and periodically in the background.

**Consequences:**
- No dependency on internet for daily POS operations
- Sync queue in local SQLite tracks pending changes
- Cloud API acts as auth server + sync hub, not real-time backend

---

## ADR-002: Business-Scoped Multi-Tenancy

**Date:** 2026-06-02  
**Status:** Accepted  

**Context:** Each business is a tenant. Staff belong to one business and should never see data from other businesses.

**Decision:** Every entity carries `business_id` FK. All queries are scoped by the authenticated user's `business_id`. No separate database per tenant (simpler for MVP).

**Consequences:**
- Simpler deployment (single MySQL database)
- `business_id` index on every scoped table
- Middleware/controller always filters by `auth()->user()->business_id`

---

## ADR-003: JSON Permissions on Roles

**Date:** 2026-06-02  
**Status:** Accepted  

**Context:** Need role-based access control scoped to each business without complexity of a full RBAC package.

**Decision:** Roles store permissions as a JSON column with boolean flags for each permission. Two default roles seeded per business: Admin (all true) and Staff (limited).

**Consequences:**
- No separate permissions table
- Easy to seed and customise per business
- Checked via `$user->role->permissions['sales.create'] ?? false`

---

## ADR-004: Plan-Based Feature Restriction

**Date:** 2026-06-02  
**Status:** Accepted  

**Context:** Future monetisation requires restricting features per plan tier.

**Decision:** Plans table stores features and limits as JSON columns. Middleware checks `$business->canFeature('expenses')` on route groups. Limits checked at creation/update time.

**Consequences:**
- Three seeded tiers: Free (UGX 0), Pro (UGX 30,000), Premium (UGX 100,000)
- Feature gates already in place, no controller refactoring when paid plans launch
- `null` limit = unlimited

---

## ADR-005: Receipt Snapshots on SaleItems

**Date:** 2026-06-02  
**Status:** Accepted  

**Context:** Receipts must be reproducible even if product names or prices change later.

**Decision:** SaleItems store `product_name` and `product_price` as snapshots at time of sale, frozen permanently.

**Consequences:**
- Receipts survive product edits and deletions
- Slight data duplication but guarantees accurate historical records

---

## ADR-006: SOLID Repository + Service Pattern

**Date:** 2026-06-02  
**Status:** Accepted  

**Context:** Need maintainable, testable code that follows Laravel best practices.

**Decision:** Every entity has: Migration → Model → RepositoryInterface → Repository → ServiceInterface → Service → Request → Resource → Collection → Controller → Routes → ServiceProvider. All bindings in `bootstrap/providers.php`.

**Consequences:**
- 168 files generated across 14 entities
- Clear separation of concerns (Controller = HTTP, Service = business logic, Repository = data access)
- Provider bindings make testing/swapping implementations trivial

---

## ADR-007: B2B Inventory & Supply Chain (online-only)

**Date:** 2026-07-11  
**Status:** Accepted  

**Context:** Businesses need to buy stock from other tenants. POS Orders already mean held carts.

**Decision:** Opt-in marketplace (`is_open_for_supply`, `listed_for_supply`) and purchase-order lifecycle under `module:inventory`. Online-only (no sync queue). Fulfill stocks out seller; receive requires buyer product mapping then stocks in. Payments off-platform in v1.

**API:** `marketplace.php`, `purchase_orders.php`, `PATCH /businesses/supply-profile`, `PATCH /products/{id}/supply-listing`.

**Tests:** `tests/Feature/SupplyChainTest.php`

**Frontend ADR:** `Frontend/docs/adr/2026-07-11-inventory-supply-chain-b2b.md`

---

## ADR-008: URA EFRIS fiscalization (v1)

**Date:** 2026-07-12  
**Status:** Accepted (config + procedures; API client follows)  

**Context:** Ugandan VAT businesses need EFRIS e-receipts/e-invoices without breaking offline POS or forcing every country onto URA.

**Decision:**
- Uganda-first (`EFRIS_COUNTRY=UG`); country-configurable later
- Direct URA **API** only in v1 (no hardware EFD)
- Fiscalize **both** POS sales and sales invoices
- Offline: **sync later** (never block checkout waiting for URA)
- Master switch `EFRIS_ENABLED` (default `false`) gates all EFRIS behaviour
- Credentials in `.env`; procedures in Frontend `docs/compliance/efris-setup.md`

**Config:** `config/efris.php` · **Env template:** `.env.example` (EFRIS section)

**Frontend ADR:** `Frontend/docs/adr/2026-07-12-efris-fiscalization.md`

---

## ADR-009: Storefront buyer accounts → seller customers

**Date:** 2026-07-12  
**Status:** Accepted  

**Context:** Discover shoppers need accounts without creating a business. Storefront orders tracked `storefront_buyer_user_id` but never created a seller `Customer`.

**Decision:**
- `POST /auth/register` with `account_type=storefront_buyer` → User `business_id=null`, `modules=[]`, no Shift
- Login/register skip Shift when `business_id` is null
- `customers.user_id` FK + unique `(business_id, user_id)`
- `CustomerContactService::attachStorefrontBuyer` on storefront place-order sets `order.customer_id`

**Tests:** `StorefrontTest` (buyer register + customer attach)

**Frontend ADR:** `Frontend/docs/adr/2026-07-12-storefront-buyer-customer-accounts.md`

---

## ADR-010: B2C storefront buyer receipts & invoices

**Date:** 2026-07-12  
**Status:** Accepted  

**Context:** Discover buyers need sale receipts / invoices after shops fulfill storefront orders, without business-scoped `/sales` or `/invoices` access.

**Decision:**
- Enrich `GET /storefront/my-orders` with `sale_id` / `invoice_id` / receipt fields
- `GET /storefront/my-orders/{id}/sale` and `.../invoice` authorized by `storefront_buyer_user_id`
- FE reuses `ReceiptPreviewModal` + `ViewInvoiceModal` (`role=storefront_buyer`)

**Frontend ADR:** `Frontend/docs/adr/2026-07-12-storefront-buyer-receipts-invoices.md`

---

## ADR-011: Storefront buyer phone reuse + My Orders items

**Date:** 2026-07-12  
**Status:** Accepted  

**Context:** Buyers retyped phone on every reorder; My Orders lacked a line-item preview before fulfillment.

**Decision:**
- On place-order, update buyer `User.phone` when a non-empty phone is submitted
- `buyerOrderPayload` includes `items[]`, `customer_name`, `customer_phone`
- FE persists last contact in localStorage and shows Eye → order items modal on My Orders

**Frontend ADR:** `Frontend/docs/adr/2026-07-12-storefront-buyer-phone-and-order-eye.md`

---

## ADR-012: Storefront polish gaps

**Date:** 2026-07-12  
**Status:** Accepted  

**Context:** Discover needed category filters, stock signals, buyer cancel/delete, delivery address, and buyer notify on fulfill.

**Decision:**
- Buyer cancel/delete on my-orders; stock fields + place-order stock check; delivery_address/city on orders
- Notify buyer (email + in-app) when storefront order completed/invoiced
- FE: categories, Online filter, product detail, self-hosted QR, Public shop logo

**Frontend ADR:** `Frontend/docs/adr/2026-07-12-storefront-polish-gaps.md`

---

## ADR-013: Tabbed subscription settings with decision matrix

**Date:** 2026-07-23  
**Status:** Accepted  

**Context:** The subscription settings page showed a dead-end "No active subscription" when the user had no subscription record, and rendered everything in a single scroll. Users needed a clearer experience with plan selection, payment history, and change history in separate tabs, plus context-aware action buttons (Subscribe / Upgrade / Downgrade Now / Schedule Downgrade).

**Decision:**
- Three-tab layout: **Plans**, **Payments**, **History**
- Plans tab reuses the existing plan card visual style with a decision matrix:
  - No subscription → **Subscribe** (navigates to `/onboarding`)
  - Current plan → **Current Plan** badge
  - Higher sort_order → **Upgrade** (immediate, POST `/subscriptions/{id}/upgrade`)
  - Lower sort_order → **Downgrade** → inline choice: **Downgrade Now** (immediate) / **Schedule Downgrade** (end of period)
- Backend: downgrade endpoint accepts optional `effective` param (`immediate`|`end_of_period`), matching the upgrade endpoint pattern
- Backend: new `GET /subscriptions/{id}/changes` endpoint returns scheduled change history
- Frontend: `useUpgrade`, `useDowngrade`, `useSubscriptionChanges` hooks; `DOWNGRADE(id)` and `CHANGES(id)` endpoint constants

**API:**
- `POST /subscriptions/{id}/downgrade { to_plan_id, effective? }`
- `GET /subscriptions/{id}/changes`

**Consequences:**
- Single scroll page replaced with focused tabbed layout (457 lines, under 500 limit)
- Consistent UX: same card styling as PlanCards but with context-aware action buttons
- Downgrade now offers both immediate and end-of-period options
- Payment history and change history moved to their own tabs, reducing cognitive load

---

## ADR-014: Referral reward = % of amount actually paid (10% off / 15% reward)

**Date:** 2026-07-31  
**Status:** Accepted  

**Context:** The default referral program was referee 10% off / referrer 20% reward, both on the undiscounted base. The reward could exceed what the platform actually collected (e.g., free-month codes), and cost more per acquisition than the discount it drove.

**Decision:**
- Default program stays **referee 10% off**; referrer reward default drops **20% → 15%**
- Reward (and sales-rep commission) is a % of the **amount actually paid** = `max(0, base − discount_applied)`, not the undiscounted base
- Flat-amount rewards/commissions unchanged; free-month codes yield $0 referrer reward (the cap working as designed)
- Platform net per referral: 90% collected − 13.5% rewarded ≈ **76.5%**
- Full option analysis (3 options + revisit triggers) in `docs/adr/2026-07-31-referral-reward-economics.md`

**Consequences:**
- `ReferralService::markActive()` now computes `$paidBase` and applies it to PERCENTAGE/FREE_MONTH rewards and PERCENTAGE commissions
- Defaults updated in `UserService`, `BusinessService`, `SimulateCreditDeduction`
- Referrer incentive slightly reduced (20% → ~13.5% of base) but still exceeds the referee's 10% saving

---

## ADR-015: Welcome email on account creation

**Date:** 2026-08-01
**Status:** Accepted

**Context:** New users got no email after registering. The platform already had a standard transactional email (`emails.standard` + `StandardEmail` mailable) used for password resets, dormant-account warnings, and notification digests, but nothing fired on account creation.

**Decision:**
- `UserRegistered` domain event carrying `User` + optional `Business`, dispatched from `UserService::register` and `BusinessService::register` (the latter after the transaction commits)
- Synchronous `SendWelcomeEmail` listener sends the existing `StandardEmail` mailable — brand name, logo, personalised greeting, feature list, "Get Started" CTA to `FRONTEND_URL`, offline-first pro tip
- Email failures are caught and logged; they never fail or roll back registration
- Fixed `StandardEmail::content()` passing `logoPath` where the view reads `logoUrl`, so the header logo now renders

**Tests:** `tests/Feature/SendWelcomeEmailTest.php` (with/without business, `Mail::assertSent`)

**Full detail:** `docs/adr/2026-08-01-account-welcome-email.md`

---

## ADR-016: New products default to listed; bulk list/unlist endpoint

**Date:** 2026-08-01
**Status:** Accepted

**Context:** Products created or imported were unlisted by default on both the B2B supply marketplace (`listed_for_supply`) and the public storefront (`listed_for_storefront`). New inventory therefore never appeared on either shop until manually listed, and listing could only be toggled one product at a time.

**Decision:**
- **Default on** for new/imported products: migration sets both flags to `true` (with `change()`); `ProductService::create` sets `(bool)($data[$flag] ?? true)`; `ProductImportService` sets both flags `true` per row. Existing products keep current state (opt-in via bulk List).
- **Bulk list/unlist**: `ProductService::bulkUpdateListing($ids, $businessId, $channel, $listed)` — business-scoped, channel `supply|storefront`. Supply list auto-fills `supply_price` from `wholesale_price ?? unit_price` (when null) and `supply_min_qty` to `1`; sets/clears `listed_at` / `storefront_listed_at`. Returns updated count.
- `POST /products/bulk-listing` → `{ updated: int }`, validated by `ProductBulkListingRequest` (ids 1..5000, channel enum, listed boolean).
- Listing mutations remain online-only (no offline queueing).

**Tests:** `tests/Feature/ProductListingTest.php` — 6 tests, 31 assertions.

**Full detail:** `docs/adr/2026-08-01-default-listed-products-bulk-listing.md`

---

## ADR-017: SupplyChainTest subscriptions + missing Customer import

**Date:** 2026-08-01
**Status:** Accepted

**Context:** `tests/Feature/SupplyChainTest.php` (12 tests) was written before `subscription.active` middleware landed on all operational route groups (`dc65716`). Without active subscriptions for the test businesses, every marketplace / purchase-order / supplier call returned 403 (and the failed PO `create` cascaded into 404s on `submit`/`accept`). A second, real production bug surfaced once the 403s cleared: accepting a purchase order threw `Class "App\Services\Customer" not found` (500) because `InvoiceService` lost the `Customer` import during a refactor.

**Decision:**
- `SupplyChainTest::setUp` now calls `ensureSubscription()` for the seller and buyer businesses (matching `ProductListingTest`/`DashboardTest`); the cross-tenant "other buyer" also gets a subscription so the test verifies authorization, not subscription gating.
- Added `use App\Models\Customer;` to `app/Services/InvoiceService.php` — PO accept (`createFromPurchaseOrder`) creates/finds the seller's `Customer` by buyer name.

**Tests:** `tests/Feature/SupplyChainTest.php` — 12/12 passing (81 assertions).

**Note:** `InvoiceCreateSaleLinkTest` / `InvoiceSaleLinkTest` / `InvoiceLinkedSalePaymentSyncTest` have 6 **pre-existing** failures (missing accounting period + subscription setup) unrelated to this change; verified identical with these changes stashed.

---

## ADR-018: Invoice link tests fixed — accounting period date lookup + subscriptions

**Date:** 2026-08-01
**Status:** Accepted

**Context:** `InvoiceCreateSaleLinkTest` (3) and `InvoiceLinkedSalePaymentSyncTest` (3) failed pre-existing:
1. Every HTTP call returned **403** — the test businesses had no active subscription, but `subscription.active` middleware now guards all operational route groups (added in `dc65716`).
2. Journal entry posting threw **"No open accounting period found"** even though `setUp` seeded one: `AccountingPeriodRepository::getPeriodByDate`/`getCurrentPeriod` compared a bare `Y-m-d` string against date columns Eloquent stores as `Y-m-d H:i:s`. In SQLite's string comparison, `'2026-08-01 00:00:00' <= '2026-08-01'` is **false**, so lookups silently failed **on the first day of a period** (e.g., journal entries posted on the 1st of the month).

**Decision:**
- `AccountingPeriodRepository::getPeriodByDate` + `getCurrentPeriod` now use `whereDate(...)` so only the date part is compared (SQLite `strftime`, MySQL `DATE()`). Real production bug fix.
- Added `ensureSubscription($business->id)` to `setUp` in both test classes (same pattern as `ProductListingTest` / `SupplyChainTest`).

**Tests:** `InvoiceCreateSaleLinkTest` + `InvoiceLinkedSalePaymentSyncTest` + `InvoiceSaleLinkTest` — **7/7 passing (35 assertions)**.

**Note:** `AccountingTest` (20) and `ReportPeriodRangeTest` (3) have the same 403 root cause plus a July-vs-current period date mismatch — pre-existing, unchanged by this fix.

---

## ADR-019: AccountingTest + ReportPeriodRangeTest fixed — plan-gated modules, date rotation, response wrapping

**Date:** 2026-08-01
**Status:** Accepted

**Context:** `AccountingTest` (20) and `ReportPeriodRangeTest` (3) failed pre-existing. Root causes, once the missing `subscription.active` setup was added (ADR-018 pattern):
1. **Module plan gating:** accounting routes are also guarded by `module:accounting`. `ModuleAccessService::planAllowsModule` requires `plan->features['accounting'] === true`. The default `ensureSubscription` plan (`essential`) does not include accounting → every call still 403. Only `enterprise` (and `personal`) include it.
2. **Date rotation:** `AccountingTest` seeded a static **July 2026** period and hard-coded `2026-07-15/16` journal dates, while the suite now runs in **August 2026**. `current_period_returns_period` (which calls the `whereDate`-fixed `getCurrentPeriod`) and entries dated outside the seeded period would fail. The fixed-assets chart also lacked account **1203**, so `can_create_fixed_asset` crashed with `ErrorException` (null `->id`).
3. **Unwrapped single-resource responses:** `ChartOfAccountController::store` and `AccountingPeriodController::store/close/reopen` returned `response()->json(new Resource)` — this bypasses Laravel's `ResourceResponse` auto-wrap, so the body had **no `data` key** (`data.code` / `data.is_closed` were null). Every other single-resource response in the codebase (e.g. `JournalEntryController`) wraps in `['data' => ...]`, and the frontend (`useCreateChartOfAccount`, `useClosePeriod`) reads `data.data`.

**Decision:**
- Both test classes now subscribe to the **`enterprise` plan** (`ensureSubscription($business->id, Plan::where('slug','enterprise')->first()?->id)`).
- `AccountingTest` seeds a **current-month** period (`now()->startOfMonth()/endOfMonth()`, name `now()->format('F Y')`) and derives journal/fixed-asset dates from `now()` (`startOfMonth()+7/+8`), removing the July-2026 hard-coding; added chart accounts **1200 / 1203**.
- Fixed production response shape: `ChartOfAccountController::store` and `AccountingPeriodController::store/close/reopen` now wrap resources in `['data' => ...]` (matches `JournalEntryController`, matches frontend contract).
- `JournalEntryService::createEntry` now throws `ValidationException` (→ **422**) for unbalanced entries instead of `RuntimeException` (→ 500); `JournalEntryServiceTest::test_rejects_unbalanced_entry` updated to match the validation contract.

**Tests:** `AccountingTest` — 21/21 (92 assertions); `ReportPeriodRangeTest` — 3/3 (10 assertions); `JournalEntryServiceTest` — 4/4. `BoardProgressTest`, `ForecastingAccountingCorrectnessTest`, `PipelineTest`, `ReportTest` failures verified **pre-existing on HEAD** (identical with these changes stashed).

## ADR-022: Full-suite restoration to 608 green — module-gating test setup, storefront-buyer contract, HR test split

**Date:** 2026-08-01
**Status:** Accepted

**Context:** Full suite (`php artisan test`) had **110 failures** in three buckets: (1) ~20 `{data: ...}` sweep shape regressions in tests never re-run (Subscription/Shift/Stock/Invoice/Referral); (2) ~78 pre-existing 403s because test setUps created no subscription for `subscription.active`/`module:*` gated routes; (3) ~10 value mismatches (LedgerServiceTest double-posting, stale SupplyChainTest/StorefrontTest expectations, InvoiceCreateSaleLink send 404).

**Decision:**
- **Bucket 2:** Added `ensureSubscription(...)` to 10 setUps (`UserTest`, `HrModuleTest`, `ForecastingModuleTest`, `ProductServiceSalesTest`, `CompanyAssetsTest`, `EfrisFiscalizationTest`, `CustomerContactResolveTest`, `CustomerDocumentEmailTest`, `TaxTest`, `SupplyChainReceiveAndPartyTest`) — `enterprise` plan where routes are hr/documents/forecasting-gated, default otherwise.
- **Bucket 1:** Updated remaining tests to the `{data: ...}` single-resource shape (`ShiftTest`, `StockMovementTest`, `SubscriptionBillingTest`, `ReferralBillingTest`, `SubscriptionTest`, `InvoiceCreateSaleLinkTest`, `TaxTest`, `ProductServiceSalesTest`, `SupplyChainTest`, `SupplyChainReceiveAndPartyTest`, `UserTest`). `POST /invoices/{id}/email` and `POST /auth/register` confirmed **flat by design** (raw service result / embedded resource) and tests aligned.
- **Storefront-buyer contract:** `UserService::register` no longer creates a personal workspace for `storefront_buyer` (FE copy: "no business setup"); `UserResource::resolveModules()` returns `[]` for personal accounts without a business. FE `getAccessibleModules` seeds `account/guide/discover` client-side, so no FE change. Also exposed `default_vat_rate` on the user resource business section (FE already reads it).
- **Bucket 3:** `LedgerServiceTest` — removed redundant `postEntryToLedger` calls (already run by `createAndPostEntry`; they double-posted 2x). `BusinessAccountDeletionTest` — `forgetGuards()` between requests (in-process auth-guard cache hid the revoked token). `PipelineBoardProgressService` — added `resolvePeriod()` forwarder (Refactor 4 moved it to `PipelineBoardProgressPeriodService`; `HrPerformanceService` still called it → 500s).
- **File-size-500:** Split `HrModuleTest` (1048 lines) into `HrModuleTest` (500), `HrPerformanceTest`, `HrPayrollTest`, `HrPayrollAffordabilityTest` — never gutted, each reuses setUp + `authJson` helper.

**Tests:** `composer vera:fast` — passed (php -l + 6 logic rules incl. file-size-500). `php artisan test` — **608 passed, 1 skipped, 0 failed** (was 110 failures).

---

## ADR-023: Frontend hook response-shape hardening — hooks return the unwrapped resource

**Date:** 2026-08-01
**Status:** Accepted

**Context:** ADR-021 (audit) + ADR-022 (backend sweep) normalized every single-resource endpoint to `{data: ...}`. The frontend has no response unwrap interceptor — each hook unwraps itself. The FE audit found no flat-read bugs, but several hooks returned the `{data: T}` envelope instead of the resource; they only worked because every consumer read `.data`. Latent silent-break hazard for future consumers.

**Decision:** All wrapper-returning hooks now return the **unwrapped resource**; consumers aligned. 12 files, commit `fa31ad3`:
- Queries: `useBookingSettings`, `useBookingInfo`, `useBookingSlots`, `useCheckBooking`, `usePaymentInfo`, `usePayoutHistory` → return `data.data`.
- Mutations: `useUserLookup`, `useRecordPayout`, `useCreateCampaignCode`, `useUpdateCampaignCode`, `useApproveBooking`, `useCompleteBooking`, `useRejectBooking`, `useScheduleMeeting`, `useCreateMeeting`, `useUpdateMeeting` → return the typed entity (`PipelineLead`, `PipelineLeadMeeting`, `CampaignCode`, `PayoutRecord`, `UserLookupResult`).
- Consumers aligned: `BookingSettingsSection`, `LegacyBookingSection`, `CardBookingSection`, `BoardMemberPicker`, `AccountReferralsWinsTab`, `PublicBookingPage`, `PublicBookingCheckPage`.
- **Not changed (correct as-is):** `useCreateBooking` (bespoke top-level `reference_code`/`check_url` contract), `useUpdatePaymentInfo` (`{message}` matches backend).

**Tests:** `npx tsc --noEmit` — clean; `npm run vera:fast` — passed (12 files). Backend untouched.

---

## ADR-024: Third account type — Shopping (storefront_buyer)

**Date:** 2026-08-01
**Status:** Accepted

**Context:** Online storefront buyers — visitors who browse Discover, add to cart, and place orders — were flattened into the Personal flow: `UserService::register` stored `storefront_buyer` as `account_type = 'personal'`, which auto-created a workspace + Personal-plan subscription. Every shopper silently became a workspace owner with a dashboard, plans, and billing surface they never asked for. Oscar asked for a distinct **shopping account** type: Discover & My Orders only, bottom nav hides Dashboard, register page shows it, store signup modals create it, FAQ seeder documents it.

**Decision:**
- Canonical type is **`storefront_buyer`** (already accepted by `RegisterRequest`), exposed as `account_type: 'storefront_buyer'`; FE labels it "Shopping". `UserService::register` preserves it (no flatten to personal) — same no-business branch: `role_id = null`, `modules = []`, no workspace/subscription.
- `UserResource`: `active_plans` is `[]` for `storefront_buyer` (shoppers don't buy Custosell plans); `modules` stays `[]` (FE seeds `account/guide/discover` client-side + new `isStorefrontBuyer()` helper).
- Migration `2026_08_01_000100_backfill_storefront_buyer_account_type.php` re-classifies legacy buyers: `personal AND business_id IS NULL` → `storefront_buyer` (reliable predicate — personal flow always sets `business_id`).
- `SendWelcomeEmail` branches on `storefront_buyer` (shopping intro/showcase/quick-start/tip); `GuideFaqSeeder` gains a "For Shopping Accounts" section.
- FE: `AccountTypeSelector`/`RegisterPage` third "For shopping" option via new reusable `SimpleAccountForm`; `ConnectedStorefrontStrip` hides the Dashboard tab (`onHome={undefined}`) for shopping accounts; `DiscoverAccountMenu` hides the App-home item; `Navbar` brand shows "Shopping". `getDefaultRoute` already lands shopping users on Discover.

**Tests:** `composer vera:fast` passed (php -l + logic incl. file-size-500); new `StorefrontBuyerAccountTypeTest` 2/2 + existing StorefrontTest register test green. FE: `npx tsc --noEmit` clean, `npm run vera:fast` passed.

**Full detail:** `docs/adr/2026-08-01-shopping-account-type.md`

---

## ADR-025: Branch stock transfer excludes service items

**Date:** 2026-08-02
**Status:** Accepted

**Context:** `StockMovementService::transfer()` deducted/incremented `location_product` quantities and wrote a `type = transfer` movement for service lines — inconsistent with the receive path (`d484fca`), which skips stock movements for services because they are not quantitative.

**Decision:** `transfer()` now loads each `Product` and, when `!$product->tracksStock()`, `continue`s the item (no stock validation, no movement). Frontend `BranchTransferModal` also filters services out via `isServiceItem()`. Rule now matches receipt semantics across stacks.

**Tests:** `composer vera:fast` passed (php -l + logic incl. file-size-500). FE: `npx tsc --noEmit` clean, `npm run vera:fast` passed. Commits BE `78bbf16`, FE `32fd1d1`.

**Full detail:** `docs/adr/2026-08-02-branch-stock-transfer-excludes-services.md`

---

## ADR-026: Auto-send subscription payment receipts on successful completion

**Date:** 2026-08-05
**Status:** Accepted

**Context:** Receipts were only emailed via the manual resend endpoint (`POST /billing/payments/{id}/email`). After a successful subscription payment (webhook, callback, confirm, credit, or test bypass) users never received an automatic receipt, driving "I paid but got no receipt" support tickets.

**Decision:** Dispatch a queued `SendPaymentReceiptJob` right after a payment is completed in the success paths:
- `autoApprove()` (covers webhook / callback / `confirmPayment`)
- credit-only completion path in `GatewayService::initiatePayment()`
- gateway test bypass path in `InitiatesGatewayPayments`

The job only sends when the payment is **completed**, **amount > 0**, and **`receipt_sent_at` is null**; on a successful send it stamps `receipt_sent_at` so webhook + confirm firing together cannot double-email. Zero-amount payments (free onboarding, zero-cost upgrades) are skipped. The manual resend endpoint remains for retries. Non-blocking via the queue; `QUEUE_CONNECTION=database` in prod, `sync` in tests.

**Migration:** `2026_08_05_000001_add_receipt_sent_at_to_billing_payments.php` (nullable `receipt_sent_at` timestamp).

**Tests:** `PaymentReceiptAndHistoryTest` 8/8 — includes new auto-send, zero-amount skip, and duplicate-guard tests. Full billing lifecycle suites 79 passed. `composer vera:fast` passed (php -l + logic incl. file-size-500), `migrate --pretend` clean.

**Full detail:** `docs/adr/2026-08-05-auto-send-subscription-receipts.md`
