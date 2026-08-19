# 04 - Shifts

Start, close, and balance the working day. Cash drawer accountability.

## Video: Open your shift
- Format: 45-90s how-to
- Priority: P1
- Platforms: Reels / Shorts / TikTok / YouTube
- Tagline: "Open your shift in seconds - and know exactly how you start."
- Description: Learn how to open a shift on Custosell POS, set your opening
  cash, and start selling. Your cash drawer stays accountable from the first
  minute.
- What it's about: The shift-opening flow - start shift, record opening cash.
- Script beats:
  - Beat 1 (Hook): "Your day starts here - one tap."
  - Beat 2 (Problem): "Without a shift, who knows who sold what?"
  - Beat 3 (Action): /sales/my-shift -> Open shift -> set opening cash ->
    confirm.
  - Beat 4 (Aha): "Every sale now belongs to this shift."
  - Beat 5 (CTA): "Try it free at custosell.com."
- Screen flow: /sales/my-shift -> Open shift -> opening cash -> confirm.
- On-screen text / captions:
  - "Open in one tap"
  - "Cash drawer locked to this shift"
- Demo data needed: A seeded business with products.
- CTA: Try free + subscribe
- Related videos: [03-sales-pos.md](./03-sales-pos.md) (first sale)

## Video: Add cash to the drawer (money in/out)
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "Petty cash, change, float - track every shilling in and out."
- Description: Log cash movements on an open shift with Custosell. Record
  money added to the drawer or taken out, and keep your closing balance honest.
- What it's about: Cash in/out movements on the active shift.
- Script beats:
  - Beat 1 (Hook): "The drawer was short. Now it never is."
  - Beat 2 (Problem): "Extra cash in the drawer without a note = confusion."
  - Beat 3 (Action): /sales/my-shift -> Add cash -> pick type (in/out) ->
    amount -> reason -> save.
  - Beat 4 (Aha): "Every movement is logged against the shift."
  - Beat 5 (CTA): "Start free."
- Screen flow: /sales/my-shift -> Add cash -> type -> amount -> reason -> save.
- On-screen text / captions:
  - "Cash in. Cash out. Logged."
- Demo data needed: An open shift.
- CTA: Try free
- Related videos: [04-shifts.md](./04-shifts.md) (open shift)

## Video: Close your shift like a pro
- Format: 45-90s how-to
- Priority: P1
- Platforms: Reels / Shorts / TikTok / YouTube
- Tagline: "Close the day and balance the drawer - in one minute."
- Description: Close your shift on Custosell and get a full breakdown of cash,
  card, and everything in between. The exact workflow for end of day.
- What it's about: The shift-close flow and the end-of-shift report.
- Script beats:
  - Beat 1 (Hook): "This is how the day ends - clean."
  - Beat 2 (Problem): "Counting the drawer without a system invites mistakes."
  - Beat 3 (Action): /sales/my-shift -> Count cash -> Close shift -> review the
    summary (cash, card, net, expected balance).
  - Beat 4 (Aha): "Your whole day is summarized in one screen."
  - Beat 5 (CTA): "Try it free at custosell.com."
- Screen flow: /sales/my-shift -> Count cash -> close -> summary.
- On-screen text / captions:
  - "Count. Close. Balanced."
  - "Full day summary in one screen"
- Demo data needed: An open shift with several sales and a cash movement.
- CTA: Try free + subscribe
- Related videos: [02-dashboard-reports.md](./02-dashboard-reports.md)

## Video: Hand over the shift (staff changeover)
- Format: 45-90s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok
- Tagline: "Handing over the till? Keep it accountable."
- Description: Switch staff mid-day on Custosell - close one shift, open
  another, and keep every sale traceable to the right person.
- What it's about: Shift handover between two staff members.
- Script beats:
  - Beat 1 (Hook): "Morning team out, afternoon team in - still accounted for."
  - Beat 2 (Problem): "Shared tills hide who really sold what."
  - Beat 3 (Action): Close current shift -> next staff opens theirs -> show the
    per-shift sales view.
  - Beat 4 (Aha): "Sales are always tied to a person."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /sales/my-shift -> close -> open new shift -> /sales/history
  filtered by shift.
- On-screen text / captions:
  - "Every sale has an owner"
- Demo data needed: Two staff users on a business.
- CTA: Try free
- Related videos: [03-sales-pos.md](./03-sales-pos.md)

---

## Technical reference (source of truth)

**Screens:** `/sales/my-shift` [M], `/sales/history` (filter by shift),
`/sales/refunds` (per shift)

**User actions (FE hooks):** `useOpenShift` (opening cash) ·
`useCloseShift` · `useShiftCashMovements` (money in/out) ·
`useTrackShiftLog` (shift log)

**API endpoints (BE):** `/shifts` CRUD · `/shifts/active` (current shift) ·
`/shifts/{id}/close` · `/shifts/{id}/cash-movements` · `/shifts/{id}/report` ·
`/sales/daily` (day rollup per shift)