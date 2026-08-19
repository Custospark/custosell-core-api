# 13 - Forecasting

**Videos in this pack: 2**

See the future. Forecast sales and profit from your real data.

## Video: Forecast next month's sales
- Format: 3-6min deep dive
- Priority: P3
- Platforms: YouTube
- Tagline: "Know next month's sales before it happens."
- Description: Use Custosell's forecast to predict sales and profit from your
  history - plan stock, cash, and staffing with confidence.
- What it's about: Sales/profit forecast from historical data.
- Script beats:
  - Beat 1 (Hook): "Your past data can see the future."
  - Beat 2 (Problem): "Planning blind means stocking blind."
  - Beat 3 (Action): /forecast -> pick period -> read predicted sales/profit ->
    compare vs actual as it rolls in.
  - Beat 4 (Aha): "Now stocking decisions have a basis."
  - Beat 5 (CTA): "Try it free at custosell.com."
- Screen flow: /forecast -> period -> prediction -> comparison.
- On-screen text / captions:
  - "Plan with the future in hand"
- Demo data needed: Several months of sales history.
- CTA: Try free + subscribe
- Related videos: [02-dashboard-reports.md](./02-dashboard-reports.md)

## Video: Compare forecast vs actual
- Format: 45-90s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok
- Tagline: "How close was the forecast? Check it."
- Description: Compare your Custosell forecast to what actually happened and
  learn how reliable your numbers are.
- What it's about: Forecast-vs-actual review.
- Script beats:
  - Beat 1 (Hook): "A forecast you can trust is a forecast you check."
  - Beat 2 (Problem): "Guesses without feedback stay wrong."
  - Beat 3 (Action): /forecast -> past period -> forecast vs actual -> gap.
  - Beat 4 (Aha): "Next month's forecast just got smarter."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /forecast -> comparison view.
- On-screen text / captions:
  - "Forecast vs reality"
- Demo data needed: A closed month with a forecast.
- CTA: Try free
- Related videos: [13-forecasting.md](./13-forecasting.md)

---

## Technical reference (source of truth)

**Screens:** `/forecast` [M] (prediction + comparison)

**User actions (FE hooks):** `useForecast` · `useForecastVsActual` ·
`useForecastPeriod`

**API endpoints (BE):** `/forecast` (generate) + `/forecast/vs-actual` +
`/forecast/periods`