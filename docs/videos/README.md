# Custosell - Video Content Pack

Handover-ready video briefs for Custosell. Every file in this folder is a
production pack: a producer can open a module file, pick any video block, and
make the video without asking questions.

Each module may contain **as many videos as it can support**. Every video block
follows the same standard format:

```
## Video: <Title>
- Format: 45-90s how-to | 30s hook | 3-6min deep dive
- Priority: P1/P2/P3
- Platforms: Reels / Shorts / TikTok / YouTube / Site embed
- Tagline:        (the 3-second hook line)
- Description:    (YouTube description box text, keywords included)
- What it's about: 2-3 sentence summary for the producer
- Script beats:   Beat 1 Hook -> Beat 2 Problem -> Beat 3 The action
                  (step-by-step, with exact clicks) -> Beat 4 Aha -> Beat 5 CTA
- Screen flow:    exact route(s) to open, what to click, in order
- On-screen text / captions: (word-for-word text overlays)
- Demo data needed: (which seeded records to have ready)
- CTA:            (subscribe / comment / try free + ?utm link)
- Related videos: (links to other module files for the end-screen)
```

**How the briefs were derived:** from the product's actual code - the frontend
query/mutation hooks and the backend routes - because queries and routes are
the user's verbs. The compact Technical reference at the bottom of each module
file lists the hooks + endpoints behind that module's videos.

---

## Module index

| # | Module | File | Videos | Wave |
|---|--------|------|--------|------|
| 1 | Account, Auth & Security | [01-account-auth-security.md](./01-account-auth-security.md) | 16 | 4 |
| 2 | Dashboard & Reports | [02-dashboard-reports.md](./02-dashboard-reports.md) | 20 | 3 |
| 3 | Sales & POS | [03-sales-pos.md](./03-sales-pos.md) | 34 | 1 |
| 4 | Shifts | [04-shifts.md](./04-shifts.md) | 10 | 1 |
| 5 | Inventory - Products & Categories | [05-inventory-products.md](./05-inventory-products.md) | 25 | 2 |
| 6 | Marketplace & Purchase Orders | [06-marketplace-purchase-orders.md](./06-marketplace-purchase-orders.md) | 19 | 2 |
| 7 | Customers | [07-customers.md](./07-customers.md) | 7 | 2 |
| 8 | Expenses, Budgets & Income | [08-expenses-budgets-income.md](./08-expenses-budgets-income.md) | 10 | 2 |
| 9 | Accounting | [09-accounting.md](./09-accounting.md) | 13 | 3 |
| 10 | Documents Vault | [10-documents.md](./10-documents.md) | 10 | 3 |
| 11 | Pipeline / CRM | [11-pipeline-crm.md](./11-pipeline-crm.md) | 18 | 3 |
| 12 | Estimates, Templates & Projects | [12-estimates-projects.md](./12-estimates-projects.md) | 26 | 2 |
| 13 | Forecasting | [13-forecasting.md](./13-forecasting.md) | 8 | 3 |
| 14 | Storefront / B2C | [14-storefront.md](./14-storefront.md) | 12 | 3 |
| 15 | HR | [15-hr.md](./15-hr.md) | 16 | 3 |
| 16 | Subscriptions & Billing | [16-subscriptions-billing.md](./16-subscriptions-billing.md) | 8 | 4 |
| 17 | Settings | [17-settings.md](./17-settings.md) | 11 | 4 |
| 18 | Quick Notes | [18-quick-notes.md](./18-quick-notes.md) | 5 | 4 |
| 19 | Notifications & Web Push | [19-notifications-webpush.md](./19-notifications-webpush.md) | 4 | 4 |
| 20 | Referrals, Credits & Sales Reps | [20-referrals-credits.md](./20-referrals-credits.md) | 6 | 4 |
| 21 | Offline-First / Sync | [21-offline-sync.md](./21-offline-sync.md) | 4 | 1 |
| 22 | Guide | [22-guide.md](./22-guide.md) | 5 | 4 |
| **Total** | | | **287** | |

---

## House style (recording checklist)

- Vertical 9:16, browser zoom ~120% so UI stays legible cropped.
- Captions on every video (most watch muted).
- Demo data: a realistic UGX business (products like "Blue Band 500g", real
  customer names, an actual supplier) - never empty screens.
- One job per video, one promise: "By the end of this, you can ___."
- Cursor highlighted; no stray tabs/notifications during takes.
- End every video with a CTA: comment / subscribe / try free (link with
  `?utm` per playlist for measurement).

---

## Filming order (batch by wave, record all in one sitting)

| Wave | Playlist | Modules | Why first |
|---|---|---|---|
| 1 - Sell & cash | Setup, First sale, Shift close, Offline | 3, 4, 21 | Money loop + differentiator |
| 2 - Run daily | Products, Marketplace/POs, Customers, Expenses, Estimates | 5, 6, 7, 8, 12 | Fastest time-to-value |
| 3 - Manage | Dashboard/Reports, Accounting, Documents, Pipeline, Forecasting, Storefront, HR | 2, 9, 10, 11, 13, 14, 15 | Depth + adoption |
| 4 - Grow | Account/Security, Billing, Settings, Quick Notes, Notifications, Referrals | 1, 16, 17, 18, 19, 20 | Growth + retention |

**Batching rule:** record all episodes of one wave in a single sitting (same
demo data, same cursor/zoom, same intro), then slice each into a <=30s hook
clip for Reels/Shorts/TikTok.

---

## Measurement (what "working" looks like)

- **Watch-to-completion** (not just views) on the 45-90s videos.
- **"Try Custosell" clicks** from video to site - give each wave a `?utm` link
  per playlist.
- **Repeat view rate** - the Offline video (module 21) should have people
  re-watching.

---

## Keeping this pack in sync

Source of truth for what a user can do:

- FE routes: `Frontend/src/renderer/app/routes/index.tsx` +
  `Frontend/src/renderer/app/routes/constants/shared.paths.ts`
- FE actions: `Frontend/src/renderer/modules/**/api/*Queries.ts` +
  `Frontend/src/renderer/shared/api/**`
- BE endpoints: `Backend/routes/api.php` + `Backend/routes/api/v1/*.php`

When a new feature ships (new hooks/routes), add a video block to the relevant
module file above, following the same standard format.