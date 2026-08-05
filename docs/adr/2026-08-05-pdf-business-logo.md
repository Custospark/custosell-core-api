# Business logos in generated PDFs

Status: Accepted · 2026-08-05 · Backend only (frontend already renders `logo_path` on the storefront)

## Context

Businesses can already upload a logo (`businesses.logo_path`, stored on the `public`
disk, symlinked at `public/storage`). It appeared on the storefront but was absent
from every backend-generated PDF (invoices, estimates, sales tickets, payment receipts,
subscription receipts, shift-close tickets, and accounting-export reports).

## Decision

- Embed the business logo in the header of the **shared PDF base layout**
  `resources/views/reports/layouts/base.blade.php` as a **base64 data URI**
  (`data:<mime>;base64,<bytes>`).
- The logo is read directly from the `public` disk using the business's `logo_path`
  (with the `/storage/` prefix stripped) — no symlink resolution at render time, so it
  works in DomPDF, which cannot follow relative web URLs.
- The `<img>` renders **above the business name** in the centered header; CSS caps it at
  `max-height: 56px; max-width: 180px`.
- **Graceful fallbacks**, in order:
  1. Business has no `logo_path` → no `<img>` rendered.
  2. File missing on disk → no `<img>` rendered.
  3. MIME type undetectable → falls back to `image/png`.
- `logo_path` is read via `isset()` so the same blade works when the layout is passed a
  plain `stdClass` business object (payment/subscription receipt paths) as well as an
  Eloquent `Business` model.
- Reuses the established data-URI precedent from `SendWelcomeEmail::logoDataUri()`.

## Why one edit covers every PDF

Every PDF blade already extends `reports.layouts.base` (verified for receipts,
invoices, estimates, sales tickets, shift-close, and income-statement exports), so the
logo appears consistently without touching individual report views.

## Notes

- No new API surface or migration; `logo_path` already existed.
- DomPDF renders data-URI images natively; a PNG test logo produced a valid `%PDF`
  output in the suite.
- Backend-only by design — the frontend storefront already displays `logo_path`.

## Tests

- `tests/Unit/Reports/ReportPdfLogoTest.php`: data URI present for a business with a
  logo, omitted when there is none or the file is missing, and a full DomPDF render
  yields a `%PDF` document.
