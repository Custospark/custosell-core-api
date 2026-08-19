# 12 - Estimates, Templates & Projects

**Videos in this pack: 11**

Quote before you sell. Estimates with margins, reusable templates, and
project job costing.

## Video: Create an estimate with cost and margin
- Format: 45-90s how-to
- Priority: P1
- Platforms: Reels / Shorts / TikTok / YouTube
- Tagline: "Quote with a margin you can see."
- Description: Build a professional estimate on Custosell - title, customer,
  valid-until date, tax rate, and line items with cost, markup, and a live
  gross-profit margin summary. Starts as a draft.
- What it's about: EstimateBuilderForm and EstimateLineItemEditor - line item
  types (labor/material/service), cost + markup driving price, margin summary,
  and notes/terms/internal notes.
- Script beats:
  - Beat 1 (Hook): "Quote now. They decide later. You follow up."
  - Beat 2 (Problem): "Handwritten quotes look unprofessional and hide the
    margin."
  - Beat 3 (Action): /estimates -> New estimate -> title + customer -> add line
    items -> cost + markup -> review margin -> create draft.
  - Beat 4 (Aha): "Gross profit and margin % are computed line by line."
  - Beat 5 (CTA): "Try it free at custosell.com."
- Screen flow: /estimates -> New estimate -> builder -> create.
- On-screen text / captions:
  - "Cost in. Price out. Margin seen."
- Demo data needed: A customer and a few priced products/services.
- CTA: Try free + subscribe
- Related videos: [11-pipeline-crm.md](./11-pipeline-crm.md)

## Video: Send and email estimates
- Format: 45-90s how-to
- Priority: P1
- Platforms: Reels / Shorts / TikTok / YouTube
- Tagline: "Mark it sent - or really email it."
- Description: Mark an estimate as sent to track the deal, or email the actual
  proposal to the customer, and always have a professional PDF ready to preview
  or download.
- What it's about: EstimateDetailHeader - Mark as sent (status only), Send by
  email (real email), and PDF Preview/Download.
- Script beats:
  - Beat 1 (Hook): "Track the status. Send the PDF. Follow up."
  - Beat 2 (Problem): "A quote in your head isn't a quote they can read."
  - Beat 3 (Action): /estimates/{id} -> Mark as sent -> Send by email -> enter
    recipient -> send -> Preview/Download PDF.
  - Beat 4 (Aha): "Status flips to Sent so the team knows it's live."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /estimates/{id} -> send -> email -> PDF.
- On-screen text / captions:
  - "Sent. Emailed. PDF ready."
- Demo data needed: A draft estimate.
- CTA: Try free + subscribe
- Related videos: [12-estimates-projects.md](./12-estimates-projects.md)

## Video: Approve an estimate - or decline it
- Format: 45-90s how-to
- Priority: P1
- Platforms: Reels / Shorts / TikTok
- Tagline: "The customer said yes? Make it official."
- Description: Approve an estimate to record the accepted deal - optionally
  creating the invoice and project in the same step - or decline it with a
  reason for the record.
- What it's about: ApproveEstimateModal - just approve, or approve + create
  invoice from billable items + create project with job costing; Decline
  proposal with required rejection reason.
- Script beats:
  - Beat 1 (Hook): "They accepted! Lock it in."
  - Beat 2 (Problem): "A verbal yes that nobody records."
  - Beat 3 (Action): /estimates/{id} -> Approve & convert -> tick Create
    invoice and/or Create project -> confirm.
  - Beat 4 (Aha): "Approve once, and the invoice or project is born."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /estimates/{id} -> Approve & convert -> confirm.
- On-screen text / captions:
  - "Approved. And converted."
- Demo data needed: A sent estimate.
- CTA: Try free
- Related videos: [12-estimates-projects.md](./12-estimates-projects.md)

## Video: Convert an estimate into an invoice or project
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "Accepted estimate -> invoice or project, no re-typing."
- Description: Convert an approved estimate into a draft invoice from its
  billable items, or into a project seeded with the same budget and margin.
- What it's about: ConvertEstimateModal - Convert to invoice (draft from
  billable line items) and Convert to project (name + budget from estimate).
- Script beats:
  - Beat 1 (Hook): "Same items, same totals, ready to go."
  - Beat 2 (Problem): "Re-entering a whole quote wastes time and adds errors."
  - Beat 3 (Action): /estimates/{id} -> Convert -> Convert to invoice -> see
    the draft invoice -> or Convert to project.
  - Beat 4 (Aha): "Invoice and project both carry the estimate's numbers."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /estimates/{id} -> Convert -> invoice/project.
- On-screen text / captions:
  - "Estimate -> Invoice / Project"
- Demo data needed: An approved estimate.
- CTA: Try free
- Related videos: [03-sales-pos.md](./03-sales-pos.md)

## Video: Reuse estimate templates to quote faster
- Format: 30-45s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "Quote the same package often? Make a template."
- Description: Save default line items, tax rate, and terms as a reusable
  estimate template - pick the customer and you're most of the way done.
- What it's about: EstimateTemplatesPage - template cards, New template with
  line items + default tax + terms, edit, and delete.
- Script beats:
  - Beat 1 (Hook): "The 10th quote for the same package should take 10
    seconds."
  - Beat 2 (Problem): "Repeating the same line items is the slowest work."
  - Beat 3 (Action): /estimates/templates -> New template -> add the package
    items -> save -> start an estimate from it.
  - Beat 4 (Aha): "Template = the quote minus the thinking."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /estimates/templates -> new -> save -> reuse.
- On-screen text / captions:
  - "Template. Apply. Done."
- Demo data needed: A recurring service or package.
- CTA: Try free
- Related videos: [12-estimates-projects.md](./12-estimates-projects.md)

## Video: Read estimate insights
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "Win rate, margins, and pipeline value - in one place."
- Description: See how your estimates are performing - win rate, average
  margin, pipeline value, approved value, and gross profit - with a monthly
  trend and a breakdown by status.
- What it's about: EstimatesInsightsPage - stat cards, monthly trend chart, by
  status donut, and status count summary.
- Script beats:
  - Beat 1 (Hook): "How many quotes become money?"
  - Beat 2 (Problem): "Guesswork about whether proposals are winning."
  - Beat 3 (Action): /estimates/insights -> read win rate and avg margin ->
    look at the monthly trend -> the by-status donut.
  - Beat 4 (Aha): "You see exactly where proposals stall."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /estimates/insights -> stats -> charts.
- On-screen text / captions:
  - "Won. Lost. Margin."
- Demo data needed: Estimates across several months.
- CTA: Try free
- Related videos: [02-dashboard-reports.md](./02-dashboard-reports.md)

## Video: Create an estimate from a pipeline lead
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "From hot lead to priced proposal in one click."
- Description: Turn a pipeline lead straight into an estimate - the lead's
  title, customer, notes, and estimated value come across, and the two stay
  linked.
- What it's about: CreateEstimateFromLeadButton - seeds the estimate from the
  lead, syncs the estimate back to the lead, then opens the detail.
- Script beats:
  - Beat 1 (Hook): "The lead says quote me. One click."
  - Beat 2 (Problem): "Re-typing lead details into an estimate."
  - Beat 3 (Action): /pipeline/boards/{id} -> open a lead -> Create estimate ->
    the estimate opens seeded -> refine and send.
  - Beat 4 (Aha): "Lead and estimate stay connected."
  - Beat 5 (CTA): "Try it free."
- Screen flow: pipeline lead -> Create estimate -> detail.
- On-screen text / captions:
  - "Lead -> Estimate"
- Demo data needed: A pipeline lead with a customer and value.
- CTA: Try free
- Related videos: [11-pipeline-crm.md](./11-pipeline-crm.md)

## Video: Track a project's budget vs actuals
- Format: 45-90s how-to
- Priority: P1
- Platforms: Reels / Shorts / TikTok / YouTube
- Tagline: "Know if the job is on budget - before it's too late."
- Description: Open a project to see budget revenue and cost against what's
  actually spent, with margin alerts that flag cost overruns and negative
  margins early.
- What it's about: ProjectDetailPage - status/due-date editing, cost alerts,
  ProjectStatsGrid, and the budget vs actual progress bars.
- Script beats:
  - Beat 1 (Hook): "A job on budget or bleeding? One glance."
  - Beat 2 (Problem): "Costs creep until the job is done and the surprise is
    big."
  - Beat 3 (Action): /estimates/projects/{id} -> read budget vs actual bars ->
    check the alerts -> update status and due date inline.
  - Beat 4 (Aha): "Red banners appear the moment a job goes over."
  - Beat 5 (CTA): "Try it free at custosell.com."
- Screen flow: /estimates/projects/{id} -> budget card -> alerts.
- On-screen text / captions:
  - "Budget vs actual, live"
- Demo data needed: A project converted from an approved estimate.
- CTA: Try free + subscribe
- Related videos: [09-accounting.md](./09-accounting.md)

## Video: Log tasks, timesheets, and cost allocations
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "Every hour and every cost lands on the job."
- Description: Break a project into tasks with estimated and actual hours, log
  staff timesheets against it, and record cost allocations by type - labor,
  material, overhead - so job costs stay true.
- What it's about: Project tasks/timesheets/cost allocations tabs - task table,
  timesheet entries, and Record allocation modal with type and amount.
- Script beats:
  - Beat 1 (Hook): "Hours, costs, and tasks - all on the job."
  - Beat 2 (Problem): "Job costs scattered across payslips and invoices."
  - Beat 3 (Action): /estimates/projects/{id} -> Tasks -> add a task -> Timesheets
    -> log hours -> Cost allocations -> Record allocation.
  - Beat 4 (Aha): "The budget updates as each cost lands."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /estimates/projects/{id} -> tasks -> timesheets -> allocations.
- On-screen text / captions:
  - "Task. Hour. Cost. Recorded."
- Demo data needed: A project with a team and budget.
- CTA: Try free
- Related videos: [15-hr.md](./15-hr.md)

## Video: Run the job on a project board
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "Your project as a kanban board - progress visible."
- Description: Open a project's board to see and move its work through stages
  - the same kanban engine as the pipeline - with cards that reflect task
  progress.
- What it's about: ProjectBoardsPage + the shared BoardKanbanPage in the
  estimates workspace - personal and project boards, plus the Open board action
  on a project.
- Script beats:
  - Beat 1 (Hook): "The whole job, column by column."
  - Beat 2 (Problem): "Project status hidden in someone's head."
  - Beat 3 (Action): /estimates/boards -> open the project board -> move cards
    through stages -> see progress.
  - Beat 4 (Aha): "The board is born when the estimate becomes a project."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /estimates/boards -> project board -> move cards.
- On-screen text / captions:
  - "Board = project progress"
- Demo data needed: A project with a board.
- CTA: Try free
- Related videos: [11-pipeline-crm.md](./11-pipeline-crm.md)

## Video: Build the project team with roles
- Format: 45-90s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok
- Tagline: "Right people, right access, one project."
- Description: Invite staff to a project as Viewer, Contributor, or Manager,
  control who edits and who manages the team, and keep the owner locked in.
- What it's about: ProjectMemberPicker - staff invite with role + email
  notification, member role dropdown, and owner protection.
- Script beats:
  - Beat 1 (Hook): "Everyone on the job, with the right access."
  - Beat 2 (Problem): "Whole teams editing jobs they shouldn't touch."
  - Beat 3 (Action): /estimates/projects/{id} -> Project team -> pick staff ->
    choose role -> Invite.
  - Beat 4 (Aha): "Managers govern, contributors work, viewers watch."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /estimates/projects/{id} -> team -> invite -> role.
- On-screen text / captions:
  - "Viewer. Contributor. Manager."
- Demo data needed: A project and a few staff accounts.
- CTA: Try free
- Related videos: [17-settings.md](./17-settings.md)

---

## Technical reference (source of truth)

**Screens:** `/estimates` [M] (list + stat cards + New estimate drawer) ·
`/estimates/{id}` [M] (detail: send/email/approve/reject/convert/PDF/duplicate +
line items, versions, notes) · `/estimates/templates` [M] (template cards) ·
`/estimates/insights` [M] (win rate, margin, trends) ·
`/estimates/projects` [M] (project list with budget vs actual) ·
`/estimates/projects/{id}` [M] (overview/tasks/timesheets/board/documents/
allocations) · `/estimates/boards` [M] (estimates-workspace kanban boards)

**User actions (FE hooks):** `useEstimates` · `useEstimate` ·
`useCreateEstimate` · `useUpdateEstimate` · `useDeleteEstimate` ·
`useSendEstimate` (status only) · `useEmailEstimate` (real email) ·
`useApproveEstimate` · `useRejectEstimate` · `useDuplicateEstimate` ·
`useEstimateVersions` · `useConvertEstimateToInvoice` ·
`useConvertEstimateToProject` · `useEstimateAnalytics` · `useEstimateTemplates`
(+ CRUD) · `useProjects` · `useProject` · `useCreateProject` ·
`useUpdateProject` · `useDeleteProject` · `useMyProjects` ·
`useCreateProjectTask`/`useUpdateProjectTask`/`useDeleteProjectTask` ·
`useCreateTimesheetEntry`/`useDeleteTimesheetEntry` ·
`useCreateCostAllocation`/`useDeleteCostAllocation` · `useProjectBudgetSummary`
· `useProjectProfitability` · `useProjectBoard` · `useProjectBoardKanban` ·
`useProjectMembers`/`useAddProjectMember`/`useUpdateProjectMember`/
`useRemoveProjectMember` · `viewEstimatePdf`/`downloadEstimatePdf`

**API endpoints (BE):** `/estimates` CRUD + `/analytics` + `/{id}/send` +
`/{id}/approve` + `/{id}/reject` + `/{id}/email` + `/{id}/pdf` +
`/{id}/duplicate` + `/{id}/versions` + `/{id}/revision` +
`/{id}/convert-to-invoice` + `/{id}/convert-to-project` · `/estimates/templates`
CRUD · `/projects` CRUD + `/my-projects` + `/{id}/board` + `/{id}/board/kanban`
+ `/{id}/members` CRUD + `/{id}/budget-summary` + `/{id}/profitability` +
`/{id}/tasks` + `/project-tasks/{id}` + `/{id}/timesheets` +
`/timesheet-entries/{id}` + `/{id}/allocations` + `/project-allocations/{id}`

**Route middleware (BE):** estimates group uses `auth:sanctum` ·
`business.active` · `subscription.active` · `estimates.workspace`
(EstimatesAccessMiddleware - full vs limited users, costing gated)