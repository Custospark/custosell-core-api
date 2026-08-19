# 14 - Storefront / B2C

Sell beyond the till. Your online storefront.

## Video: Publish your online storefront
- Format: 3-6min deep dive
- Priority: P3
- Platforms: YouTube
- Tagline: "Your shop, online, in minutes."
- Description: Publish a Custosell storefront - your products go online with a
  link you can share. Sell after hours without extra setup.
- What it's about: Storefront publish + product visibility.
- Script beats:
  - Beat 1 (Hook): "Your shop now works while you sleep."
  - Beat 2 (Problem): "Closing at 6pm means stopping at 6pm."
  - Beat 3 (Action): /storefront -> publish -> pick products -> share the link ->
    show the live storefront.
  - Beat 4 (Aha): "One link, your whole catalog, always open."
  - Beat 5 (CTA): "Try it free at custosell.com."
- Screen flow: /storefront -> publish -> product picker -> preview -> share.
- On-screen text / captions:
  - "Open 24/7"
- Demo data needed: A full product catalog.
- CTA: Try free + subscribe
- Related videos: [03-sales-pos.md](./03-sales-pos.md)

## Video: Customize your storefront
- Format: 45-90s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok
- Tagline: "Make your storefront look like your shop."
- Description: Brand your Custosell storefront - shop name, logo, colors, and
  a welcome message that matches your business.
- What it's about: Storefront branding/customization.
- Script beats:
  - Beat 1 (Hook): "Same shop, now with your name on it."
  - Beat 2 (Problem): "A generic storefront looks untrustworthy."
  - Beat 3 (Action): /storefront -> settings -> logo -> colors -> name ->
    preview.
  - Beat 4 (Aha): "Recognizable = buyable."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /storefront -> settings -> branding -> preview.
- On-screen text / captions:
  - "Your brand, online"
- Demo data needed: A logo image file.
- CTA: Try free
- Related videos: [14-storefront.md](./14-storefront.md)

## Video: Get an order from your storefront
- Format: 45-90s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok
- Tagline: "Someone ordered online. Here's what happens."
- Description: See how a storefront order lands in your Custosell app - view it
  in sales, fulfill it, and keep inventory in sync.
- What it's about: Storefront order -> in-app order flow.
- Script beats:
  - Beat 1 (Hook): "Online orders meet your real inventory."
  - Beat 2 (Problem): "Online and offline sales shouldn't be two worlds."
  - Beat 3 (Action): Place a storefront order -> open /sales or /orders in-app ->
    fulfill -> stock updates.
  - Beat 4 (Aha): "One stock count, every channel."
  - Beat 5 (CTA): "Try it free."
- Screen flow: Storefront order -> /orders -> fulfill -> inventory.
- On-screen text / captions:
  - "Every channel, one stock"
- Demo data needed: A published storefront.
- CTA: Try free
- Related videos: [05-inventory-products.md](./05-inventory-products.md)

---

## Technical reference (source of truth)

**Screens:** `/storefront` [M] (publish + settings + preview)

**User actions (FE hooks):** `usePublishStorefront` ·
`useStorefrontSettings` · `useStorefrontProducts` ·
`useStorefrontOrders`

**API endpoints (BE):** `/storefront` (publish/config) +
`/storefront/products` + `/storefront/orders`