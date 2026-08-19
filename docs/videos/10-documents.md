# 10 - Documents Vault

Every PDF and receipt, stored and findable. Tax season handled.

## Video: Store documents automatically
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok / YouTube
- Tagline: "Every receipt and invoice, filed without lifting a finger."
- Description: Custosell's documents vault stores receipts, invoices, and
  reports automatically - searchable forever. Stop losing paper.
- What it's about: Auto-filed documents from business actions.
- Script beats:
  - Beat 1 (Hook): "Lose a receipt again? Not anymore."
  - Beat 2 (Problem): "Paper piles up and tax season hurts."
  - Beat 3 (Action): Generate a sale/invoice -> open /documents -> show it
    filed -> search.
  - Beat 4 (Aha): "Every document is there, forever."
  - Beat 5 (CTA): "Try it free at custosell.com."
- Screen flow: /documents -> search -> open a recent receipt.
- On-screen text / captions:
  - "Filed automatically"
- Demo data needed: A few generated documents.
- CTA: Try free + subscribe
- Related videos: [03-sales-pos.md](./03-sales-pos.md)

## Video: Find any document in seconds
- Format: 30-45s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok
- Tagline: "That invoice from March? Found it."
- Description: Search the documents vault on Custosell by type, date, or
  keyword - and export or print whatever you need.
- What it's about: Document search/filter/export.
- Script beats:
  - Beat 1 (Hook): "Find any paper in the shop in seconds."
  - Beat 2 (Problem): "Searching paper files is a treasure hunt."
  - Beat 3 (Action): /documents -> filter by type/date -> open -> export/print.
  - Beat 4 (Aha): "Searchable, printable, professional."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /documents -> filters -> open -> export.
- On-screen text / captions:
  - "Search. Find. Done."
- Demo data needed: Documents of multiple types.
- CTA: Try free
- Related videos: [10-documents.md](./10-documents.md)

---

## Technical reference (source of truth)

**Screens:** `/documents` [M] (vault list + search + filters)

**User actions (FE hooks):** `useDocuments` (list) · `useDocumentSearch` ·
`useDocumentUpload` · `useDocumentExport` · `useDocumentDelete`

**API endpoints (BE):** `/documents` CRUD + `/documents/search` +
`/documents/upload` + `/documents/export`