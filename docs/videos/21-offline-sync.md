# 21 - Offline-First / Sync

**Videos in this pack: 4**

The differentiator. Custosell keeps working when the internet doesn't. This is
the moat - film it well.

## Video: Sell even when the internet is down
- Format: 45-90s how-to
- Priority: P1
- Platforms: Reels / Shorts / TikTok / YouTube
- Tagline: "Internet down? You're still selling."
- Description: Custosell POS works offline. Ring up sales, take payments, and
  keep your day moving even when Wi-Fi or mobile data drops. Sales sync
  automatically when you're back online.
- What it's about: The offline-first selling flow - the core promise.
- Script beats:
  - Beat 1 (Hook): "Watch what happens when the internet dies mid-sale."
  - Beat 2 (Problem): "Shops stop selling when the network does. Yours won't."
  - Beat 3 (Action): Start a sale -> toggle offline (simulate) -> complete
    checkout -> reconnect -> show the sale appear in history + dashboard.
  - Beat 4 (Aha): "It synced itself - no clicks, no data entry."
  - Beat 5 (CTA): "This is why shops switch. Try free at custosell.com."
- Screen flow: /sales/new -> cart -> offline banner -> checkout -> reconnect ->
  /sales/history -> dashboard KPI updated.
- On-screen text / captions:
  - "Offline. Still selling."
  - "Synced when you're back"
- Demo data needed: A seeded business; demonstrate the offline banner
  (use browser DevTools Network tab to go offline).
- CTA: Try free + subscribe
- Related videos: [03-sales-pos.md](./03-sales-pos.md) (first sale),
  [04-shifts.md](./04-shifts.md)

## Video: What syncs automatically
- Format: 3-6min deep dive
- Priority: P2
- Platforms: YouTube
- Tagline: "Everything you can do offline - and what happens when you reconnect."
- Description: A deep look at Custosell's offline queue. Which actions work
  without internet, how conflicts are handled, and what syncs when you
  reconnect. Perfect for owners deciding to switch.
- What it's about: The full offline capability map and sync behavior.
- Script beats:
  - Beat 1 (Hook): "Let's break the internet and watch Custosell not care."
  - Beat 2 (Problem): "Most POS systems are bricks without Wi-Fi."
  - Beat 3 (Action): Go offline -> sell -> record payment -> add a product ->
    take a customer -> reconnect -> show the sync queue drain.
  - Beat 4 (Aha): "Sales, payments, stock, customers - all queued and synced."
  - Beat 5 (CTA): "Ready to stop fearing outages? Try free."
- Screen flow: Multi-module tour while offline, then reconnect and show sync
  activity/log.
- On-screen text / captions:
  - "Queued while offline"
  - "Synced on reconnect"
- Demo data needed: Products, customers, a running shift.
- CTA: Try free + subscribe
- Related videos: [21-offline-sync.md](./21-offline-sync.md) (short version),
  [05-inventory-products.md](./05-inventory-products.md)

## Video: Check your sync health
- Format: 30-45s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok
- Tagline: "Wondering if your data made it? Check sync status."
- Description: See the sync status of your Custosell app - what's pending,
  what's failed, and what's up to date - so you always know your data is safe.
- What it's about: The sync status/log screen.
- Script beats:
  - Beat 1 (Hook): "Your data is safe. Here's how you know."
  - Beat 2 (Problem): "You can't trust what you can't see."
  - Beat 3 (Action): Open sync status -> show pending/failed/up-to-date -> tap
    retry on any failure.
  - Beat 4 (Aha): "One screen shows every byte's status."
  - Beat 5 (CTA): "Try it free."
- Screen flow: Settings -> Sync status -> list -> retry.
- On-screen text / captions:
  - "Pending: 0. All synced."
- Demo data needed: A business with a couple of offline-created sales.
- CTA: Try free
- Related videos: [21-offline-sync.md](./21-offline-sync.md)

## Video: No-internet setup (first-time offline install)
- Format: 45-90s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok
- Tagline: "Set up Custosell once, and it works offline from day one."
- Description: Install and set up Custosell, and understand how the app caches
  itself so the shop keeps working with or without internet.
- What it's about: First-run offline readiness - install, cache, and offline
  boot.
- Script beats:
  - Beat 1 (Hook): "Set it up on Wi-Fi. Use it anywhere."
  - Beat 2 (Problem): "Shops in low-coverage areas need tools that adapt."
  - Beat 3 (Action): Install/sign in on Wi-Fi -> go offline -> open the app
    from the home screen -> it still loads.
  - Beat 4 (Aha): "The app itself is cached - it boots offline."
  - Beat 5 (CTA): "Try free at custosell.com."
- Screen flow: Install -> sign in -> add to home screen -> offline boot.
- On-screen text / captions:
  - "Installs on Wi-Fi. Works anywhere."
- Demo data needed: Fresh install flow.
- CTA: Try free + subscribe
- Related videos: [01-account-auth-security.md](./01-account-auth-security.md)

---

## Technical reference (source of truth)

**Screens:** `/sales/new` (offline banner), Settings -> Sync status, sync
activity log

**User actions (FE hooks):** `useSyncQueue` · `useSyncStatus` ·
`useRetrySync` · `useOfflineSalesBatch` (queue drain on reconnect) ·
`useCreateSale` (offline path writes to local queue first)

**API endpoints (BE):** `/sales/batch` (bulk sync) · `/sync/status` ·
`/sync/activity` · `/sync/retry`