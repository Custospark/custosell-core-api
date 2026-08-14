# ADR-020: Pipeline/reports modularized under 500 lines + shifts.close_report enforcement

**Date:** 2026-08-01
**Status:** Accepted

## Context

Vera's `[file-size-500]` gate flagged four backend files over the 500-line hard limit after prior fixes pushed them over: `ReportsController`, `PipelineBoardConversationService`, `PipelineCollaborationService`, and `PipelineBoardProgressService` (1190 lines). Rule 14 mandates modularization - never revert or strip behavior.

A latent post-refactor bug was also exposed: `PipelineCollaborationService` called a now-undefined `$this->boardRecipients(...)`, and one `ReportTest` (13/14) failed because the backend ignored staff role permissions for shift-close report downloads.

## Decision

- **ReportsController (451 lines)** - extracted `app/Http/Controllers/Concerns/BuildsReportResponses.php` (getDateRange / businessId / getBusiness / filters / pdfData / dateSubtitle / trendExportRows / trendChartConfig / trendBlock / pdfOrientation / xlsx) and created `app/Http/Controllers/Api/ShiftReportsController.php` (139 lines) holding `shiftReconciliation`, `shiftClose`, and `canAccessShiftCloseReport`. `routes/api/v1/reports.php` now wires shift routes to the new controller (shift routes stay under `module:sales`; dashboard routes unchanged).
- **Conversation (361 lines)** - extracted `app/Services/Pipeline/PipelineBoardConversationPresenter.php` (299 lines): findMessageForBusiness, loadBoardMessages, reloadMessage, assertCanEditMessage, assertCanDeleteMessage, unreadCountForUser, messageNotificationRecipients, syncMentions, parseMentionIds, isValidReaction, reactionSummary, serializeAttachment, serializeMessage, logBoardActivity. `PipelineBoardConversationService::__construct` now also receives the presenter.
- **Collaboration (214-line facade)** - extracted `PipelineCollaborationReactionService` (142), `PipelineBoardAnnouncementService` (201), `PipelineBoardPollService` (454), `PipelineReminderService` (128); added `PipelineBoardPermissionService::boardTeamMembers`; replaced the undefined `boardRecipients()` call with `PipelineNotificationService::boardRecipientsForNotifications`. Same public API preserved.
- **Progress (1190 → 259-line orchestrator)** - extracted `PipelineBoardProgressPeriodService` (resolvePeriod), `PipelineBoardProgressMetricsService` (METRIC_KEYS, boardContext, computeTeamMetrics, computeMemberMetrics, computeTrendSeries, computeStageFunnel, computeMetricValue, win/conversion/cycle helpers, boardMemberIds, computeExpectedTrendSeries), `PipelineBoardTargetProgressService` (listTargetsWithProgress, serializeTargetTree, progressPercent, paceStatus, period slices, pace alerts, serializeTargetForHr), `PipelineBoardTargetService` (listTargets, storeTarget, updateTarget, archiveTarget, resolveStageIds, defaultChartConfig, findTargetForBusiness, validateTargetPayload). `progressSummary`, `progressQuery`, `myProgress`, `getProgressConfig`/`saveProgressConfig`, `decomposePreview`, CRUD passthroughs, `recordDailySnapshots`, and `serializeTargetForHr` stay on the facade. Public constructor: `(PipelineService $pipeline, PipelineColumnMetricsService $columnMetrics, PipelineGoalDecompositionService $decomposition, PipelineBoardProgressPeriodService $period, PipelineBoardProgressMetricsService $metrics, PipelineBoardTargetService $targets, PipelineBoardTargetProgressService $targetProgress)`.
- **Shift-close permission** - `User::hasRolePermission(string $permission): bool` reads `$this->role->permissions[$permission]` (null role → false). `ShiftReportsController::canAccessShiftCloseReport` requires `shifts.close_report` for non-dashboard staff (owner/dashboard bypass; cross-business → false; own-shift-only). This is the one documented exception to the intentional "staff module access is the source of truth" policy in `ModuleAccessService`.
- **Lead fixes folded in** - `currency` defaults to board business currency (`UGX`); `convertLead` uses `customerContactService->resolve(...)`; `archiveLead` sets `status='archived'` (schema has no `is_archived` column); `PipelineLead::reminders()` relation added.

## Tests

- `BoardProgressTest` 12/12, `PipelineTest` 6/6, `ReportTest` 14/14, `ForecastingAccountingCorrectnessTest` 6/6, `AccountingTest` 21/21, `ReportPeriodRangeTest` 3/3.
- `composer vera:fast` - `[file-size-500]` all changed files ≤ 500; `[php-imports]` clean.

## Consequences

- All four flagged files now under 500 lines; the gate is green on HEAD.
- External references to `PipelineBoardProgressService` (controller, `RecordPipelineBoardProgressSnapshots` command, `HrPerformanceService`) unchanged - same public API.
- Staff shift-close downloads now require the `shifts.close_report` role flag (frontend `RoleTypes.ts` already listed it as assignable).
