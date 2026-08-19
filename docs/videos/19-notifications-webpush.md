# 19 - Notifications & Web Push

**Videos in this pack: 4**

Updates from the Custosell team, pipeline activity, and alerts that reach you.

## Video: Read your notifications inbox
- Format: 30-45s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "Team updates and pipeline news, all in one inbox."
- Description: Open your notifications on Custosell to see updates from the
  Custosell team and activity from your pipeline - boards, comments, reminders -
  with unread messages flagged and marked read as you open them.
- What it's about: Notifications inbox.
- Script beats:
  - Beat 1 (Hook): "Everything you need to know, in one place."
  - Beat 2 (Problem): "Important updates scatter across the app."
  - Beat 3 (Action): Open the bell -> /notifications -> filter All or Unread ->
    tap Open -> read -> it's marked read.
  - Beat 4 (Aha): "Open once, read and done."
  - Beat 5 (CTA): "Try it free at custosell.com."
- Screen flow: navbar bell -> /notifications -> All/Unread filter -> Open a message.
- On-screen text / captions:
  - "One inbox"
- Demo data needed: A few unread notifications.
- CTA: Try free
- Related videos: [19-notifications-webpush.md](./19-notifications-webpush.md)

## Video: Keep your inbox clean
- Format: 30-45s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok
- Tagline: "Mark read, delete, done."
- Description: Manage your notifications on Custosell - mark all as read,
  delete one message, or clear your whole inbox in a couple of taps.
- What it's about: Notification management.
- Script beats:
  - Beat 1 (Hook): "A clean inbox, a clean head."
  - Beat 2 (Problem): "Clutter buries the messages that matter."
  - Beat 3 (Action): /notifications -> Mark all as read -> select messages ->
    Delete -> or Delete all.
  - Beat 4 (Aha): "Two taps and it's tidy."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /notifications -> Mark all as read -> select -> Delete -> Delete all confirm.
- On-screen text / captions:
  - "Tidy inbox"
- Demo data needed: Multiple notifications, some read.
- CTA: Try free
- Related videos: [19-notifications-webpush.md](./19-notifications-webpush.md)

## Video: Get desktop notifications
- Format: 45-60s how-to
- Priority: P2
- Platforms: Reels / Shorts / TikTok
- Tagline: "Alerts even when the app is closed."
- Description: Turn on desktop notifications on Custosell so new orders, sales,
  and account updates arrive as system alerts - even when you're not looking at
  the app.
- What it's about: Web Push enable/disable.
- Script beats:
  - Beat 1 (Hook): "Your shop tells you - even when the app is closed."
  - Beat 2 (Problem): "You miss the moment something happens."
  - Beat 3 (Action): /notifications -> Desktop notifications -> flip the switch ->
    allow in the browser -> alerts start arriving.
  - Beat 4 (Aha): "New order, new alert - instantly."
  - Beat 5 (CTA): "Try it free at custosell.com."
- Screen flow: /notifications -> Desktop notifications card -> toggle -> browser permission -> done.
- On-screen text / captions:
  - "Alerts on"
- Demo data needed: A browser where notifications are supported.
- CTA: Try free
- Related videos: [19-notifications-webpush.md](./19-notifications-webpush.md)

## Video: Hear when new orders arrive
- Format: 30-45s how-to
- Priority: P3
- Platforms: Reels / Shorts / TikTok
- Tagline: "A chime when the order comes in."
- Description: Set the order sound on Custosell so your device chimes when a new
  open order arrives - and set a big-order threshold so large orders get an
  urgent chime and a highlighted alert.
- What it's about: Order chime and big-order alert (business accounts).
- Script beats:
  - Beat 1 (Hook): "You'll hear the next order before you see it."
  - Beat 2 (Problem): "Open orders get missed at a busy till."
  - Beat 3 (Action): /notifications -> Order sound -> toggle on -> Play test
    sound -> set the big-order amount.
  - Beat 4 (Aha): "Big order in, urgent chime out."
  - Beat 5 (CTA): "Try it free."
- Screen flow: /notifications -> Order sound card -> toggle -> threshold -> test sound.
- On-screen text / captions:
  - "Chime on order"
- Demo data needed: A business account.
- CTA: Try free
- Related videos: [03-sales-pos.md](./03-sales-pos.md)

---

## Technical reference (source of truth)

**Screens:** `/notifications` [M] (modules/notifications/NotificationsPage) -
inbox with All/Unread filters, open/read, mark-all-read, delete
single/selected/all; the navbar bell (HeaderNotifications) shows the unread
count; PushNotificationsCard and OrderSoundCard live at the top of the inbox.

**User actions (FE hooks):** `useNotifications` (paginated, `per_page`,
`unread_only`) · `useNotificationUnreadCount` · `useMarkNotificationRead` ·
`useMarkAllNotificationsRead` · `useDeleteNotification` ·
`useBulkDeleteNotifications` · `useDeleteAllNotifications` · `useWebPush`
(toggle + status via `fetchWebPushStatus` / `storePushSubscription` /
`removePushSubscription`) · `useSoundPreferences` (`orderSound`,
`bigOrderThreshold`).

**API endpoints (BE):** `GET /notifications` · `GET /notifications/unread-count`
· `PATCH /notifications/read-all` · `PATCH /notifications/{id}/read` ·
`DELETE /notifications/{id}` · `POST /notifications/bulk-delete` ·
`DELETE /notifications/delete-all` · `GET /webpush/status` ·
`POST /webpush/subscribe` · `DELETE /webpush/unsubscribe`.

**Middleware:** notifications routes use `auth:sanctum` + `business.active`;
web-push routes use `auth:sanctum` only.

**Notable behavior:** notification types are `business_status`,
`platform_message`, `user_status`, and pipeline events
(`pipeline.assignment`, `pipeline.comment`, `pipeline.announcement`,
`pipeline.poll`, `pipeline.reminder`, `pipeline.board_message`) - the inbox is
for team messages and pipeline activity, not stock/payment alerts. Opening a
message auto-marks it read; unread bubbles show on the bell. Web Push is
browser-only (hidden in Electron) and delivers new orders, sales, and account
updates as system notifications even while the app is closed. The order sound
and big-order threshold are device-local preferences for business accounts.