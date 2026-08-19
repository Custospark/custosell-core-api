# 06 - Marketplace & Purchase Orders

Buy smarter. Restock through the marketplace and track purchase orders.

## Video: Restock from the marketplace
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok / YouTube
- Tagline: "Restock in minutes from the marketplace."
- Description: Browse the Custosell marketplace, find suppliers, and add stock
  to your inventory in a few taps. Buying smarter starts here.
- What it's about: Marketplace browse + stock-from-supplier flow.
- Script beats:
  - Beat 1 (Hook): "Your next restock is a few taps away."
  - Beat 2 (Problem): "Finding suppliers is the slow part of restocking."
  - Beat 3 (Action): /marketplace -> browse suppliers -> view products -> add
    to stock/P.O. -> confirm.
  - Beat 4 (Aha): "Stock added with supplier + cost recorded."
  - Beat 5 (CTA): "Try it free at custosell.com."
- Screen flow: /marketplace -> suppliers -> products -> add -> inventory
  updated.
- On-screen text / captions:
  - "Browse. Add. Stocked."
- Demo data needed: A marketplace with suppliers and products.
- CTA: Try free + subscribe
- Related videos: [05-inventory-products.md](./05-inventory-products.md)

## Video: Create a purchase order
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "Order stock without losing track."
- Description: Create a purchase order on Custosell - choose a supplier, list
  the items, and send it. Track it until it arrives.
- What it's about: Purchase order creation and tracking.
- Script beats:
  - Beat 1 (Hook): "Purchase orders that track themselves."
  - Beat 2 (Problem): "Phone-call orders get forgotten."
  - Beat 3 (Action): /purchase-orders -> create -> supplier -> items -> save/send.
  - Beat 4 (Aha): "Status updates as it moves - ordered, received."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /purchase-orders -> create -> supplier -> items -> save.
- On-screen text / captions:
  - "Ordered. Tracked. Received."
- Demo data needed: A supplier and low-stock products.
- CTA: Try free
- Related videos: [05-inventory-products.md](./05-inventory-products.md)

## Video: Receive stock (approve a P.O.)
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "Stock arrived? Receive it and update inventory in one tap."
- Description: Mark a purchase order as received on Custosell and watch your
  stock levels update automatically - with the correct cost recorded.
- What it's about: Purchase order receive/approve flow.
- Script beats:
  - Beat 1 (Hook): "The truck arrived. Inventory already knows."
  - Beat 2 (Problem): "Receiving stock by hand = mistakes."
  - Beat 3 (Action): /purchase-orders -> open received order -> confirm -> show
    stock levels updated.
  - Beat 4 (Aha): "Cost and stock update together, correctly."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /purchase-orders -> receive -> confirm -> /inventory/products.
- On-screen text / captions:
  - "Received. Stocked. Costed."
- Demo data needed: A P.O. awaiting delivery.
- CTA: Try free
- Related videos: [09-accounting.md](./09-accounting.md)

---

## Technical reference (source of truth)

**Screens:** `/marketplace` [M], `/marketplace/suppliers`,
`/purchase-orders` [M]

**User actions (FE hooks):** `useMarketplaceBrowse` · `useMarketplaceAddStock`
· `useCreatePurchaseOrder` · `useUpdatePurchaseOrder` ·
`useReceivePurchaseOrder` · `useCancelPurchaseOrder` · `useSupplierList`

**API endpoints (BE):** `/marketplace/browse` · `/marketplace/add-stock` ·
`/purchase-orders` CRUD + `/purchase-orders/{id}/receive` +
`/purchase-orders/{id}/cancel` · `/suppliers`