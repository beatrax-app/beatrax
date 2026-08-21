# `Notifications` — copy that follows the reader

A notification is written once and read for up to a year, and the
reader may not be reading in the language it was written in. Nothing
about a notification is allowed to freeze in the writer's locale, so a
row does not store its sentence — it stores the **key** the sentence
comes from plus the **values** that go into it, and the sentence is
built again on every read.

This page is the contract that machinery works to: what rides in the
row, what happens when a key goes away, and why money is a value here
rather than a string.

## Where the copy lives

The spec rides inside `notifications.params`, under the `copy` key.
That column is already in `SensitiveFieldRegistry::columns()` and
already syncs between devices, so re-renderable copy needed no new
column and no new sync rule.

Three classes carry it, all in
`Modules/Notifications/Internal/Support/`:

| Class | What it is |
|---|---|
| `NotificationCopySpec` | One title line plus a list of body lines |
| `CopyLine` | A translation key, its replacement values, and a plural count |
| `CopyParam` | A replacement whose *own* rendering depends on the language |

`CopyLine::plural()` keeps the count rather than the chosen form,
because a language with more plural categories than the writer's would
otherwise be stuck with the writer's pick.

`CopyParam` covers the values a plain string cannot: a weekday name, a
short date, a nested translation key, and money. Everything else — a
category name, a merchant name, a user-authored message — is the same
text in every language and rides verbatim.

## Money is a value, not a string

`Money::format()` renders in the **reader's** locale
([money representation](../ledger/money-formatting.md)). It used to be
anchored to the currency instead, which is why every listener once
formatted its amounts up front and froze the resulting string into the
spec. That is now wrong in a way that shows: a weekly digest fires from
a scheduled job, a job has no request locale, so the amounts were
rendered against the app default and a Dutch reader was handed
`€1,234.56` forever.

There is no way to have it both ways — a value rendered at write time
cannot follow a later language change. So money rides as
`CopyParam::money($minor, $currencyCode)`: the integer and the currency
code, stored as `"<minor>|<CUR>"`, formatted at read time through
`Money::tryOfMinor(...)->format()`.

Two consequences worth knowing:

- **No listener may call `->format()` on its way into a spec.** If an
  amount is going into a notification, it goes in as
  `CopyParam::money()`. There are four such listeners today
  (`PersistBudgetNudge`, `PersistPaymentReminder`, `PersistSavingsPrompt`,
  `PersistPositionDigest`).
- **`PersistDriftAlert` is the one exception, and it is a deliberate
  one to revisit.** It renders through `MoneyInput::formatAbsMinor()`,
  which is fixed Dutch grouping with the currency code carried as a
  separate `:currency` placeholder in the copy line. Making it follow
  the reader means dropping that placeholder from the line in all
  shipped locales, which is a copy change rather than a code change.

## When a key no longer exists

`Lang::get()` deliberately answers a missing key with the key itself,
so a typo stays visible on screen. That is right for a template and
wrong for a stored notification: rename
`notifications::copy.title.budget_nudge` in a later release and every
un-dismissed row written before the rename would render the literal
string `notifications::copy.title.budget_nudge` at the reader, for up
to the full retention window.

So the resolution is layered:

- `CopyLine::render()` returns **`null`** when the key resolves to
  itself.
- `NotificationCopySpec::title()` / `body()` propagate that null.
  `body()` is all-or-nothing: half a body in the reader's language and
  half missing reads worse than the whole stored sentence.
- `NotificationQuery::hydrate()` falls back to the row's own `title` /
  `body` columns. Those are stale — they are in the language the row
  was written in — but they are a sentence, which a raw key is not.

The same fallback covers rows written before the copy spec existed at
all; they have no `copy` key in `params` and there is nothing to
re-render.

## Why the columns still hold a rendered sentence

`NotificationDraft::fromCopy()` writes `title` and `body` as well as the
spec, via `NotificationCopySpec::storedTitle()` / `storedBody()`. Two
readers need them: the OS push, which fires once and cannot be
re-rendered afterwards, and a device still on an older release that
does not know how to read the spec.

`storedTitle()` falls back to the raw key when the key does not
resolve. That is the write-time case, where a missing key is a defect
in the release being written — printing it is how it gets found, and
there is no earlier sentence to fall back to.

## Which locale the write happens in

`NotificationCopyRenderer::forUser()` switches the translator **and**
Carbon to the recipient's stored locale for the duration of the build,
then restores both. Every listener builds its draft inside that
closure. Carbon has to be switched separately because it carries its
own locale, and a job-built notification's dates would otherwise not
match its sentence.

## Compatibility across versions

`CopyParam::fromArray()` validates the money shape and returns `null`
for a kind it does not know. That failure propagates:
`CopyLine::fromArray()` → `NotificationCopySpec::fromArray()` → a null
spec at `NotificationQuery`, which then reads the stored columns. So an
older release handed a row carrying a `money` param degrades to the
written sentence rather than to a blank or a crash.

Existing rows whose money is still a frozen plain string keep working
untouched — a plain string is a legal replacement value and always was.

## Related

- [`Notifications` architecture](architecture.md) — dedup, delivery
  suppression, the state machine, and retention.
- [Money representation](../ledger/money-formatting.md) — why
  `format()` reads the ambient locale and what the ICU-less path does.
- [Sensitive columns at rest](../sync/sensitive-columns-at-rest.md) —
  what encrypts `params` and what a failed decrypt looks like.
