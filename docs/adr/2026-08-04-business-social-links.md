# Business Social Links (storefront)

Status: Accepted · 2026-08-04 · Cross-stack (backend + frontend)

## Context

Businesses wanted to surface their external web/social profiles (Facebook, YouTube,
Instagram, TikTok, LinkedIn, WhatsApp, or any emerging platform) on their public
storefront. The prior `businesses` table only held `website`; there was no model for
an open list of links.

Two design options were considered:

1. **Discrete columns** on `businesses` (one per known platform). Abandoned after the
   requirement clarified that platforms are **open-ended** - new networks keep
   appearing, so closing the set to a fixed enum would force a migration every time.
2. **Dedicated child table** with a free-text `platform`. Chosen.

## Decision

- New table `business_social_links`: `id`, `business_id` (FK, cascade on delete),
  `platform` (string), `url` (string), `sort_order`, timestamps, unique
  `(business_id, platform)`.
- `platform` is **free text** (trimmed + lowercased for dedup). No enum. Same platform
  re-added **upserts** (updates URL) instead of creating a duplicate row.
- **Owner-only CRUD**: routes scoped by `business.owner` middleware (and
  `business.active` + `module:settings`), resolving the business from
  `$request->user()->business_id`. Direct access to another business's link returns
  **404** (hidden resource), not 403.
- `StorefrontService::publicShopPayload()` exposes `social_links` (ordered) to buyers;
  the owner's edits are server-only.

## Why a dedicated table supports unlimited platforms

Because `platform` is a plain string column, adding a new network is data-entry only -
no schema or enum change. Each business is kept to **one row per platform** by the
unique constraint + upsert path.

## API

| Method | Endpoint | Auth | Notes |
|--------|----------|------|-------|
| GET | `/business-social-links` | Owner | List business's links |
| POST | `/business-social-links` | Owner | Create / upsert by platform |
| GET | `/business-social-links/{id}` | Owner | Scoped (404 if not owned) |
| PUT | `/business-social-links/{id}` | Owner | Update URL/order |
| DELETE | `/business-social-links/{id}` | Owner | Remove (204) |

Public storefront payload now includes `social_links: [{ platform, url }, ...]`.

## Frontend

- Settings → Business → new **"Social links"** card (self-contained section, not part of
  the parent form's save/cancel). Add / edit URL / remove rows; server-only CRUD.
- Public shop page renders the links to the **left of the QR code** (brand-colored SVG
  glyphs for known platforms, globe fallback for custom ones; stacks above the QR on
  mobile).
- Removed the **Download PNG** control from the public shop page (kept in shop settings).

## Notes

- lucide-react 1.x removed brand icons (Facebook/Instagram/...), so glyphs are inline
  CC0 SVGs (`brandIcons.tsx`) with the `react-refresh` eslint disable.
- Cross-business access returns **404** because `abort()` → `HttpException` extends
  `RuntimeException`, which the global handler in `bootstrap/app.php` maps to `422
  plan_limit` for `api/*`. Local controllers therefore return explicit 404 JSON.