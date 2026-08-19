# 07 - Customers

**Videos in this pack: 4**

Know who buys from you. Profiles, credit, and customer history.

## Video: Add a customer to a sale
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "Attach a customer to any sale - instantly."
- Description: Create or assign a customer while selling on Custosell POS. Keep
  a clean customer list without slowing the till.
- What it's about: Creating/assigning a customer at checkout.
- Script beats:
  - Beat 1 (Hook): "Every sale can know who bought it."
  - Beat 2 (Problem): "Building a customer list feels like extra work."
  - Beat 3 (Action): At checkout -> assign customer -> search or create new ->
    save.
  - Beat 4 (Aha): "Customer history builds itself with every sale."
  - Beat 5 (CTA): "Try it free at custosell.com."
- Screen flow: /sales/new -> checkout -> assign customer -> create -> done.
- On-screen text / captions:
  - "Sell + know the customer"
- Demo data needed: A sale in progress.
- CTA: Try free
- Related videos: [03-sales-pos.md](./03-sales-pos.md)

## Video: Manage your customer list
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "Your customer list - searchable, usable, yours."
- Description: Browse and manage customers on Custosell - search, edit
  profiles, and see each customer's buying history in one place.
- What it's about: Customer CRUD + history view.
- Script beats:
  - Beat 1 (Hook): "Who's your best customer? One search away."
  - Beat 2 (Problem): "Paper customer books can't be searched."
  - Beat 3 (Action): /customers -> search -> open profile -> buying history.
  - Beat 4 (Aha): "Loyalty lives in the data."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /customers -> list -> search -> profile -> history.
- On-screen text / captions:
  - "Search your customers"
- Demo data needed: Several customers with purchase history.
- CTA: Try free
- Related videos: [03-sales-pos.md](./03-sales-pos.md)

## Video: Track customer credit
- Format: 3-6min deep dive
- Priority: P3
- Platforms: YouTube
- Tagline: "Give credit, collect it, and never forget who owes you."
- Description: The full customer credit flow on Custosell - record credit
  sales, watch balances, and collect. No more forgotten debt.
- What it's about: Credit sales, balances, and collection.
- Script beats:
  - Beat 1 (Hook): "Credit customers are great - until you forget."
  - Beat 2 (Problem): "Trusting memory for debt doesn't work."
  - Beat 3 (Action): Sale on credit -> open customer -> balance -> collect
    payment -> balance clears.
  - Beat 4 (Aha): "Every shilling owed is visible."
  - Beat 5 (CTA): "Try it free at custosell.com."
- Screen flow: /customers -> profile -> credit balance -> record collection.
- On-screen text / captions:
  - "Credit given. Credit tracked. Credit collected."
- Demo data needed: A customer with an outstanding balance.
- CTA: Try free + subscribe
- Related videos: [08-expenses-budgets-income.md](./08-expenses-budgets-income.md)

## Video: Email customers their receipts
- Format: 30-45s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok
- Tagline: "Receipts in their inbox, automatically."
- Description: Send a customer their receipts from Custosell - from the sale or
  from their profile - so they always have a copy.
- What it's about: Sending receipts to a customer's email.
- Script beats:
  - Beat 1 (Hook): "Your customer's receipts, sent in one tap."
  - Beat 2 (Problem): "Customers lose paper receipts."
  - Beat 3 (Action): Customer profile -> sales -> email receipt -> confirm.
  - Beat 4 (Aha): "History + receipts, both in their inbox."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /customers -> profile -> sales -> email receipt.
- On-screen text / captions:
  - "Receipt sent"
- Demo data needed: A customer with sales + an email.
- CTA: Try free
- Related videos: [03-sales-pos.md](./03-sales-pos.md)

---

## Technical reference (source of truth)

**Screens:** `/customers` [M], customer profile, `/sales/new` (assign at
checkout)

**User actions (FE hooks):** `useCreateCustomer` · `useUpdateCustomer` ·
`useDeleteCustomer` · `useCustomerSearch` · `useCustomerHistory` ·
`useAssignSaleCustomer` · `useResolveCustomerContact` · `useCustomerCredit` ·
`useRecordCreditCollection` · `useEmailCustomerReceipt`

**API endpoints (BE):** `/customers` CRUD + `/customers/search` +
`/customers/{id}/history` + `/customers/{id}/credit` +
`/customers/{id}/credit-collect` + `/customers/{id}/email-receipt` ·
`/sales/{id}/customer`