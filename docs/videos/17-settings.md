# 17 - Settings

**Videos in this pack: 4**

Business settings, preferences, and making Custosell yours.

## Video: Set up your business profile
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok / YouTube
- Tagline: "Your shop's identity - name, address, logo, done."
- Description: Complete your business profile on Custosell - name, address,
  currency, logo - so receipts and reports carry your brand.
- What it's about: Business profile setup.
- Script beats:
  - Beat 1 (Hook): "Receipts should look like you."
  - Beat 2 (Problem): "Generic receipts look unprofessional."
  - Beat 3 (Action): /settings -> business -> name, address, currency, logo ->
    save -> show it on a receipt.
  - Beat 4 (Aha): "Every document now carries your brand."
  - Beat 5 (CTA): "Try it free at custosell.com."
- Screen flow: /settings -> business profile -> save -> receipt preview.
- On-screen text / captions:
  - "Your brand, everywhere"
- Demo data needed: A logo image.
- CTA: Try free + subscribe
- Related videos: [01-account-auth-security.md](./01-account-auth-security.md)

## Video: Configure payment methods
- Format: 45-90s how-to
- Priority: P1
- Platforms: Reels / Shorts / TikTok / YouTube
- Tagline: "Cash, Mobile Money, card - set them up once."
- Description: Configure the payment methods your shop accepts on Custosell -
  then take payments without thinking about it.
- What it's about: Payment method configuration.
- Script beats:
  - Beat 1 (Hook): "Your customers pay their way. Set it up once."
  - Beat 2 (Problem): "Unconfigured payment methods slow the till."
  - Beat 3 (Action): /settings -> payments -> enable/add methods -> reorder ->
    sell with each.
  - Beat 4 (Aha): "Checkout now matches how your customers pay."
  - Beat 5 (CTA): "Try it free at custosell.com."
- Screen flow: /settings -> payments -> methods -> /sales/new checkout.
- On-screen text / captions:
  - "Pay your way"
- Demo data needed: A business without payment methods set.
- CTA: Try free + subscribe
- Related videos: [03-sales-pos.md](./03-sales-pos.md)

## Video: Customize tax and currency
- Format: 45-90s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok
- Tagline: "Tax and currency, set for your country."
- Description: Set VAT/tax rates and currency on Custosell so every price and
  report is correct for your location.
- What it's about: Tax + currency configuration.
- Script beats:
  - Beat 1 (Hook): "Prices, taxes, and reports - all correct."
  - Beat 2 (Problem): "Wrong tax on receipts is a legal headache."
  - Beat 3 (Action): /settings -> tax -> set rate -> currency -> save -> show
    VAT on a sale.
  - Beat 4 (Aha): "Taxes apply automatically everywhere."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /settings -> tax/currency -> save -> sale VAT preview.
- On-screen text / captions:
  - "Tax right, everywhere"
- Demo data needed: A business profile.
- CTA: Try free
- Related videos: [02-dashboard-reports.md](./02-dashboard-reports.md)

## Video: Export your data anytime
- Format: 30-45s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok
- Tagline: "Your data, exportable, always."
- Description: Export your Custosell data - reports, products, customers - to
  take with you or hand to an accountant.
- What it's about: Data export from settings.
- Script beats:
  - Beat 1 (Hook): "Your data is yours - take it anytime."
  - Beat 2 (Problem): "Locked-in data is a fear that keeps owners stuck."
  - Beat 3 (Action): /settings -> export -> pick data -> download.
  - Beat 4 (Aha): "Open data, no lock-in."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /settings -> export -> download.
- On-screen text / captions:
  - "No lock-in"
- Demo data needed: Business data present.
- CTA: Try free
- Related videos: [10-documents.md](./10-documents.md)

---

## Technical reference (source of truth)

**Screens:** `/settings` [M] (business, payments, tax/currency, export)

**User actions (FE hooks):** `useBusinessProfile` ·
`useUpdateBusiness` · `usePaymentMethods` · `useUpdatePaymentMethods` ·
`useTaxSettings` · `useUpdateTax` · `useExportData`

**API endpoints (BE):** `/settings/business` · `/settings/payment-methods` ·
`/settings/tax` · `/settings/currency` · `/settings/export`