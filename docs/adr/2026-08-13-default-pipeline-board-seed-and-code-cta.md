# ADR - Default pipeline board seeded on account creation; email CTAs deep-link by board code

- **Date:** 2026-08-13
- **Status:** Accepted
- **Stack:** Backend (no DB migration - reuses the existing `PipelineBoardService` and board `code` column).

## Context

1. **Empty board on first sign-in.** A new personal or business account had no pipeline board until a board was explicitly created. The CRM board looked empty on first login, which is a poor onboarding experience.
2. **Email CTAs broke deep-linking.** `PipelineNotificationService` built board CTA links with the numeric `$board->id` (`/pipeline/boards/{id}`), but the frontend opens boards by their **opaque code** (`/pipeline/boards/{code}`). Email links to boards were therefore invalid.

## Decision

1. **Seed a default board on registration, via the `UserRegistered` event.**
   - New `App\Listeners\SeedDefaultPipelineBoard` listens to `UserRegistered` (registered in `EventServiceProvider`).
   - It resolves the business (`$event->business ?? $user->business`) and calls `PipelineBoardService::ensureBusinessSetup($businessId, $userId)`.
   - `ensureBusinessSetup` (existing) seeds sources if missing and creates a default `Main sales pipeline` board (workspace `pipeline`, `is_default = true`) with the standard kanban stages, default labels, default appearance, and guiding cards - so the board is never empty.
   - Storefront buyers (no business workspace) are skipped (`if (!$business) return`).
   - Seeding failures are caught and only logged - they never block registration.

2. **Board notification CTAs deep-link by `code`, not `id`.**
   - `PipelineNotificationService::boardCta` and `boardConversationCta` now use `$board->code` in place of `$board->id`, matching how the frontend routes boards (`/pipeline/boards/{code}` and `/estimates/boards/{code}`).

## Why the `UserRegistered` event and not the registration services

The chart-of-accounts seeding (ADR 2026-08-11) seeds inside the registration `DB::transaction`. Board seeding is deliberately **non-transactional** and best-effort: a failure to seed a board should never roll back a successful registration. Hooking the `UserRegistered` event decouples the concern, keeps it out of `UserService`/`BusinessService`, and applies uniformly to personal and business flows. `ensureBusinessSetup` is also idempotent (checks for an existing non-archived board first), so re-runs are safe.

## Consequences

- Personal and business accounts get a populated default pipeline board immediately on sign-up.
- Board email notifications now deep-link correctly to the opaque board `code`.
- Non-fatal failures are logged with `user_id` / `business_id` context; registration is never blocked.
- Test coverage:
  - `AuthTest::test_personal_registration_seeds_default_pipeline_board`
  - `BusinessTest::test_register_business_seeds_default_pipeline_board_with_columns_and_cards`
  - `PipelineBoardNotificationCtaTest::test_board_notification_email_links_to_board_by_code_not_id`

## References

- `app/Listeners/SeedDefaultPipelineBoard.php` - new listener.
- `app/Providers/EventServiceProvider.php` - wires the listener to `UserRegistered`.
- `app/Services/Pipeline/PipelineBoardService.php::ensureBusinessSetup` / `createBoard`.
- `app/Services/Pipeline/PipelineNotificationService.php::boardCta` / `boardConversationCta`.
- `tests/Feature/AuthTest.php`, `tests/Feature/BusinessTest.php`, `tests/Feature/PipelineBoardNotificationCtaTest.php`.
