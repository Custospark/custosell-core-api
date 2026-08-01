# Platform Businesses Table Reorg + Manual Subscription Activation

**Date:** 2026-08-02
**Status:** Accepted
**Scope:** Frontend + Backend

## Context

The Platform Businesses table used four inline icon buttons per row (notify / status / delete /
wipe) and had no subscription filter, and platform admins had no way to start a subscription for
a business that never signed up through the normal flow. The backend `PlatformBusinessService`
had also grown to 1008 lines, tripping the `file-size-500` gate on the same change.

## Decision

- **Businesses table matches the products-table pattern.** Inline icon buttons were replaced by a
  kebab dropdown (`PlatformBusinessRowActions`, mirroring `ProductRowActions`: MoreVertical,
  ref-based menu positioning, `MENU_ITEM_CLASS`). An indexed `#` column was added (row number
  from pagination) and the Actions column is now labeled and center-aligned.
- **Subscription-status filter added.** A filter select (active / trial / past_due / suspended /
  cancelled / expired / no subscription) filters rows by `PlatformBusiness.subscription_status`;
  the "none" option matches businesses with no subscription relation. The filter bar was extracted
  into `PlatformBusinessFilters` to keep the page ≤ 500 lines.
- **Manual subscription activation.** The Actions menu shows "Activate subscription" only for
  businesses with no subscription. It opens `PlatformActivateSubscriptionModal` (plan via
  `usePlans`, monthly/yearly billing cycle) and calls the new endpoint below. Activation reuses
  the normal onboarding chain: `SubscriptionService::subscribe` then `activateAfterOnboarding`,
  recording `approved_by_user_id` and a `business.subscription.activated` audit entry.
- **New API surface:**
  - `POST /platform/businesses/{id}/subscription` with `plan_id` (exists in `plans`) and optional
    `billing_cycle` (`monthly|yearly`); 422 if the business already has a subscription.
  - `GET /platform/businesses` accepts `subscription_status` (including `none`).
- **Backend service modularized.** `PlatformBusinessService` (1008 lines) was split into:
  - `PlatformBusinessQueryBuilder` — attributed-sales metrics query + owner/staff resolution.
  - `PlatformBusinessMetricsService` — analytics, transformation, onboarding dashboard, tiers.
  - `PlatformBusinessAdminService` — status/delete/reset/notify + `activateSubscription`.
  - `PlatformBusinessService` is now a slim facade preserving the full public API, so
    `PlatformBusinessController` and `PlatformOverviewService` were unchanged.

## Trade-offs

- `per_page` is 500 for the businesses list, so the subscription filter is applied client-side
  rather than via the API `subscription_status` param (though the param is now supported).
- The activation modal resets per business via a `key` remount (same pattern as the reset modal)
  rather than an effect.
