# Oscar - In-App AI Agent

Enterprise AI agent ("Oscar", avatar `public/oscar.webp`, bundled into the
frontend) answering from live business data. Provider key never leaves the
backend - the frontend only calls our proxy.

## Architecture

- `POST /api/v1/assistant/chat` (auth + `business.active`, throttle 30/min) -
  member answers with a read-only business snapshot (low stock, today's sales,
  outstanding invoices, product count).
- `POST /api/v1/assistant/guide` (guest, throttle 10/min) - how-to answers for
  landing/auth pages, no business data.
- `AssistantChatService` posts OpenAI-compatible `/chat/completions` to the
  configured provider. Free-tier 429/timeout/empty replies map to safe,
  retryable messages; all failures log `business_id` + latency, never the key.
- Cost guards: `ASSISTANT_MAX_TOKENS` (default 4000 - reasoning models spend
  budget thinking), 20 messages x 2000 chars max per request, bounded snapshot
  (10 low-stock rows).
- Frontend: `AssistantWidget` (portal, bottom-right, responsive + offline-aware)
  mounted once in `App.tsx` so it renders on landing, auth and every page.

## Configuration (.env)

```
OPENROUTER_API_KEY=
ASSISTANT_BASE_URL=https://openrouter.ai/api/v1
ASSISTANT_MODEL=meta/muse-spark-1.3-contributor
ASSISTANT_TIMEOUT=60
ASSISTANT_MAX_TOKENS=1000
```

Model ID is env-swappable (e.g. standard tier for private data - contributor
traffic may train provider models and is rate-limited).

## Knowledge base (`AssistantKnowledgeService`)

Retrieval over existing content - no vector DB. Sources: published `GuideFaq`
rows, published `GuideTutorial` rows (the same tables driving FAQs/tutorials
pages), plus a curated static product brief mirroring landing/pricing. Keyword
scoring (title x3), top 5 passages, 2500-char cap, corpus cached 10 minutes.
Injected for members and guests. Covered by `AssistantTest::test_knowledge_base_faq_reaches_provider`.

Live plan pricing (`planPassages()`) reads active `plans` rows - Oscar quotes
current USD prices, trials and feature lists with nothing hardcoded. Unknown
questions trigger the human-support fallback (call +256 756 697 871 / +256 764
428 003, Mon-Fri 8-6 EAT, or support@custosell.com) per `guideSupportConfig.ts`.

Route map (`route-map.json`, 74 entries) extracted from the frontend search
sources (`ROUTES`, `NAV_ITEM_KEYWORDS`, sidebar labels) by
`scripts/extract-assistant-routes.py` - re-run it when navigation changes.
Passages carry full URLs (`FRONTEND_URL` + path) so Oscar answers "where do
I X" with real clickable links. Auth-recovery routes outside the sidebar
(login, register, pricing, forgot-password) are pinned in
`scripts/extract-assistant-routes.py` (`core` list) - re-run it when
navigation changes and keep that list in sync.

## No-answer fallback

When Oscar truly cannot answer, the system prompt tells it to say so
plainly and share these next steps (dash list, full URLs so the widget
linkifies them): Custosell team phones/email, the WhatsApp community
(`https://chat.whatsapp.com/HWHjz6ErUuhAjZnZUyfLpe`), and the Custospark
YouTube channel (`https://www.youtube.com/@Custospark`). Signed-in
members additionally get the in-app video tutorials
(`<FRONTEND_URL>/guide/tutorials`). Contacts appear ONLY on genuine
no-answers - never on greetings.

## Chat sessions (`ChatSessionService`)

Signed-in users get persistent history; guests stay ephemeral and never
touch these endpoints:

- `GET /api/v1/assistant/sessions` - list own sessions (latest first)
- `GET /api/v1/assistant/sessions/{id}` - open one with its messages
- `PATCH /api/v1/assistant/sessions/{id}` - rename (title max 120)
- `DELETE /api/v1/assistant/sessions/{id}` - delete with its messages
- `POST /api/v1/assistant/chat` accepts optional `session_id` and returns
  the session (`{id, title}`) so the client can adopt it.

All session rows are scoped by `user_id` + `business_id` - cross-owner
access returns 404, never another user's data. Tables: `chat_sessions`,
`chat_messages` (migration `2026_09_26_000002`).

## Conversation continuity

Jumpy follow-ups are fixed on both sides. The client sends up to 20
recent turns with every request and resumes its thread after reloads
(members: last `session_id` from local storage; guests: last 20 turns
from local storage, never touching the server). The server additionally
merges stored session turns under the incoming messages
(`ChatSessionService::contextWithHistory`, deduped, capped to
`assistant.max_messages`), so pronouns ("it", "that one") resolve even
when only the latest turn arrives.

## Tests

`tests/Feature/AssistantTest.php` - provider reply + snapshot injection (Http::fake),
guest access, validation, missing-key path.
`tests/Feature/AssistantSessionsTest.php` - session CRUD scoped to owner.
