# Subscription Receipts & Unified Billing History — backend

## Status

Adopted 2026-08-03.

## Context

Subscribers could see a raw list of payments but had no way to retrieve or share an
official receipt for a paid charge, and plan changes / credit applications lived in
separate places from payments. We needed:

1. A **branded receipt PDF** (Custospark Company Ltd) downloadable for any completed
   payment, and a way to **email** it to the subscriber.
2. A **unified billing history feed** merging payments, scheduled plan changes, and
   credit applications, newest-first.

## Decision

### Receipt service

`app/Services/Payment/PaymentReceiptService.php`:

- Renders `resources/views/payments/subscription-receipt.blade.php` with the `dompdf`
  engine. The view is Custospark-branded: header shows **Custospark Company Ltd**,
  Kampala, Uganda, phone `+256 756 697 871`, email `support@custosell.com`.
- Blade uses explicit `$accent` (primary color) and `$formatter` (money/date formatter)
  variables so styling/formatting stays view-side.
- PDF sections: **Subscription** table (plan, cycle, period, subscriber) and **Amount**
  table (TOTAL PAID, plan rate, credit deduction), with a "PAID IN FULL" footer.
- Filename: `receipt-{transaction_reference}.pdf`.
- `emailReceipt()` attaches the PDF via `StandardEmail` to the subscriber.

### Controller + routes

`PaymentController` gains:

- `downloadReceipt(Payment $payment)` → `GET /billing/payments/{payment}/receipt`
  returns the PDF as an attachment (streamed download, validated completed status).
- `emailReceipt(Payment $payment)` → `POST /billing/payments/{payment}/receipt/email`
  emails the receipt and returns a confirmation payload.

Routes live in `routes/api/v1/billing.php` inside the subscription billing group.

### History feed

`app/Services/Billing/BillingHistoryService.php`:

- `feed(Subscription $subscription)` merges `payments` + `scheduledChanges` +
  `creditApplications` (new `Subscription::creditApplications()` relation and
  `BillingPayment::user()` relation added), tags each item with its `kind`, and sorts
  newest-first. Exposed as `GET /billing/history`.

### Brand config

`config/brand.php` now carries `company_*` keys (name, url, phone, email). The phone
uses the real support number `+256 756 697 871` matching the frontend brand constants.

## Failure states

- Download/email of a non-completed payment → 422 with actionable message.
- Unknown payment id → 404 (standard route model binding).
- DOM/PDF rendering failure → 500 with `log::error` context; frontend surfaces a toast.

## Verification

- `PaymentReceiptAndHistoryTest` (4 tests): renders PDF, download response headers +
  body, email attachment, history feed merge ordering.
- Full billing suite still green (57 tests).
- Live smoke: `GET /billing/history` merged feed; `GET /billing/payments/13/receipt`
  → 200, `Content-Type: application/pdf`, attachment filename matches, 880 KB valid PDF.

## Related files

- `app/Services/Payment/PaymentReceiptService.php`
- `app/Services/Billing/BillingHistoryService.php`
- `resources/views/payments/subscription-receipt.blade.php`
- `app/Http/Controllers/Api/Billing/PaymentController.php`
- `routes/api/v1/billing.php`
- `config/brand.php`
- `app/Models/Subscription.php`, `app/Models/BillingPayment.php` (new relations)
- `tests/Unit/Billing/PaymentReceiptAndHistoryTest.php`
