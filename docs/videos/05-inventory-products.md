# 05 - Inventory - Products & Categories

**Videos in this pack: 5**

Keep stock honest. Products, categories, stock levels, and reordering.

## Video: Add your first product
- Format: 45-90s how-to
- Priority: P1
- Platforms: Reels / Shorts / TikTok / YouTube
- Tagline: "Add a product once - sell it forever."
- Description: Learn how to add a product to Custosell inventory - name, price,
  cost, stock, and category. Everything you need to start selling in minutes.
- What it's about: The create-product flow.
- Script beats:
  - Beat 1 (Hook): "Your catalog starts here."
  - Beat 2 (Problem): "A product list is only useful if it's easy to build."
  - Beat 3 (Action): /inventory/products -> Add product -> name, price, cost,
    stock -> category -> save.
  - Beat 4 (Aha): "That's it - it's sellable right now."
  - Beat 5 (CTA): "Try it free at custosell.com."
- Screen flow: /inventory/products -> Add -> form -> save -> list shows it.
- On-screen text / captions:
  - "Add. Price. Save. Sell."
- Demo data needed: An existing category.
- CTA: Try free + subscribe
- Related videos: [03-sales-pos.md](./03-sales-pos.md)

## Video: Organize products into categories
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "A tidy catalog is a faster till."
- Description: Create and manage categories in Custosell - group products,
  rename, and keep the POS fast to use.
- What it's about: Category CRUD and organizing products.
- Script beats:
  - Beat 1 (Hook): "Faster sales start with a tidy catalog."
  - Beat 2 (Problem): "A flat product list slows everyone down."
  - Beat 3 (Action): /inventory/categories -> add category -> assign products ->
    show the POS grouped by category.
  - Beat 4 (Aha): "The till just got faster for every cashier."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /inventory/categories -> create -> assign -> /sales/new grouped.
- On-screen text / captions:
  - "Group it. Find it. Sell it."
- Demo data needed: Several uncategorized products.
- CTA: Try free
- Related videos: [05-inventory-products.md](./05-inventory-products.md)

## Video: Adjust stock (counts, corrections)
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "Stock count wrong? Fix it with a reason attached."
- Description: Make manual stock adjustments in Custosell with a reason, so
  every change is traceable and your numbers stay trustworthy.
- What it's about: Stock adjustment / count corrections.
- Script beats:
  - Beat 1 (Hook): "Stock corrections that explain themselves."
  - Beat 2 (Problem): "Silent stock edits destroy your numbers."
  - Beat 3 (Action): Product -> Adjust stock -> new count -> reason (count,
    damage, etc.) -> save.
  - Beat 4 (Aha): "Every adjustment is logged and reportable."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /inventory/products -> open product -> Adjust stock -> reason ->
  save -> activity log.
- On-screen text / captions:
  - "Change. Reason. Traceable."
- Demo data needed: A product with a known stock level.
- CTA: Try free
- Related videos: [06-marketplace-purchase-orders.md](./06-marketplace-purchase-orders.md)

## Video: Track stock levels at a glance
- Format: 30-45s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "Out of stock before you run out of stock."
- Description: Read your stock level indicator on Custosell - see what's low,
  what's out, and what needs restocking at a glance.
- What it's about: Stock level badges and low-stock visibility.
- Script beats:
  - Beat 1 (Hook): "Your stock levels, color-coded."
  - Beat 2 (Problem): "Running out mid-sale is a bad look."
  - Beat 3 (Action): /inventory/products -> sort by stock level -> show low/out
    badges.
  - Beat 4 (Aha): "Restock before you disappoint a customer."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /inventory/products -> stock columns/badges -> low-stock view.
- On-screen text / captions:
  - "Low. Out. Order now."
- Demo data needed: Products at various stock levels including low and out.
- CTA: Try free
- Related videos: [06-marketplace-purchase-orders.md](./06-marketplace-purchase-orders.md)

## Video: Deactivate a product (stop selling)
- Format: 30-45s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok
- Tagline: "Stop selling a product without deleting its history."
- Description: Deactivate a discontinued product on Custosell - it leaves your
  POS but keeps its sales history and reports intact.
- What it's about: Product deactivation vs deletion.
- Script beats:
  - Beat 1 (Hook): "Stop selling it. Keep its history."
  - Beat 2 (Problem): "Deleting a product erases its sales records."
  - Beat 3 (Action): Product -> Deactivate -> confirm -> show it gone from POS,
    still in history/reports.
  - Beat 4 (Aha): "History stays, till is clean."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /inventory/products -> product -> Deactivate -> /sales/new (not
  visible) -> /reports (still counted).
- On-screen text / captions:
  - "Gone from the till. Kept in the books."
- Demo data needed: A product with past sales.
- CTA: Try free
- Related videos: [09-accounting.md](./09-accounting.md)

---

## Technical reference (source of truth)

**Screens:** `/inventory/products` [M], `/inventory/categories` [M],
`/inventory` (stock overview)

**User actions (FE hooks):** `useCreateProduct` · `useUpdateProduct` ·
`useDeleteProduct` / `useDeactivateProduct` · `useAdjustStock` ·
`useCreateCategory` · `useUpdateCategory` · `useDeleteCategory` ·
`useProductSuggestions` · `useImportProducts`

**API endpoints (BE):** `/products` CRUD · `/products/{id}/stock-adjustments` ·
`/categories` CRUD · `/products/import` · `/inventory/summary`