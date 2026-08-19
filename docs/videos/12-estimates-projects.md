# 12 - Estimates, Templates & Projects

Quote before you sell. Estimates, reusable templates, and project tracking.

## Video: Quote a customer in minutes
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok / YouTube
- Tagline: "Send a quote before the customer walks away."
- Description: Create an estimate on Custosell, send it, and track whether it's
  accepted. Close deals faster with professional quotes.
- What it's about: Estimate creation + send.
- Script beats:
  - Beat 1 (Hook): "Quote now. They decide later. You follow up."
  - Beat 2 (Problem): "Handwritten quotes look unprofessional and get lost."
  - Beat 3 (Action): /estimates -> create -> items -> total -> send -> status.
  - Beat 4 (Aha): "You can see if they opened it."
  - Beat 5 (CTA): "Try it free at custosell.com."
- Screen flow: /estimates -> create -> items -> send -> status.
- On-screen text / captions:
  - "Quote. Send. Track."
- Demo data needed: A customer and a price list.
- CTA: Try free + subscribe
- Related videos: [11-pipeline-crm.md](./11-pipeline-crm.md)

## Video: Convert an accepted estimate into an invoice
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "Accepted? Convert to invoice in one tap."
- Description: When a customer accepts your estimate on Custosell, turn it into
  an invoice with all items intact - no re-typing.
- What it's about: Estimate -> invoice conversion.
- Script beats:
  - Beat 1 (Hook): "They accepted! Make it an invoice before the call ends."
  - Beat 2 (Problem): "Re-entering a whole quote wastes time and adds errors."
  - Beat 3 (Action): Estimate -> accepted -> Convert -> invoice pre-filled.
  - Beat 4 (Aha): "Same items, same totals, ready to send."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /estimates -> convert -> /invoices.
- On-screen text / captions:
  - "Accepted -> Invoice"
- Demo data needed: An accepted estimate.
- CTA: Try free
- Related videos: [03-sales-pos.md](./03-sales-pos.md)

## Video: Reuse templates to save time
- Format: 30-45s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok
- Tagline: "Quote the same thing often? Make a template."
- Description: Save estimates as reusable templates on Custosell - fill in the
  customer and you're done.
- What it's about: Template save + reuse.
- Script beats:
  - Beat 1 (Hook): "The 10th quote for the same package should take 10 seconds."
  - Beat 2 (Problem): "Repeating work is the slowest work."
  - Beat 3 (Action): Estimate -> Save as template -> new estimate -> apply
    template -> fill customer.
  - Beat 4 (Aha): "Template = the quote minus the thinking."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /estimates -> save template -> apply template.
- On-screen text / captions:
  - "Template. Apply. Done."
- Demo data needed: A recurring service/package.
- CTA: Try free
- Related videos: [12-estimates-projects.md](./12-estimates-projects.md)

## Video: Track a project from quote to completion
- Format: 3-6min deep dive
- Priority: P3
- Platforms: YouTube
- Tagline: "Jobs and orders, tracked to the end."
- Description: The full project flow on Custosell - start a project from a
  quote, track progress and costs, and complete it with everything recorded.
- What it's about: Project lifecycle + costs.
- Script beats:
  - Beat 1 (Hook): "Bigger jobs need more than a receipt."
  - Beat 2 (Problem): "Long jobs lose track of costs and deadlines."
  - Beat 3 (Action): /projects -> start from estimate -> progress -> costs ->
    complete -> invoice.
  - Beat 4 (Aha): "One place holds the whole job."
  - Beat 5 (CTA): "Try it free at custosell.com."
- Screen flow: /projects -> create from quote -> track -> complete.
- On-screen text / captions:
  - "Job tracked, end to end"
- Demo data needed: A multi-step job/project.
- CTA: Try free + subscribe
- Related videos: [12-estimates-projects.md](./12-estimates-projects.md)

---

## Technical reference (source of truth)

**Screens:** `/estimates` [M], `/estimates/templates` [M],
`/projects` [M]

**User actions (FE hooks):** `useCreateEstimate` · `useSendEstimate` ·
`useEstimateStatus` · `useConvertEstimateToInvoice` ·
`useSaveTemplate` · `useApplyTemplate` · `useCreateProject` ·
`useUpdateProject` · `useProjectCosts` · `useCompleteProject`

**API endpoints (BE):** `/estimates` CRUD + `/estimates/{id}/send` +
`/estimates/{id}/status` + `/estimates/{id}/convert` ·
`/estimate-templates` CRUD · `/projects` CRUD + `/projects/{id}/costs` +
`/projects/{id}/complete`