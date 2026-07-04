# Notification Channels: In-App + SMS + Per-User Preferences — Design

**Status:** approved (design) · **Date:** 2026-07-04 · **Backlog:** P1-3

## Problem

The app has four mail notifications (`ApprovalRequestedNotification`, `ExpenseApprovalNotification`, `PaymentReminderNotification`, `CollectionNotification`), all `ShouldQueue`. Two of them (`ApprovalRequested`, `ExpenseApproval`) already declare `via() => ['mail', 'database']`, **but there is no `notifications` table** — so the database channel errors/no-ops. There is no SMS channel, and users can't choose how they're notified. A bank-import failure only raises a transient Filament toast (`BankStatementResource` import action), never a durable notification.

## Decisions (locked)

- **SMS via Vonage**, credentials **team-level** (one SMS account per business/team), stored encrypted.
- **Per-user preferences**: each user self-configures their phone + per-channel opt-in (mail / in-app / SMS).
- **In-app** via Filament's built-in database notifications (bell + panel), not a custom UI.
- **Scope: fix-broken + fill-gap** — unbreak the database channel, add SMS to customer-facing sends, add the missing failed-import notification. Existing send-points (approval requested/decided, due/overdue reminders, collections) stay as they fire today.

## Non-goals (YAGNI)

- Push notifications, Slack, per-event-type preference matrix (just per-channel on/off).
- New invoice-due (pre-due) reminders or Invoice/Bill approve/reject notification parity (only Expense notifies on decision today — leave it).
- Per-user Vonage accounts (creds are team-level), customer self-service preferences (customers are external; staff set their phone).
- Retrying/queuing failed SMS beyond Laravel's existing queue behavior.

## Architecture — three domains over a shared foundation

**Build order (they are not independent from cold):** a small foundation lands first — the `notifications` table (Domain 1), the `ResolvesChannels` trait, `SmsChannel`, and the data model (`teams` creds, `UserNotificationPreference`, routing methods) from Domain 2. Only then do the leaf edits parallelize cleanly on disjoint files: the four notifications' `via()`/`toSms()`, the two Filament settings pages, and the failed-import notification (Domain 3, which consumes the trait). The file partition below is what makes the leaf work parallel-safe; the foundation is sequential.

### Domain 1 — In-app / database channel (infra)

- New migration: the standard Laravel `notifications` table (`php artisan notifications:table` shape — uuid `id`, `type`, morph `notifiable`, json `data`, `read_at`, timestamps).
- `AppPanelProvider`: add `->databaseNotifications()` to the panel chain (bell + slide-over for the authenticated staff user).
- Files: new migration, `app/Providers/Filament/AppPanelProvider.php`. No notification-class edits (the two that need `database` already declare it and have `toArray()`).

### Domain 2 — SMS system + per-user preferences (the bulk)

**Data model**

- `teams` migration adds: `vonage_key` (string, nullable, **encrypted** cast), `vonage_secret` (string, nullable, **encrypted** cast), `vonage_from` (string, nullable). Added to `Team::$fillable` + `Team::casts()`.
- New `UserNotificationPreference` model + `user_notification_preferences` migration: `user_id` (FK, unique), `phone` (string, nullable), `mail_enabled` (bool, default true), `database_enabled` (bool, default true), `sms_enabled` (bool, default false). `User` gains `notificationPreference(): HasOne`.

**Custom channel** — `App\Notifications\Channels\SmsChannel`:

```
send($notifiable, Notification $notification):
    if ! method_exists($notification, 'toSms'): return
    $team = resolveTeam($notifiable)            // Customer->team_id | User->current_team_id
    if ! $team?->vonage_key or ! $team->vonage_secret or ! $team->vonage_from: return   // skip, no error
    $to = $notifiable->routeNotificationFor('sms', $notification)
    if ! $to: return
    $client = new Vonage\Client(new Basic($team->vonage_key, $team->vonage_secret))
    $client->sms()->send(new SMS($to, $team->vonage_from, $notification->toSms($notifiable)))
```

`resolveTeam()` reads `team_id` (Customer/IsTenantModel) or `current_team_id` (User); returns null → skip. The team-creds check is the OSS-safe gate: no creds configured → SMS silently off, mail/in-app unaffected. Pulls `laravel/vonage-notification-channel` (for the `vonage/client` classes); the client is built **per-team per-send**, not from the app singleton.

**Routing** — `Customer::routeNotificationForSms()` → `customer_phone`; `User::routeNotificationForSms()` → `notificationPreference?->phone`.

**Channel gating** — a small trait `App\Notifications\Concerns\ResolvesChannels` with `channelsFor($notifiable): array`, called by each notification's `via()`:
- `$notifiable instanceof User`: read `notificationPreference` (null → defaults mail=on, database=on, sms=off). Include `'mail'`, `'database'`, and `SmsChannel::class` (only if `sms_enabled` **and** a phone is set).
- Customer / other: `['mail']` plus `SmsChannel::class` when `routeNotificationForSms()` is non-empty. No `'database'` (customers have no panel).

Each notification adds `toSms($notifiable): string` (short plaintext) and switches `via()` to `return $this->channelsFor($notifiable);`. Applies to all four notifications.

**Settings UI (2 Filament pages, app panel `app/Filament/App/Pages`)**
- `TeamNotificationSettings` — edits the current team's (`Filament::getTenant()`) Vonage creds. Secret fields (password inputs, not re-displayed in plaintext). Gated to team admins.
- `NotificationSettings` — edits the current user's `UserNotificationPreference` (phone + three toggles), upserting the row.

Files: `Team.php`, `User.php`, `Customer.php`, new `UserNotificationPreference.php`, two migrations, `SmsChannel.php`, `ResolvesChannels.php`, the four notification classes, two Filament pages.

### Domain 3 — Failed-import notification (the event gap)

- New `ImportFailedNotification` (`ShouldQueue`) with `toMail()` + `toArray()` (via `channelsFor`, staff → mail + database). Carries the `BankStatement` + error message.
- `BankStatementResource` import action: in the existing `catch (Exception $e)` block (which already shows a toast), also `auth()->user()->notify(new ImportFailedNotification($record, $e->getMessage()))` so there's a durable mail + in-app record.
- Files: new `ImportFailedNotification.php`, `BankStatementResource.php`.

## Data flow

- Approval/expense/import → notify a **User** → `channelsFor()` reads that user's prefs → mail / bell / (SMS if opted-in + phone + team creds).
- Payment reminder / collection (scheduled, no acting user) → notify a **Customer** → mail always + SMS when the customer has a phone and the owning team has Vonage creds. `SmsChannel` resolves the team from `customer.team_id`, so no logged-in user is needed.

## Error handling / safety

- Missing team creds, missing phone, or channel disabled → the channel/`via()` omits or the channel returns early. Never throws for an unconfigured tenant.
- Vonage creds are encrypted at rest (`encrypted` cast); settings pages use password fields and never echo stored secrets back in plaintext.
- SMS send failures propagate through Laravel's queue (the notification is `ShouldQueue`); no bespoke retry.

## Testing (PHPUnit, sqlite `:memory:`; `Notification::fake()` throughout — no real Vonage send)

1. **DB channel unbroken:** a `User` (default prefs) notified with `ApprovalRequested` → `assertSentOnChannel('database')`; the `notifications` migration exists and a real (non-faked) send persists a row.
2. **Preferences gate:** user with `database_enabled=false` → not sent on `database`; `mail_enabled=false` → not on `mail`.
3. **SMS via() inclusion:** customer with a phone + team with creds → `SmsChannel` in channels; customer with no phone, or team with no creds → `SmsChannel` absent. `toSms()` returns the expected text.
4. **SmsChannel skip:** `(new SmsChannel)->send()` with a team lacking creds → returns null, no exception, no client built (covers the OSS-default path).
5. **Failed import:** import action `catch` path → `assertSentTo($user, ImportFailedNotification::class)`.
6. **Encrypted creds:** team `vonage_secret` set → the raw `teams` column value differs from the plaintext (encryption applied).

The live Vonage HTTP send is integration-only (external) and intentionally not unit-tested; the skip path and channel selection are.

## Files

- **New:** `app/Notifications/Channels/SmsChannel.php`, `app/Notifications/Concerns/ResolvesChannels.php`, `app/Notifications/ImportFailedNotification.php`, `app/Models/UserNotificationPreference.php`, `app/Filament/App/Pages/{TeamNotificationSettings,NotificationSettings}.php`, migrations (`notifications`, `add_vonage_to_teams`, `create_user_notification_preferences`), `tests/Feature/Notifications/*`.
- **Changed:** `AppPanelProvider.php`, `Team.php`, `User.php`, `Customer.php`, the four notification classes, `BankStatementResource.php`, `composer.json` (vonage channel), `config/services.php` (optional vonage placeholder).
