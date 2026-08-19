# 11 - Pipeline / CRM

**Videos in this pack: 3**

Turn leads into customers. Sales pipeline, quotes, and follow-ups.

## Video: Track a lead from hello to customer
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok / YouTube
- Tagline: "No lead left behind."
- Description: Add leads to your Custosell pipeline, move them through stages,
  and close them. A simple CRM built for shop businesses.
- What it's about: Pipeline stage tracking.
- Script beats:
  - Beat 1 (Hook): "Your next big customer is already in your phone. Put them
    in a pipeline."
  - Beat 2 (Problem): "Leads in WhatsApp threads get forgotten."
  - Beat 3 (Action): /pipeline -> add lead -> move through stages -> close.
  - Beat 4 (Aha): "Every opportunity has a place and a next step."
  - Beat 5 (CTA): "Try it free at custosell.com."
- Screen flow: /pipeline -> add -> stages -> close.
- On-screen text / captions:
  - "Add. Nurture. Close."
- Demo data needed: A few leads in various stages.
- CTA: Try free + subscribe
- Related videos: [07-customers.md](./07-customers.md)

## Video: Follow up without forgetting
- Format: 45-90s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok
- Tagline: "Follow-ups that actually happen."
- Description: Set follow-up reminders on your Custosell pipeline so no lead
  goes cold - and see what's due today.
- What it's about: Follow-up/reminder tracking on pipeline items.
- Script beats:
  - Beat 1 (Hook): "The fortune is in the follow-up."
  - Beat 2 (Problem): "You meant to call back. You didn't."
  - Beat 3 (Action): Lead -> set follow-up date -> dashboard/notifications show
    it -> mark done.
  - Beat 4 (Aha): "The app reminds you - you just close."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /pipeline -> lead -> follow-up -> notification -> done.
- On-screen text / captions:
  - "Remind me. And I close."
- Demo data needed: A lead with a follow-up date.
- CTA: Try free
- Related videos: [19-notifications-webpush.md](./19-notifications-webpush.md)

## Video: Convert a lead into a sale
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "Lead said yes? Close it into a sale in one flow."
- Description: Turn a won pipeline lead straight into a Custosell sale or
  invoice - no re-typing.
- What it's about: Lead-to-sale conversion.
- Script beats:
  - Beat 1 (Hook): "They said yes. Don't make them wait."
  - Beat 2 (Problem): "Winning the deal is half - closing the sale is the other."
  - Beat 3 (Action): Pipeline -> won -> convert -> sale/invoice opens pre-filled.
  - Beat 4 (Aha): "Converted with all the details intact."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /pipeline -> convert -> /sales/new or /invoices pre-filled.
- On-screen text / captions:
  - "Won -> Sold"
- Demo data needed: A pipeline lead in the 'won' stage.
- CTA: Try free
- Related videos: [03-sales-pos.md](./03-sales-pos.md)

---

## Technical reference (source of truth)

**Screens:** `/pipeline` [M], lead detail, `/sales/new` (conversion target)

**User actions (FE hooks):** `usePipeline` · `useCreateLead` ·
`useUpdateLead` · `useMoveStage` · `useLeadFollowUp` ·
`useConvertLeadToSale` · `usePipelineStages`

**API endpoints (BE):** `/pipeline` CRUD + `/pipeline/{id}/stage` +
`/pipeline/{id}/follow-up` + `/pipeline/{id}/convert` · `/pipeline/stages`