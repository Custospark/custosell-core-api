# 20 - Referrals, Credits & Sales Reps

**Videos in this pack: 3**

Grow by referral. Rewards, credits, and sales-rep tracking.

## Video: Refer a business and earn credit
- Format: 45-90s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok / YouTube
- Tagline: "Know a shop that needs Custosell? Earn credit for it."
- Description: Refer another business to Custosell and earn account credit - a
  simple way to lower your own cost while helping others.
- What it's about: Referral link + credit earning.
- Script beats:
  - Beat 1 (Hook): "The best marketing is a happy customer."
  - Beat 2 (Problem): "Word of mouth is powerful but untracked."
  - Beat 3 (Action): /referrals -> copy your link -> share -> watch credit
    arrive when they sign up.
  - Beat 4 (Aha): "Credit you can use on your own bill."
  - Beat 5 (CTA): "Try it free at custosell.com."
- Screen flow: /referrals -> link -> share -> credit balance.
- On-screen text / captions:
  - "Refer. Earn. Grow."
- Demo data needed: A referral link ready.
- CTA: Try free + subscribe
- Related videos: [16-subscriptions-billing.md](./16-subscriptions-billing.md)

## Video: Track your credits
- Format: 30-45s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok
- Tagline: "See your credits and how they're used."
- Description: Check your Custosell credit balance, how it was earned, and
  where it's applied to your billing.
- What it's about: Credit balance + ledger.
- Script beats:
  - Beat 1 (Hook): "Credit earned. Credit used. All tracked."
  - Beat 2 (Problem): "Rewards nobody can see feel fake."
  - Beat 3 (Action): /referrals -> credit balance -> ledger -> billing applied.
  - Beat 4 (Aha): "Full transparency on every shilling of credit."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /referrals -> credits -> ledger -> billing.
- On-screen text / captions:
  - "Earned. Tracked. Applied."
- Demo data needed: A credit balance with entries.
- CTA: Try free
- Related videos: [20-referrals-credits.md](./20-referrals-credits.md)

## Video: Track your sales reps
- Format: 45-90s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok
- Tagline: "Know which sales rep brought in what."
- Description: Assign and track sales reps on Custosell - see which rep's
  customers are buying and how much they drive.
- What it's about: Sales rep tracking by customer/sale.
- Script beats:
  - Beat 1 (Hook): "Your best rep isn't a guess - it's a number."
  - Beat 2 (Problem): "Commission disputes are easier to avoid with data."
  - Beat 3 (Action): /referrals -> sales reps -> assign to customers -> report
    on rep performance.
  - Beat 4 (Aha): "Pay commission on facts, not memory."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /referrals -> sales reps -> assign -> performance report.
- On-screen text / captions:
  - "Reps on record"
- Demo data needed: Customers assigned to reps.
- CTA: Try free
- Related videos: [07-customers.md](./07-customers.md)

---

## Technical reference (source of truth)

**Screens:** `/referrals` [M] (link, credits, sales reps)

**User actions (FE hooks):** `useReferralLink` · `useReferralCredit` ·
`useCreditLedger` · `useSalesReps` · `useAssignSalesRep` ·
`useRepPerformance`

**API endpoints (BE):** `/referrals/link` · `/referrals/credits` +
`/referrals/credits/ledger` · `/referrals/sales-reps` CRUD +
`/referrals/sales-reps/{id}/customers` + `/referrals/sales-reps/performance`