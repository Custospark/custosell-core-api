# 19 - Notifications & Web Push

The app tells you what matters - low stock, payments, follow-ups.

## Video: Get notified when stock runs low
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok / YouTube
- Tagline: "Low stock? Your phone tells you."
- Description: Enable stock alerts on Custosell and get notified before you run
  out - no more mid-sale surprises.
- What it's about: Stock alert / notification setup.
- Script beats:
  - Beat 1 (Hook): "Your phone just saved you a disappointed customer."
  - Beat 2 (Problem): "You only notice low stock at the till."
  - Beat 3 (Action): Settings -> notifications -> enable stock alerts -> show a
    low-stock notification arriving.
  - Beat 4 (Aha): "Restock before the problem happens."
  - Beat 5 (CTA): "Try it free at custosell.com."
- Screen flow: Settings -> notifications -> enable -> notification arrives.
- On-screen text / captions:
  - "Notified before it's empty"
- Demo data needed: A product near its low-stock threshold.
- CTA: Try free + subscribe
- Related videos: [05-inventory-products.md](./05-inventory-products.md)

## Video: Never miss a payment or follow-up
- Format: 45-90s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok
- Tagline: "Payments and follow-ups that remind themselves."
- Description: Get web-push notifications on Custosell for due payments,
  invoice activity, and pipeline follow-ups - so nothing slips.
- What it's about: Payment/invoice/follow-up notifications.
- Script beats:
  - Beat 1 (Hook): "The app nags you - so customers don't."
  - Beat 2 (Problem): "Silent invoices get paid slowly."
  - Beat 3 (Action): Enable notifications -> show a payment-due alert -> tap it ->
    open the invoice.
  - Beat 4 (Aha): "One tap from notification to action."
  - Beat 5 (CTA): "Try it free."
- Screen flow: Settings -> notifications -> alert -> invoice.
- On-screen text / captions:
  - "Reminded. Handled."
- Demo data needed: An unpaid invoice + a pipeline follow-up due.
- CTA: Try free
- Related videos: [03-sales-pos.md](./03-sales-pos.md),
  [11-pipeline-crm.md](./11-pipeline-crm.md)

## Video: Manage notification preferences
- Format: 30-45s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok
- Tagline: "Get the alerts you want - and silence the rest."
- Description: Control which notifications Custosell sends - stock, payments,
  reports - so your phone stays useful, not noisy.
- What it's about: Notification preference toggles.
- Script beats:
  - Beat 1 (Hook): "Alerts should help, not annoy."
  - Beat 2 (Problem): "Too many pings = ignored pings."
  - Beat 3 (Action): Settings -> notifications -> toggle each type -> done.
  - Beat 4 (Aha): "Only what matters, only when you want."
  - Beat 5 (CTA): "Try it free."
- Screen flow: Settings -> notifications -> toggles.
- On-screen text / captions:
  - "Your alerts, your rules"
- Demo data needed: Notifications enabled.
- CTA: Try free
- Related videos: [19-notifications-webpush.md](./19-notifications-webpush.md)

---

## Technical reference (source of truth)

**Screens:** Settings -> Notifications, notification center/bell,
web-push prompts

**User actions (FE hooks):** `useNotifications` (list) ·
`useNotificationPreferences` · `useEnableWebPush` ·
`useNotificationRead` · `useStockAlerts`

**API endpoints (BE):** `/notifications` (list/mark-read) +
`/notifications/preferences` + `/notifications/webpush` +
`/notifications/stock-alerts`