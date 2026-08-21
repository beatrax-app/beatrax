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
short date, a nested translation key, money, and a category name.
Everything else — a merchant name, a user-authored message — is the
user's own words, the same text in every language, and rides verbatim.

A **category** is not the user's own words unless they renamed it: a
default category stores canonical English and the screen shows the
slug's translation ([category display names](../ledger/category-display-names.md)).
So it rides as `CopyParam::category($storedName, $slug, $nameIsDefault)`
— all three, resolved through `CategoryDisplayName::resolve()` at read
time. `BudgetThresholdCrossed` carries the slug and the flag for exactly
this reason; the emitting job is hourly and has no reader.

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
  `CopyParam::money()`. Five listeners carry money today:
  `PersistBudgetNudge`, `PersistPaymentReminder`, `PersistSavingsPrompt`,
  `PersistPositionDigest` and `PersistDriftAlert`.
- **`PersistDriftAlert` was the exception and is not one any more.** It
  rendered through `MoneyInput::formatAbsMinor()` — fixed Dutch
  grouping, with the currency code as a separate `:currency`
  placeholder. The two placeholders were merged into one `:amount`
  across all shipped locales, and the listener passes
  `CopyParam::money(abs($deltaMinor), $currency)`: absolute, because
  the direction word already carries the sign.

## A category name is a value too

The same trap, one field over. `categories.name` holds canonical
English for a row nobody has renamed and the user's own words for a row
they have, and
[`CategoryDisplayName`](../ledger/category-display-names.md) is the one
place that decides which. A budget nudge is emitted by
`EmitBudgetNudgesJob`, an hourly `Schedule::call` with no request behind
it, so everything it resolves is resolved in `config('app.locale')`.
That put **"Je hebt 80% van je budget voor Groceries gebruikt"** in
front of a Dutch reader whose screen says `Boodschappen` everywhere
else.

So the name rides as `CopyParam::category($storedName, $slug,
$nameIsDefault)`, stored as `"<0|1>|<slug>|<stored name>"` — the name
last, so one containing the separator still decodes — and resolved at
read time through `CategoryDisplayName::resolve()`. Going through that
seam rather than doing `Lang::get('categorization::categories.'.$slug)`
inline is what keeps the fallback: a slug with no wording in the
reader's language renders the stored English, never a raw translation
key in a notification body.

The provenance has to travel to get there.
`BudgetProgressQuery::expenseCategoryNaming()` returns the resolved
name alongside its slug and flag, `EnvelopeRow` and
`BudgetThresholdCrossed` carry all three, and `PersistBudgetNudge`
hands them to `CopyParam`. The resolved name is what goes in as the
fallback string, which is equivalent: a renamed row resolves to itself,
and an untouched default re-resolves from its slug.

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

`CopyParam::fromArray()` validates the money and category shapes and
returns `null` for a kind it does not know. That failure propagates:
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
