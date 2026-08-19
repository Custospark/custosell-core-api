# 16 - Subscriptions & Billing

**Videos in this pack: 3**

Plans, billing, and what you pay for.

## Video: Choose the right plan
- Format: 45-90s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok / YouTube
- Tagline: "Which Custosell plan fits your shop?"
- Description: Compare Custosell plans and pick the one that fits your shop
  size and features - and upgrade without losing anything.
- What it's about: Plan comparison + upgrade.
- Script beats:
  - Beat 1 (Hook): "Not sure which plan? Here's how to choose."
  - Beat 2 (Problem): "Paying for features you don't use is wasted money."
  - Beat 3 (Action): /billing -> plans -> compare features -> select -> confirm.
  - Beat 4 (Aha): "Data and history stay with you no matter the plan."
  - Beat 5 (CTA): "Try free at custosell.com."
- Screen flow: /billing -> plans -> compare -> upgrade.
- On-screen text / captions:
  - "Pick what fits. Not more."
- Demo data needed: A free/current plan to upgrade from.
- CTA: Try free + subscribe
- Related videos: [17-settings.md](./17-settings.md)

## Video: See your billing history
- Format: 30-45s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok
- Tagline: "Every invoice and payment, in one place."
- Description: Review your Custosell billing history - invoices, payments, and
  receipts - whenever you need them.
- What it's about: Billing/invoice history.
- Script beats:
  - Beat 1 (Hook): "Proof of payment, one tap away."
  - Beat 2 (Problem): "Receipts get buried in email."
  - Beat 3 (Action): /billing -> history -> open invoice -> download.
  - Beat 4 (Aha): "Whole billing trail, filed."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /billing -> history -> invoice -> download.
- On-screen text / captions:
  - "Billing trail, all here"
- Demo data needed: At least one billing invoice.
- CTA: Try free
- Related videos: [10-documents.md](./10-documents.md)

## Video: Update your payment method
- Format: 45-90s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok
- Tagline: "New card, new number - update in seconds."
- Description: Change the payment method on your Custosell subscription safely
  and confirm billing goes to the right place.
- What it's about: Payment method update.
- Script beats:
  - Beat 1 (Hook): "Change cards without missing a payment."
  - Beat 2 (Problem): "An expired card can pause your shop."
  - Beat 3 (Action): /billing -> payment method -> update -> confirm.
  - Beat 4 (Aha): "Billing keeps flowing, zero effort."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /billing -> payment method -> update -> confirm.
- On-screen text / captions:
  - "Billing, updated"
- Demo data needed: A subscription to update.
- CTA: Try free
- Related videos: [16-subscriptions-billing.md](./16-subscriptions-billing.md)

---

## Technical reference (source of truth)

**Screens:** `/billing` [M] (plans, history, payment method)

**User actions (FE hooks):** `usePlans` · `useSubscription` ·
`useUpgradePlan` · `useBillingHistory` · `usePaymentMethod` ·
`useUpdatePaymentMethod`

**API endpoints (BE):** `/plans` · `/subscriptions` (current) ·
`/subscriptions/upgrade` · `/billing/history` · `/billing/payment-method`