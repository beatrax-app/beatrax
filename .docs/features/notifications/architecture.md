# `Notifications` — architecture

The `Notifications` module owns a single, deduplicated inbox
(`/notifications`) that unifies eleven trigger types — payment reminders,
budget nudges, savings prompts, position digests, drift alerts, forecast
shortfalls, coalesced imports, matched e-mail receipts, hand-entered cash
entries, finished budget migrations, and ICS-statement-ready nudges — behind one persisted
`notifications` row per logical event, one delivery suppression seam,
and one state machine.

Every one of those titles has to hold for **every** state its trigger can
be raised in, which is a harder question than it reads as and the one
five of them got wrong — see
[a notification title that only holds for one of the states that raise
it](../../conventions/invariants-from-shipped-failures.md#a-notification-title-that-only-holds-for-one-of-the-states-that-raise-it).

## Deterministic deduplication

Every notification row's primary key is a sha256 string digest of
`(user_id, trigger_type, subject_key, occurrence)`, computed exclusively
by `DeterministicKeyDeriver::derive()` — the one place any notification
id is ever computed. Byte-identical input produces byte-identical output
across two independent devices; that identity is the CRDT convergence
mechanism the whole design relies on. Two devices that each independently
decide "this user's forecast just went into shortfall for this window"
derive the same row id and converge on one row with no sync-engine
change — the dedup happens at insert time via the primary key, not a
post-hoc merge step. `NotificationWriter::write()` is the sole caller: it
derives the id, encrypts the four `SensitiveFieldRegistry`-registered
columns (`title`/`body`/`params`/`trigger_type`) under the current
encryption epoch, and `insertOrIgnore`s the row — a duplicate write is a
silent no-op. Both `NotificationMutated` (sync capture) and
`NotificationDeliverable` (delivery adapters) are dispatched only when
the insert actually landed, so a duplicate dispatch never double-fires
either event, and only after the insert has committed.

`NotificationDeliverable` carries everything an adapter needs to render
a banner as plaintext values riding the event itself — the title/body
are never re-read from the (possibly encrypted-at-rest) `notifications`
row, so no delivery adapter needs an encryption key, a DB read, or the
app-lock to be unlocked at delivery time. The event is dispatched and
consumed inside one Laravel event-bus tick, locally; it never crosses a
process or network boundary.

`id`/`user_id`/`state`/`read_at`/`dismissed_at` are deliberately *not*
part of the encrypted-field registry: the primary key is matched in
`WHERE` dedup clauses, and the timestamps drive KEK-less unread counts
and pruning.

Key ordering is fixed by the literal array order inside `derive()` — a
caller must never build the payload from a caller-supplied array, since
a differently-ordered `json_encode` would silently change the digest and
break convergence between devices running different code paths. The
`JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE` flag pair keeps a
non-ASCII `subject_key` (e.g. a merchant name) byte-identical across
encodes, and turns any encoding failure into a loud exception rather
than a silently ignored `false` return.

### Occurrence-key strategy per trigger

The occurrence key is what makes each trigger type's "don't re-fire for
the same logical event" rule true — different trigger types choose
different occurrence keys because their real-world identity differs:

| Trigger | Occurrence key | Why |
|---|---|---|
| Payment reminder | the due date (date only, never a datetime) | two devices computing at different times of day still derive the same id |
| Budget nudge | the budget period | a second crossing in the same period is a no-op; advancing to the next period legitimately re-fires |
| Savings prompt | the insight's own stable key | already stable across re-runs |
| Position digest | the digest's own occurrence string (a date for daily cadence, an ISO week for weekly) | one digest per calendar unit |
| Drift alert | the drift alert's own row id | `drift_alerts` already enforces one row per detected drift |
| Forecast shortfall | the shortfall window's start date | one row per detected window start |
| Coalesced import, matched receipts, hand-entered cash | the batch's completion timestamp (second precision) + inserted count | an import is a local, user-initiated event with no independent second-device re-derivation, so it only needs to not double-fire for one batch, not converge across devices |
| ICS statement ready | the statement-arrival day (`Y-m-d`), not the message id | a resend or a re-scan on a later tick lands on the same day key; two distinct statements on different days do not collide |

## Never-throw listener envelope

Every `Persist*` listener (`PersistBudgetNudge`, `PersistCoalescedImport`,
`PersistDriftAlert`, `PersistForecastShortfall`, `PersistIcsStatementReady`,
`PersistPaymentReminder`, `PersistPositionDigest`, `PersistSavingsPrompt`)
and `ResolveSettledReminder` wrap their entire handler body in a
try/catch that logs and swallows any throwable — a failed
notification-persist or resolve must never break the job or event chain
that dispatched the originating trigger event. Each listener subscribes
to an event owned by its trigger's own module (never
`Modules\Notifications\Public\Events`, which only carries
`NotificationDeliverable` since Notifications is that event's producer),
registered by `NotificationsServiceProvider`'s single-owner, `class_exists()`-guarded
listener table — every registration checks both the listener class and
the trigger event class exist before calling `Dispatcher::listen()`,
since several of each may not exist yet during incremental rollout.

## State machine: a narrow resolved/withdrawn axis only

`notifications.state` carries only the open/resolved axis (Req 13's
resolved/withdrawn outcome for a reminder whose charge later settles) —
there is no "unread" concept in this column and no "mark as unread"
transition. `NotificationStateMachine` is the single legal mutator,
enforcing `open -> resolved` (and nothing else) both at the application
layer (throws on an illegal transition) and at the database layer (a
trigger pair aborts any out-of-enum value even if some other code path
bypassed this class). `read_at` (a plain nullable one-way latch) and
`dismissed_at` (a plain nullable, reversible timestamp) are written
directly by `MarkNotificationRead`/`DismissNotification`/`UndoDismissNotification`
and by the op-log replayer — never through the state machine, and the
replayer never touches `state` at all, since `state` is locally-derived
from a settlement check, not a synced field. `ResolveSettledReminder`
re-derives the same deterministic id `PersistPaymentReminder` wrote from
`(userId, NotificationTrigger::PaymentReminder, seriesId, dueDate)` and flips it to
`resolved` only when the row exists and is still `open` — a missing row
(the reminder never fired) or an already-resolved row is a silent no-op.

Every Public action (`MarkNotificationRead`, `DismissNotification`,
`UndoDismissNotification`) treats the sha256 primary key strictly as a
`WHERE` match, never as an authorization boundary — every query carries
an explicit `user_id` scope, since `BelongsToUser`'s global scope does
not fire in queue/console context.

## Delivery suppression

`SuppressionEvaluator::shouldDeliver()` is the one place delivery
suppression is decided, and it takes a `NotificationTrigger` rather than
a string; both platform delivery adapters
(`Modules\Desktop`'s `DispatchOsNotification` and `Modules\Mobile`'s
`DispatchMobileNotification`) call it exclusively. It decides delivery
only — it never prevents a row from being written, and the writer always
persists the row regardless of what this returns. Evaluation order: an
active seeding flag suppresses everything (reason `seeding`); a
per-trigger toggle off suppresses (reason `trigger_disabled`); inside the
device's quiet-hours window suppresses (reason `quiet_hours`); otherwise
delivered (reason `ok`). `hideDetails` always reflects the stored
preference regardless of the deliver outcome, so a delivery adapter can
never forget to consult it. A handful of trigger types
(import-finished, receipts-found, manual-entry-recorded,
migration-finished, drift-changed, forecast-shortfall,
ics-statement-ready) carry no per-trigger toggle in
the preferences DTO and are always deliverable —
`NotificationTrigger::requiresToggle()` is where that half is stated. The
toggle match names all eleven cases and has no default arm, so a new trigger
is a static-analysis failure rather than a row written and then silently
never delivered; the trigger type is the enum end to end, so an
unrecognised one cannot be constructed.

`suppressDelivery()` is the seeding/test suppression flag — it runs a
callback with delivery globally suppressed, restoring the prior flag
value in a `finally` (reentrant-safe), so a demo seeder or feature test
can dispatch real trigger events and have rows stored without ever
pushing to the OS.

### The sentence beside the switch is part of the contract

The settings switch is bound to `hideDetails`, so **on** is the state
that keeps amounts and merchant names off the banner. The help under it
shipped describing the control that does not exist — *"Show amounts and
merchant names in the notification banner itself. Turn off if your
screen might be visible to others."* A reader who took that advice
turned the switch **off**, which is the one state that puts the amounts
on the lock screen; all 25 translations carried the inversion
faithfully, because each is a faithful translation.

`Modules/Notifications/tests/Feature/TheHideDetailsHelpPointsAtThePrivateStateTest.php`
is what stops it coming back. It does not compare the sentence to a
literal — a rewrite would take the literal with it. It reads the switch
direction *out of* the shipped sentence, sets the settings section to
that state, saves, and asks the evaluator what a delivery adapter would
be told. The advice, the rendered switch and the decision have to agree,
and there is nowhere left for the sentence to be right on its own.

## Per-device preferences

`notification_preferences` is keyed `(user_id, device_id)` — one row per
device, not per user, since quiet hours and toggles are inherently
per-device settings. `NotificationPreferenceQuery` is the sole Public
read/write seam: `forCurrentDevice()` reads (or returns locked defaults
for) this device's row, `forOtherDevices()` reads every other device
read-only for the settings "Other devices" panel, and
`saveForCurrentDevice()` is the only write path, validating server-side
(out-of-range input throws, never clamps) and dispatching
`NotificationPreferenceMutated` only after the write commits.

An install with no sync identity — `DeviceRegistryService::localDeviceId()`
null, which is the DEFAULT state and the whole life of a single-device
install — writes its row under the reserved `device_id`
`NotificationPreferenceQuery::UNPAIRED_DEVICE_ID`. It used to be a logged
no-op, so the entire ~9-control settings section was inert on any install
that never paired: quiet hours, lead days and "hide details" all reverted
on reload while the UI said "Saved", and `SuppressionEvaluator` read the
defaults through the same seam and kept posting at 03:00 with amounts
shown. The sentinel is deliberately not a UUID, which a real `device_id`
always is, so it can never collide with a peer's. `saveForCurrentDevice()`
renames that row onto the real device id the first time the install has
one, and `forCurrentDevice()` falls back to it, so pairing neither loses
the settings nor leaves the pre-pairing row showing up as a foreign device
in "Other devices".

`NotificationPreferenceMutated`'s `preferenceId` is
`notification_preferences.id`, a local autoincrement surrogate — unlike
the notification table's sha256 primary key, this table is not
independently generated on two devices, so the usual PK-convergence
argument does not apply to it.

## Render-time deep links

`DeepLinkResolver::resolve()` re-validates every row's deep link at
render time, never from write time or a background sweep, so a target
deleted after delivery degrades to a disabled, explained link instead of
a 404 or a stale URL. It re-reads and decrypts the row's own `params`
column directly (rather than threading it through `NotificationQuery`'s
DTO) and re-runs the same user-scoped existence query the deep-link
target itself would use — a target belonging to another user resolves
exactly like a deleted target: disabled, generic copy, no distinguishing
signal. A handful of `target_kind` values (`dashboard`, `forecast`,
`inbox`, `import`, `ics-import`, `cash-book`, `migration`) never carry a
deletable per-user entity
and are always live; the rest (`series`, `budget`, `counterparty`,
`transaction`) resolve through each owning module's Public existence
check. A row that never carried a `target_kind` at all is a different
answer from a target that is gone: it renders as a plain non-link with
NO explanation line, because "This item no longer exists." about
something that never existed is a lie the reader cannot check. A kind
this build does not recognise still degrades to the neutral kind
`item` — the explanation line is a whole sentence per kind, so the kind
also names a translation key and must never be a raw payload string.
`NotificationCopy` holds one thing — the type-chip glyph and **lang key**
per trigger, plus the neutral `◌` chip a trigger this build cannot name
falls back to; the word itself is resolved per reader through
`Lang::get()`, because `aria-hidden` keeps the chip from a screen reader
and not from eyes ([copy that follows the
reader](reader-language-copy.md)). Titles and bodies live in the
`notifications::copy` lang files and are re-rendered per reader by
`NotificationCopyRenderer`, so the OS banner and the inbox take the same
sentence from the same key.

## Retention

`PruneNotificationsJob` runs a per-user daily sweep deleting rows older
than a fixed 365-day window, matching `CounterpartyGarbageCollectorJob`'s
retention precedent exactly (a single grep-able number, not a
`config()`-driven tunable). This is deliberately *not* a "history
retained forever" violation — that project rule governs transactions,
not the notification inbox, which is explicitly carved out. The sweep
keys solely on the plaintext `created_at` column and touches none of the
four encrypted columns, so — unlike the counterparty GC job, whose
predicate does compare an encrypted column — this job needs no
encryption key to run correctly: on a locked or headless device the
sweep still runs and the inbox still stays bounded. Optional
key-service/session parameters exist purely as a forward-compatible
safety net (logged, never gating today's delete) in case a future change
ever extends the predicate onto an encrypted column.

## Demo seeding

`DemoNotificationsSeeder` never writes a `notifications` row directly and
never reaches for the module's internal writer — it dispatches the real
trigger events (`PaymentReminderDue`, `BudgetThresholdCrossed`, etc.) and
lets the real `Persist*` listeners derive the id and write the row, the
same code path production traffic runs through. The entire run is
wrapped in `SuppressionEvaluator::suppressDelivery()` so seeding never
fires a real OS/mobile notification. Every dispatch runs under
`CarbonImmutable::setTestNow()` pinned to a specific past instant
(captured once before any freezing, restored in a `finally`), so
`created_at` spreads naturally across recent days and a second seed run
derives byte-identical occurrence keys, collapsing via the writer's
idempotent insert like any other repeated trigger.

`NotificationQuery` clones `DriftAlertQuery`'s shape (`PAGE_SIZE` rows
plus one lookahead row, read and then WITHHELD) with one mandatory
deviation: `drift_alerts.id` is an
autoincrement surrogate, so `ORDER BY id DESC` + `WHERE id < cursor`
stays monotone with insertion order, but `notifications.id` is a sha256
hex digest and is not insertion-ordered. This query instead sorts on
`created_at DESC, id DESC` and pages on a compound cursor —
`(created_at < ?) OR (created_at = ? AND id < ?)` — backed by a
`(user_id, created_at, id)` index. A malformed cursor is treated as null
(first page) rather than thrown. The three page reads return a
`array{rows: list<NotificationDto>, nextCursor: ?string}` — the rows and
the cursor that follows them, never a bare list: the lookahead row used to be rendered as well as read,
and the Blade then took the cursor from it against a page count spelled
out as a literal, so an inbox holding an exact multiple of a page landed
on the empty state with no way back. `unreadCountForUser()` is the one
method that never touches the encryption codec or session — it counts
on the plaintext `read_at`/`dismissed_at` columns only, so the nav badge
works on a locked device; every other method decrypts
`title`/`body`/`trigger_type` via the codec (a pass-through no-op when
encryption is not enabled).

## UI surface

`/notifications` (`NotificationsPage`) is a single lifecycle dimension —
the `NotificationTab` cases, Unread / All / Dismissed, which the strip
and both empty-state lines are built from — deliberately not a second type-switch
surface like `/drift`'s; every action re-resolves the user from
`CurrentUser` rather than trusting a Livewire-payload id as an
authorization boundary. Its cursor is an opaque `(created_at, id)`
compound string (unlike `/drift`'s plain integer cursor), since the
sha256 primary key carries no insertion order.

### The row's three controls, and the rail that counts them

Each row is a flex wrapper holding an anchor and, as its **siblings**, the
marks for the actions the page implements. The three actions shipped
implemented, registered and covered by `->call()` tests with nothing in the
Blade calling any of them: the row was one `<a>` with no siblings, so a reader
could not mark a notification read, could not dismiss one, and could not undo a
dismissal — and the *Dismissed* tab filtered to a state the reader had no way
to produce. Only the demo seeder ever wrote a `read_at` or a `dismissed_at`.

- **Dismiss** (`✖️`) and **Restore** (`↩️`) are the two halves of one
  reversible pair; which one a row draws is read from its own `dismissed_at`,
  not from the active tab, so a dismissed row found through *All* offers the
  way back too. Dismiss carries no confirmation on purpose — the toast offers
  the Undo, and a reversible action takes none of the three question shapes.
- **Mark read** (`✅`) is drawn only while `read_at` is null, *in addition to*
  the anchor's `x-on:click="$wire.markRead()"`. Opening a notification is still
  what marks it read, but that call is fired at the same moment the browser
  begins a full page navigation — the anchor deliberately carries no
  `wire:navigate` — so it races the teardown of the document it was sent from.
  Nothing but the explicit mark lets a reader clear an inbox without opening
  every target and coming back.

All three then dispatch `NavBadgeEvents::REFRESH`, which `AppSidebar` listens
for. The rail is mounted by the layout, which a component update never
re-renders, so before that the unread badge could only fall on a page load. The
same seam is why this module's nav-badge composer counts per render rather than
memoising per boot: the drawer holds the app's single sidebar mount, so a memo
collapsed nothing and instead froze the count for exactly the second render the
recount is.

The Settings
"Notifications" section (`NotificationsSettingsSection`) is the ~9-control
preferences form plus a read-only "Other devices" panel, bounds-checking
every input before any write (mirroring the project's other settings
sections' defense-in-depth discipline) even though the query layer
re-validates as well.

## Related

- [Copy that follows the reader](reader-language-copy.md) — the copy
  spec stored in `params`, how a removed translation key degrades, and
  why money rides as a value rather than a formatted string.
