# 18 - Quick Notes

**Videos in this pack: 2**

Jot it down. Small notes that stay with your business.

## Video: Save a quick note
- Format: 30-45s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok
- Tagline: "A note in 3 seconds - and it's never lost."
- Description: Use quick notes on Custosell to capture reminders, customer
  preferences, and ideas right where your business lives.
- What it's about: Quick note create/read flow.
- Script beats:
  - Beat 1 (Hook): "Write it down before it's gone."
  - Beat 2 (Problem): "Paper notes and phone scraps get lost."
  - Beat 3 (Action): /notes -> add -> type -> save -> find it later.
  - Beat 4 (Aha): "Searchable, everywhere, forever."
  - Beat 5 (CTA): "Try it free at custosell.com."
- Screen flow: /notes -> add -> save -> search.
- On-screen text / captions:
  - "Jot. Saved."
- Demo data needed: A business with a few notes.
- CTA: Try free
- Related videos: [18-quick-notes.md](./18-quick-notes.md)

## Video: Search your notes
- Format: 30-45s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok
- Tagline: "That note about the supplier? Found in seconds."
- Description: Search and organize your quick notes on Custosell - never lose
  a detail again.
- What it's about: Note search/organization.
- Script beats:
  - Beat 1 (Hook): "Notes are only useful if you can find them."
  - Beat 2 (Problem): "A pile of notes is just a pile."
  - Beat 3 (Action): /notes -> search -> filter -> open.
  - Beat 4 (Aha): "Every thought, retrievable."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /notes -> search -> result.
- On-screen text / captions:
  - "Find anything"
- Demo data needed: Several notes with varied content.
- CTA: Try free
- Related videos: [18-quick-notes.md](./18-quick-notes.md)

---

## Technical reference (source of truth)

**Screens:** `/notes` [M]

**User actions (FE hooks):** `useQuickNotes` · `useCreateQuickNote` ·
`useUpdateQuickNote` · `useDeleteQuickNote` · `useSearchQuickNotes`

**API endpoints (BE):** `/quick-notes` CRUD + `/quick-notes/search`