# ADR-023: Frontend hook response-shape hardening - hooks return the unwrapped resource

**Date:** 2026-08-01
**Status:** Accepted

## Context

ADR-021 audited response shapes and ADR-022 completed the backend sweep: every single-resource endpoint now wraps in `{data: ...}` (implicit `return new Resource;` or explicit `['data' => ...]`). The frontend convention is that each query/mutation hook unwraps `{data: ...}` itself (there is **no** response unwrap interceptor in `axiosConfig.ts`).

The follow-up FE audit found no flat-read runtime bugs - every reader unwraps correctly and `syncEngine.ts` defends both shapes. However, a **latent silent-break hazard** remained: a handful of hooks returned the `{data: T}` envelope instead of the unwrapped resource. They worked only because every consumer happened to read `.data`; a future consumer (or refactor) reading the resource directly would silently get `undefined`.

## Decision

Normalize every hook that returned the `{data: T}` envelope to return the **unwrapped resource** (or typed list), and align all consumers to read the resource directly. Mutations whose results are read return the typed entity; mutations whose results are unused still return the unwrapped entity so the return contract is correct for future consumers.

**Hooks hardened (12 files, commit `fa31ad3`):**

| Hook | Endpoint | Before | After |
|------|----------|--------|-------|
| `useBookingSettings` | GET `pipeline/boards/{id}/booking-settings` | `{data: BookingSettings}` | `BookingSettings` |
| `useBookingInfo` | GET `public/book/{token}` | `{data: BookingInfo}` | `BookingInfo` |
| `useBookingSlots` | GET `public/book/{token}/slots` | `{data: {slots}}` | `{slots: TimeSlot[]}` |
| `useCheckBooking` | GET `public/book/{token}/check/{ref}` | `{data: BookingCheckInfo}` | `BookingCheckInfo` |
| `useUserLookup` | GET `users/lookup` | `{data: UserLookupResult}` | `UserLookupResult` |
| `usePaymentInfo` | GET `account/payment-info` | `{data: PaymentInfo}` | `PaymentInfo` |
| `usePayoutHistory` | GET `payouts/my-history` | `{data: PayoutRecord[]}` | `PayoutRecord[]` |
| `useRecordPayout` | POST `platform/payouts` | `{data: PayoutRecord}` | `PayoutRecord` |
| `useCreateCampaignCode` / `useUpdateCampaignCode` | POST/PUT `platform/referral-codes` | `{data: CampaignCode}` | `CampaignCode` |
| `useApproveBooking` / `useCompleteBooking` / `useRejectBooking` | `pipeline/leads/{id}/...-booking` | `{message, data: lead}` | `PipelineLead` |
| `useScheduleMeeting` / `useCreateMeeting` | `pipeline/leads/{id}/schedule-meeting` | `{message, data: meeting, ...}` | `PipelineLeadMeeting` |
| `useUpdateMeeting` | PATCH `pipeline/meetings/{id}` | `{message, data: meeting}` | `PipelineLeadMeeting` |

**Consumers aligned:** `BookingSettingsSection`, `LegacyBookingSection`, `CardBookingSection`, `BoardMemberPicker`, `AccountReferralsWinsTab`, `PublicBookingPage`, `PublicBookingCheckPage`.

**Verified non-goals (deliberately NOT changed):**
- `useCreateBooking` - backend `POST public/book/{token}` returns a bespoke payload with `reference_code` and `check_url` at the **top level** (plus `data`). The consumer reads those flat fields correctly; this is the actual API contract, not a wrapper-returning hook.
- `useUpdatePaymentInfo` - returns `{message}` which matches the backend `PUT account/payment-info` response; already correct.
- `useBookingInfo`/`useBookingSlots`/`useCheckBooking` return types tightened to the unwrapped resource; the `publicFetch<T>` helper still returns the whole body for `useCreateBooking`.

## Consequences

- Every hook now returns the resource its consumers read - no `data.data` indirection, no silent-break surface for future consumers.
- Typed surfaces tightened: mutation/query generics now match the actual unwrapped payloads (`PipelineLead`, `PipelineLeadMeeting`, `CampaignCode`, `PayoutRecord`, etc.) via existing FE types in `pipelineTypes.ts` / `PlatformTypes.ts`.
- Same-applies to future work: new hooks should `return data.data` (entities) or `return data.data ?? []` (lists), matching `usePayables` / `useCampaignCode` / `useCampaignCodeUsage`.

## Tests

- `npx tsc --noEmit` - clean.
- `npm run vera:fast` - passed (eslint on 12 files + logic incl. file-size-500).
- Backend untouched by this ADR (response shapes already normalized in ADR-022 sweep).
