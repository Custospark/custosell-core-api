# 03 - Sales & POS

The money loop. Everything a shop owner does at the till, from first sale to
refund to invoice.

## Video: Sell your first item
- Format: 45-90s how-to
- Priority: P1
- Platforms: Reels / Shorts / TikTok / YouTube
- Tagline: "Ring up your first sale in under a minute."
- Description: Learn how to make your first sale on Custosell POS. Add items to
  the cart, take payment, and print or email the receipt. Perfect for new shop
  owners getting started. Try Custosell free - link below.
- What it's about: The complete POS checkout flow - the single most important
  action in the app, filmed end to end.
- Script beats:
  - Beat 1 (Hook, 0-3s): "This is the fastest way to sell your first item."
  - Beat 2 (Problem): "Most new shop owners lose time fumbling at the till."
  - Beat 3 (Action): Open /sales/new. Search a product. Tap it to add. Open the
    cart. Choose payment method. Confirm. Show the receipt.
  - Beat 4 (Aha): "One tap and it's already in your books, your stock, and your
    dashboard."
  - Beat 5 (CTA): "Try it free at custosell.com."
- Screen flow: /sales -> /sales/new -> search product -> add to cart -> payment
  popup -> confirm -> receipt screen.
- On-screen text / captions:
  - "Sell your first item"
  - "Tap product. Pay. Done."
  - "Auto-posts to books + stock + dashboard"
- Demo data needed: A seeded business with 3+ products (e.g. Blue Band 500g),
  a customer, at least 2 payment methods configured.
- CTA: Subscribe + try free (https://custosell.com?utm=wave1-firstsale)
- Related videos: [04-shifts.md](./04-shifts.md) (shift close),
  [21-offline-sync.md](./21-offline-sync.md)

## Video: Hold an order for a customer
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "Customer stepped out? Hold their order - don't lose the sale."
- Description: Use hold/draft orders on Custosell POS to pause a sale and come
  back to it later. Never lose a customer mid-purchase again.
- What it's about: The draft/held order flow - create, reopen, edit, and charge.
- Script beats:
  - Beat 1 (Hook): "Don't lose this sale - just hold it."
  - Beat 2 (Problem): "Customer forgot their wallet and the queue is building."
  - Beat 3 (Action): Build cart. Save as order. Show the held order in
    /sales/orders. Reopen it. Complete checkout.
  - Beat 4 (Aha): "Held orders survive even if the app closes - they sync."
  - Beat 5 (CTA): "Start free at custosell.com."
- Screen flow: /sales/new -> build cart -> hold/save order -> /sales/orders ->
  reopen -> checkout.
- On-screen text / captions:
  - "Hold. Not lost."
  - "Reopen anytime"
- Demo data needed: A seeded business with a few products and an existing held
  order.
- CTA: Try free + subscribe
- Related videos: [03-sales-pos.md](./03-sales-pos.md) (first sale),
  [21-offline-sync.md](./21-offline-sync.md)

## Video: Refund a sale cleanly
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "Refunds that don't wreck your books."
- Description: Process a refund on Custosell POS the right way. Refunds update
  stock, accounting, and reports automatically so your numbers stay honest.
- What it's about: The refund flow - select the sale, refund, and see the
  automatic impact on stock + ledger.
- Script beats:
  - Beat 1 (Hook): "Returns happen. Your books shouldn't suffer."
  - Beat 2 (Problem): "A manual refund messes up cash, stock, and records."
  - Beat 3 (Action): /sales/history -> open sale -> Refund -> confirm.
  - Beat 4 (Aha): "Stock and accounting update themselves."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /sales/history -> select sale -> Refund -> confirm -> show stock
  adjustment.
- On-screen text / captions:
  - "Refund in 3 taps"
  - "Stock + books fix themselves"
- Demo data needed: A sale from earlier today with a paid payment.
- CTA: Try free + subscribe
- Related videos: [09-accounting.md](./09-accounting.md)

## Video: Email a receipt in one tap
- Format: 30-45s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "Email the receipt before the customer reaches the door."
- Description: Send a digital receipt from Custosell POS in one tap - no
  printer needed. Customers get a clean PDF by email.
- What it's about: The receipt email flow for a completed sale.
- Script beats:
  - Beat 1 (Hook): "Receipts that email themselves."
  - Beat 2 (Problem): "Printer out of paper? Receipts don't have to stop."
  - Beat 3 (Action): Complete a sale -> open receipt -> Email -> enter email.
  - Beat 4 (Aha): "Digital receipts are searchable forever."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /sales/new -> checkout -> receipt -> Email -> confirm.
- On-screen text / captions:
  - "No printer needed"
  - "Receipt sent"
- Demo data needed: A completed sale.
- CTA: Try free
- Related videos: [03-sales-pos.md](./03-sales-pos.md)

## Video: Invoice your B2B customers
- Format: 3-6min deep dive
- Priority: P3
- Platforms: YouTube
- Tagline: "Invoices that look like you - send, track, and get paid."
- Description: Full walkthrough of Custosell invoicing - create an invoice,
  send it to a customer, record their payment, and keep every PDF on file.
  Great for B2B and wholesale shops.
- What it's about: The complete invoice lifecycle: create, send, record
  payment, download PDF.
- Script beats:
  - Beat 1 (Hook): "Selling to businesses? You need invoices that follow the
    money."
  - Beat 2 (Problem): "Handwritten invoices get lost and never get paid."
  - Beat 3 (Action): /invoices -> create invoice -> add line items -> send ->
    record payment -> download PDF.
  - Beat 4 (Aha): "Every invoice is tracked: sent, viewed, paid."
  - Beat 5 (CTA): "Start free at custosell.com."
- Screen flow: /invoices -> create -> items -> send -> payment -> PDF.
- On-screen text / captions:
  - "Send. Track. Get paid."
  - "PDFs stored forever"
- Demo data needed: A B2B customer, a product/service catalog.
- CTA: Try free + subscribe
- Related videos: [08-expenses-budgets-income.md](./08-expenses-budgets-income.md),
  [09-accounting.md](./09-accounting.md)

---

## Technical reference (source of truth)

**Screens:** `/sales` -> `/sales/new` [M], `/sales/orders` [M],
`/sales/history` [M], `/sales/refunds` [M], `/sales/my-shift` [M],
`/invoices`, `/invoices/supplier`

**User actions (FE hooks):** `useCreateSale` (cart checkout) · `useRefund` ·
`useRecordSalePayment` · `useAssignSaleCustomer` · `useResolveCustomerContact`
· `useEmailSaleReceipt` · `usePaymentPopup` · `useCreateOrder` /
`useUpdateOrder` / `useCancelOrder` (hold/draft) · `useCreateInvoice` ·
`useSendInvoice` · `useRecordPayment` · `useEmailInvoice`

**API endpoints (BE):** `/sales` CRUD · `/sales/daily` · `/sales/batch`
(offline sync) · `/sales/{id}/refund` · `/sales/{id}/payment` ·
`/sales/{id}/customer` · `/sales/{id}/pdf` · `/sales/{id}/email` · `/orders`
CRUD + `/orders/{id}/cancel` · `/invoices` CRUD + `/send` `/payment` `/email`
`/pdf`