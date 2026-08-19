# 01 - Account, Auth & Security

Signing up, protecting the account, and running multiple shops with one login.

## Video: Create your shop in 5 minutes
- Format: 45-90s how-to
- Priority: P1
- Platforms: Reels / Shorts / TikTok / YouTube
- Tagline: "Create your shop in 5 minutes."
- Description: Get your shop live on Custosell - register, set up your
  business, and complete onboarding in about five minutes. Start selling today.
- What it's about: The full signup -> business setup -> onboarding flow.
- Script beats:
  - Beat 1 (Hook): "Your shop can be live in 5 minutes."
  - Beat 2 (Problem): "Most software takes a whole afternoon to set up."
  - Beat 3 (Action): Sign up -> verify email -> create business -> complete
    onboarding steps -> land on dashboard.
  - Beat 4 (Aha): "That's it - you're ready to sell."
  - Beat 5 (CTA): "Start yours at custosell.com."
- Screen flow: /register -> verification -> /onboarding/business ->
  /onboarding steps -> dashboard.
- On-screen text / captions:
  - "Live in 5 minutes"
  - "Register. Verify. Sell."
- Demo data needed: A fresh email address for signup.
- CTA: Try free + subscribe
- Related videos: [03-sales-pos.md](./03-sales-pos.md) (first sale)

## Video: Run two shops with one login
- Format: 45-90s how-to
- Priority: P1
- Platforms: Reels / Shorts / TikTok / YouTube
- Tagline: "Run two shops with one login."
- Description: Link multiple shops to one Custosell account - switch between
  them, set a primary shop, and manage them all from a single login. Includes
  the profile photo fix that makes switching instant.
- What it's about: Linked accounts / account clusters - link, switch, set
  primary.
- Script beats:
  - Beat 1 (Hook): "Two shops. One login. Zero friction."
  - Beat 2 (Problem): "Owner of more than one shop juggles multiple logins."
  - Beat 3 (Action): Account manager -> link another shop -> switch between
    them -> set primary -> show profile photos resolve correctly.
  - Beat 4 (Aha): "Each shop stays separate - but one password runs them."
  - Beat 5 (CTA): "Try it free at custosell.com."
- Screen flow: Account manager -> Linked accounts -> link -> switch -> set
  primary.
- On-screen text / captions:
  - "One login. Many shops."
  - "Switch in a tap"
- Demo data needed: Two businesses on one account (or invite flow).
- CTA: Try free + subscribe
- Related videos: [02-dashboard-reports.md](./02-dashboard-reports.md)

## Video: Lock your account with 2FA
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "Lock your account. Sleep easy."
- Description: Turn on two-factor authentication on Custosell and review your
  security activity log. Keep your business data protected.
- What it's about: 2FA enable + security activity log.
- Script beats:
  - Beat 1 (Hook): "The one setting every shop owner should turn on."
  - Beat 2 (Problem): "Your shop data is only as safe as your password."
  - Beat 3 (Action): Settings -> Security -> Enable 2FA -> scan code -> enter
    code -> show activity log.
  - Beat 4 (Aha): "Even a stolen password won't get in now."
  - Beat 5 (CTA): "Try it free."
- Screen flow: Settings -> Security -> 2FA -> enable -> log.
- On-screen text / captions:
  - "2FA: on"
  - "Password isn't enough anymore"
- Demo data needed: A phone authenticator app for the demo.
- CTA: Try free
- Related videos: [17-settings.md](./17-settings.md)

## Video: Lost your password?
- Format: 45-90s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "Locked out? Back in within a minute."
- Description: The forgotten-password flow on Custosell - reset it safely and
  keep access to your shop. The number one support question, answered.
- What it's about: Forgot/reset password flow.
- Script beats:
  - Beat 1 (Hook): "This is how you get back in - calmly."
  - Beat 2 (Problem): "Locked out of your shop is the worst feeling."
  - Beat 3 (Action): Forgot password -> enter email -> open reset link -> set
    new password -> sign in.
  - Beat 4 (Aha): "Back in with your data exactly as you left it."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /forgot-password -> email -> reset link -> new password -> login.
- On-screen text / captions:
  - "Reset in 60 seconds"
- Demo data needed: A demo account to reset.
- CTA: Try free
- Related videos: [01-account-auth-security.md](./01-account-auth-security.md)

## Video: Change your email safely
- Format: 30-45s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok
- Tagline: "New email, same account, same data."
- Description: Change the email on your Custosell account with a verification
  code - safe, confirmed, and nothing else changes.
- What it's about: Profile email change with verification.
- Script beats:
  - Beat 1 (Hook): "Switching email? It's a two-step, safe change."
  - Beat 2 (Problem): "Changing email usually means losing access."
  - Beat 3 (Action): Profile -> Change email -> enter new -> enter verification
    code -> done.
  - Beat 4 (Aha): "Verified once, changed forever."
  - Beat 5 (CTA): "Try it free."
- Screen flow: Profile -> Email -> change -> verify code.
- On-screen text / captions:
  - "Verified change"
- Demo data needed: A demo account.
- CTA: Try free
- Related videos: [17-settings.md](./17-settings.md)

---

## Technical reference (source of truth)

**Screens:** `/register`, `/login`, `/forgot-password`, `/verify-email`,
`/onboarding/*`, Account manager -> Linked accounts, Settings -> Security /
Profile

**User actions (FE hooks):** `useRegister` · `useLogin` · `useLogout` ·
`useRequestPasswordReset` · `useResetPassword` · `useVerifyEmail` ·
`useUpdateProfile` · `useChangeEmail` · `useEnable2FA` / `useDisable2FA` ·
`useSecurityActivityLog` · `useLinkedAccounts` · `useLinkAccount` ·
`useUnlinkAccount` · `useSetPrimaryAccount` · `useSwitchAccount`

**API endpoints (BE):** `/auth/register` · `/auth/login` · `/auth/logout` ·
`/password/email` · `/password/reset` · `/email/verify` · `/profile` ·
`/profile/email` · `/security/2fa` · `/security/activity` · `/linked-accounts`
CRUD + `/linked-accounts/{id}/primary` + `/linked-accounts/{id}/switch`