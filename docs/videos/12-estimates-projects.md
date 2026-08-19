# 12 - Estimates, Templates & Projects

**Videos in this pack: 22**

Quote before you sell. Estimates with margins, reusable templates, and
project job costing - plus kanban boards for running the job, from personal
sprints to client project boards.

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

## Video: Create a personal board for your own tasks
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "Your own sprint board - private by default."
- Description: Make a personal kanban board in the Projects & Estimates
  workspace for your own tasks - private to you unless you share it - with
  cards instead of leads. Name it, pick visibility and background, then open
  tasks and drag them through columns.
- What it's about: ProjectBoardsPage "New personal board" -> CreateBoardModal
  (workspace=estimates) - name, description, board template, background, and
  visibility that defaults to private; personal boards use task terminology in
  the shared BoardKanbanPage.
- Script beats:
  - Beat 1 (Hook): "Your to-do list, as a kanban board."
  - Beat 2 (Problem): "Personal tasks hide between apps and sticky notes."
  - Beat 3 (Action): /estimates/boards -> New personal board -> name it ->
    keep visibility private -> Continue -> skip column alerts -> Open board ->
    add cards.
  - Beat 4 (Aha): "Personal boards start private and use tasks, not leads."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /estimates/boards -> New personal board -> create -> open.
- On-screen text / captions:
  - "Private task board, one click"
- Demo data needed: A business with the estimates workspace.
- CTA: Try free
- Related videos: [11-pipeline-crm.md](./11-pipeline-crm.md)

## Video: The project board is born when an estimate becomes a project
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok / YouTube
- Tagline: "Approve the estimate - the board appears."
- Description: Converting an approved estimate into a project also creates its
  kanban board automatically - named after the project, with To Do, In
  Progress, Review, and Done columns. The board carries the same budget as the
  estimate.
- What it's about: EstimateService::convertToProject +
  PipelineBoardService::getOrCreateProjectBoard - project seeded with budget
  revenue/cost from the estimate, board created with project_id, workspace
  estimates, and the four project stages.
- Script beats:
  - Beat 1 (Hook): "Accept the quote, get the job board."
  - Beat 2 (Problem): "Spinning up a project and its board separately."
  - Beat 3 (Action): /estimates/{id} -> Convert -> Convert to project ->
    confirm -> open /estimates/boards -> the project board is listed.
  - Beat 4 (Aha): "To Do, In Progress, Review, Done - ready before you are."
  - Beat 5 (CTA): "Try it free."
- Screen flow: estimate -> convert to project -> project board.
- On-screen text / captions:
  - "Estimate -> Project -> Board"
- Demo data needed: An approved estimate.
- CTA: Try free
- Related videos: [12-estimates-projects.md](./12-estimates-projects.md)

## Video: Open the board from inside a project
- Format: 30-45s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok
- Tagline: "The project's board, one tab away."
- Description: Inside a project you get a Board tab showing the project board's
  name, stage count, and total cards - open it to jump straight to the full
  kanban workspace.
- What it's about: ProjectDetailPage board tab -> ProjectBoardTab - stage and
  card counts from useProjectBoard, and the Open board action navigating to
  ROUTES.ESTIMATES.BOARD.
- Script beats:
  - Beat 1 (Hook): "Board summary right in the project."
  - Beat 2 (Problem): "Leaving the project page to check its board."
  - Beat 3 (Action): /estimates/projects/{id} -> Board tab -> see stages +
    cards -> Open board -> full kanban.
  - Beat 4 (Aha): "Stage count and card totals without opening the board."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /estimates/projects/{id} -> Board tab -> Open board.
- On-screen text / captions:
  - "Stages. Cards. Open."
- Demo data needed: A project with a board and a few cards.
- CTA: Try free
- Related videos: [12-estimates-projects.md](./12-estimates-projects.md)

## Video: Give clients a board-only view
- Format: 45-90s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok
- Tagline: "They see the board - not the costing."
- Description: Staff and clients invited to a project board get a board-only
  workspace: they land straight on the board, can view and work cards, but
  never see costs, timesheets, or allocations. Owners keep full project
  control.
- What it's about: isLimitedEstimatesUser + module access - limited users are
  redirected to /estimates/boards, getEstimatesModuleDefaultRoute, the Viewer
  role (view board and tasks), and costing tabs gated by canViewProjectCosting.
- Script beats:
  - Beat 1 (Hook): "Run the job without sharing the money."
  - Beat 2 (Problem): "Clients seeing costs they shouldn't."
  - Beat 3 (Action): invite a collaborator as Viewer -> they sign in -> land
    on the board -> open tasks -> no costs tab.
  - Beat 4 (Aha): "Limited users live on the board, full users get
    everything."
  - Beat 5 (CTA): "Try it free."
- Screen flow: invite collaborator -> board-only workspace.
- On-screen text / captions:
  - "Board view, no costing"
- Demo data needed: A project, a board, and a staff account without full
  Estimates access.
- CTA: Try free
- Related videos: [17-settings.md](./17-settings.md)

## Video: Add a task card to a project board
- Format: 45-90s how-to
- Priority: P1
- Platforms: Reels / Shorts / TikTok / YouTube
- Tagline: "A job broken into cards is a job getting done."
- Description: Add a task card to any column on a project or personal board -
  give it a title and assign team members - then it sits in the column ready to
  be worked and dragged forward.
- What it's about: CreateLeadModal in the estimates workspace - the header Add
  task button and each column's + button open the New task modal with
  defaultCardType=card (title + assignees, no contact/value fields).
- Script beats:
  - Beat 1 (Hook): "One big job, split into cards."
  - Beat 2 (Problem): "The whole job lives in someone's head."
  - Beat 3 (Action): /estimates/boards/{id} -> Add task -> title -> assign a
    teammate -> Add card -> it appears in the first column.
  - Beat 4 (Aha): "Task cards use tasks, not leads - same engine, right words."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /estimates/boards/{id} -> Add task -> fill -> Add card.
- On-screen text / captions:
  - "Task in. Column filled."
- Demo data needed: A project board with columns.
- CTA: Try free
- Related videos: [11-pipeline-crm.md](./11-pipeline-crm.md)

## Video: Work a task card - details, checklist, and comments
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "Every task card is a mini project."
- Description: Open a task card to edit its title, description, start and due
  dates, labels, checklist, links, custom fields, and attachments - assign
  people, add comments, and see its full history in one place.
- What it's about: LeadDetailModal + CardDetailExtras in task context - title,
  background color, rich-text description, Dates (start/due), CardLabels,
  checklists, links, meta fields, attachments, assignees, comments, history,
  reminders, Move card, and Archive.
- Script beats:
  - Beat 1 (Hook): "Open a card. Everything about the task is inside."
  - Beat 2 (Problem): "Task details scattered across chats and docs."
  - Beat 3 (Action): click a card -> edit title and description -> set due
    date -> add checklist items -> upload a file -> comment.
  - Beat 4 (Aha): "History tracks every change without you logging it."
  - Beat 5 (CTA): "Try it free."
- Screen flow: card -> details -> checklist -> attachments -> comments.
- On-screen text / captions:
  - "Title. Dates. Checklist. Done."
- Demo data needed: A task card on a project board.
- CTA: Try free
- Related videos: [11-pipeline-crm.md](./11-pipeline-crm.md)

## Video: Move, duplicate, pin, and complete task cards
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "Drag it. Duplicate it. Mark it done."
- Description: Run your board - drag task cards between columns, tick a card
  complete, pin it to the top, duplicate it, or move it to another board.
  Reorder columns too, so the board flows the way the job does.
- What it's about: LeadCard quick actions + KanbanColumn - drag-and-drop cards
  and columns, toggle complete, pin, history, comments, duplicate, and move
  card, plus each column's Add and column-options menu.
- Script beats:
  - Beat 1 (Hook): "Cards fly, the job follows."
  - Beat 2 (Problem): "Status updates happen in meetings, not in real time."
  - Beat 3 (Action): drag a card to In Progress -> tick it done -> pin the
    urgent one -> duplicate a template card -> drag columns to reorder.
  - Beat 4 (Aha): "Done is a tick, not a status update."
  - Beat 5 (CTA): "Try it free."
- Screen flow: board -> drag card -> complete -> pin -> duplicate -> reorder.
- On-screen text / captions:
  - "Drag. Tick. Pin. Done."
- Demo data needed: A project board with several cards.
- CTA: Try free
- Related videos: [11-pipeline-crm.md](./11-pipeline-crm.md)

## Video: See project tasks on a calendar
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "Task due dates, on one calendar you can scan."
- Description: Switch a project board to the calendar view to see task cards by
  due, start, or close date - with overdue tasks flagged, month/week/day modes,
  and a side panel for the day you pick.
- What it's about: BoardCalendarView in the estimates workspace - date-field
  selector (due/start/close/all), scope (this board or all boards), month/week/
  day modes, overdue open tasks, and CalendarDayDetailPanel.
- Script beats:
  - Beat 1 (Hook): "What's due this week? One glance."
  - Beat 2 (Problem): "Dates buried inside task cards."
  - Beat 3 (Action): /estimates/boards/{id} -> Calendar -> pick Due dates ->
    see the month -> open a busy day -> review overdue tasks.
  - Beat 4 (Aha): "Overdue open tasks glow red on the grid."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /estimates/boards/{id} -> Calendar -> date field -> day panel.
- On-screen text / captions:
  - "Due. Overdue. Done."
- Demo data needed: Project task cards with due dates.
- CTA: Try free
- Related videos: [11-pipeline-crm.md](./11-pipeline-crm.md)

## Video: Shape your project board - add, edit, and reorder columns
- Format: 30-45s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "Columns that match how the job actually runs."
- Description: Add columns to a project board, rename and recolor them, drag
  them into the right order, and delete the ones you don't need - so the board
  mirrors your real workflow.
- What it's about: AddStageModal, EditStageModal, DeleteStageModal, column
  drag-to-reorder, and the column options menu in the estimates workspace.
- Script beats:
  - Beat 1 (Hook): "Your board, your stages."
  - Beat 2 (Problem): "A default workflow that doesn't match the job."
  - Beat 3 (Action): /estimates/boards/{id} -> Add column -> name and color it
    -> drag columns into order -> edit or delete a column.
  - Beat 4 (Aha): "Column count shows on each stage instantly."
  - Beat 5 (CTA): "Try it free."
- Screen flow: board -> Add column -> reorder -> edit -> delete.
- On-screen text / captions:
  - "Add. Reorder. Delete."
- Demo data needed: A project board being set up.
- CTA: Try free
- Related videos: [11-pipeline-crm.md](./11-pipeline-crm.md)

## Video: Search tasks and import from a spreadsheet
- Format: 45-90s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok
- Tagline: "Find any task. Or import a whole list."
- Description: Search tasks on a board with plain text or smart tokens
  (@label, !high, #today, @me) to narrow instantly, or import a whole task
  list from a spreadsheet using the import template.
- What it's about: BoardKanbanPageHeader search with tokenizeQuery tokens
  (@label / !priority / #due / @me), and BoardCardImportModal in the
  estimates workspace (task language, spreadsheet template).
- Script beats:
  - Beat 1 (Hook): "Type @me and see exactly your tasks."
  - Beat 2 (Problem): "Scrolling a busy board to find one card."
  - Beat 3 (Action): board -> search "@me" or "!high #today" -> click Import ->
    download template -> fill -> upload -> review.
  - Beat 4 (Aha): "Tokens filter labels, priority, due dates, and owner."
  - Beat 5 (CTA): "Try it free."
- Screen flow: board -> search tokens -> import -> template -> upload.
- On-screen text / captions:
  - "@me. !high. #today. Found."
- Demo data needed: A board with labeled, prioritized, dated task cards.
- CTA: Try free
- Related videos: [11-pipeline-crm.md](./11-pipeline-crm.md)

## Video: The board tabs - switch, resources, progress, fame, discussion
- Format: 30-45s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok
- Tagline: "Everything the board needs, one tab bar."
- Description: Along the bottom of every board sit the workspace tabs - switch
  between boards, open shared resources, watch progress targets, celebrate on
  the wall of fame, and chat in the board discussion - each with live counts.
- What it's about: BoardSwitcherIcons - Switch boards, Resources (files/links),
  Progress (targets and delivery), Fame (posts), Discussions (threads with
  unread badges), and New board.
- Script beats:
  - Beat 1 (Hook): "Your board is a workspace, not just columns."
  - Beat 2 (Problem): "Files, goals, and chat live in different apps."
  - Beat 3 (Action): board -> Resources -> add a file -> Progress -> view
    targets -> Fame -> post a win -> Discussions -> reply.
  - Beat 4 (Aha): "Unread badges and counts ride along in the tab bar."
  - Beat 5 (CTA): "Try it free."
- Screen flow: board -> tab bar -> resources/progress/fame/discussion.
- On-screen text / captions:
  - "Files. Goals. Wins. Chat."
- Demo data needed: A project board with resources, a target, and a post.
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
allocations) · `/estimates/boards` [M] (estimates-workspace kanban boards -
"My boards" personal + "Project boards", New personal board drawer)

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
`useRemoveProjectMember` · `viewEstimatePdf`/`downloadEstimatePdf` ·
`usePipelineBoards` (estimatesWorkspace: true) · `useCreatePipelineBoard`
(personal boards, workspace=estimates, private by default) ·
`usePipelineKanban` · `filterBoardsForWorkspace`/`boardBelongsToEstimatesWorkspace`/
`boardUsesTaskTerminology` (task/card language for project + estimates boards) ·
`useProjectBoard`/`useProjectBoardKanban` (project board via project id) ·
`isLimitedEstimatesUser`/`getEstimatesModuleDefaultRoute` (board-only users
land on `/estimates/boards`)
- Shared board engine actions (task language in estimates workspace):
  `useCreatePipelineLead` (defaultCardType=card -> New task modal) ·
  `useUpdatePipelineLead` · `useMovePipelineLead` · `useDeletePipelineLead`
  (archive card) · `useCreatePipelineStage`/`useUpdatePipelineStage`/
  `useDeletePipelineStage`/`useReorderPipelineStages` (columns) ·
  `useCreatePipelineChecklist` + items · `usePipelineCalendar`/
  `useAllBoardsCalendar` (due/start/close/all, scope board vs all) ·
  `useBoardResources`(+ CRUD) · `useBoardProgressSummary`/`useBoardTargets`/
  `useCreateBoardTarget` · `useWallFamePosts`(+ CRUD) ·
  `useBoardConversationSummary`/`usePostBoardMessage` · lead/card search
  tokens (@label, !priority, #due, @me) via `tokenizeQuery` +
  `usePipelineLeadSearch`

**API endpoints (BE):** `/estimates` CRUD + `/analytics` + `/{id}/send` +
`/{id}/approve` + `/{id}/reject` + `/{id}/email` + `/{id}/pdf` +
`/{id}/duplicate` + `/{id}/versions` + `/{id}/revision` +
`/{id}/convert-to-invoice` + `/{id}/convert-to-project` · `/estimates/templates`
CRUD · `/projects` CRUD + `/my-projects` + `/{id}/board` + `/{id}/board/kanban`
+ `/{id}/members` CRUD + `/{id}/budget-summary` + `/{id}/profitability` +
`/{id}/tasks` + `/project-tasks/{id}` + `/{id}/timesheets` +
`/timesheet-entries/{id}` + `/{id}/allocations` + `/project-allocations/{id}` ·
shared board endpoints (`/pipeline/boards` CRUD with `workspace`, kanban,
calendar, stages, leads/cards CRUD + move/archive/checklists/attachments,
resources, progress/targets, wall-of-fame, conversation) - project boards are
born via `PipelineBoardService::getOrCreateProjectBoard` (To Do / In Progress /
Review / Done stages, seeded labels + guiding cards) when an estimate converts;
card import via `/import-template` + `/import`

**Route middleware (BE):** estimates group uses `auth:sanctum` ·
`business.active` · `subscription.active` · `estimates.workspace`
(EstimatesAccessMiddleware - full vs limited users, costing gated);
limited users get board-only access (no costing/team management)