# Oscar - In-App Chat Assistant

Enterprise chat assistant ("Oscar", avatar `public/oscar.webp`, bundled into the
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
I X" with real clickable links.

## Tests

`tests/Feature/AssistantTest.php` - provider reply + snapshot injection (Http::fake),
guest access, validation, missing-key path.
