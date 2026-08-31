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

`NotificationCopySpec` — one title line plus a list of body lines — stays in
`Modules/Notifications/Internal/Support/`. The two pieces it is built from are
not notification-shaped and now live in `Modules/Core/Public/Support/`, because
a `display_label` column is the same problem one table over:

| Class | What it is |
|---|---|
| `CopyLine` | A translation key, its replacement values, and a plural count |
| `CopyParam` | A replacement whose *own* rendering depends on the language |
| `StoredCopy` | The same spec packed into a single string column, for a table with no `params` to put it in |

`CopyLine::plural()` keeps the count rather than the chosen form,
because a language with more plural categories than the writer's would
otherwise be stuck with the writer's pick.

`CopyParam` covers the values a plain string cannot: a weekday name, a
short date, a date carrying its year, a nested translation key, money, and a
category name.
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

Three consequences worth knowing:

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
- **The currency code beside it is the *owner's*, not the ambient
  reader's.** `BaseCurrency::code()` answers for whoever the guard
  carries and falls through to `config('currency.base')` in a queued
  context — and every listener here runs in one. An amount that does not
  come out of a `Money` the event already carries is labelled through
  `BaseCurrency::forUser($owner)`, the owner loaded from the event's
  `userId` — the same rule the
  [envelope writers](../budgets/architecture.md) follow.

### Amounts that are not all in one currency

A digest's "over budget" figure sums envelopes, and
`envelope_assignments.currency` is stamped per row: one period really can
hold a EUR envelope beside a USD one. `PersistPositionDigest` therefore
buckets the over-budget envelopes by their own currency and folds the
buckets through `CrossCurrencyTotal::of()` into the owner's currency,
rather than adding minor units that are not the same unit and printing
the total under whichever code the last row carried.

A fold can leave a bucket out for want of a rate, and a smaller number
with nothing saying so reads as less overspend rather than a partial
figure. So the codes it could not price get their own body line, through
the shared `core::money.not_converted` that every other roll-up names its
gaps with. A `CopyLine` key reaches `Lang::get()` untouched, so it may
name **any** module's translation namespace — a Notifications copy of a
sentence Core already ships in every locale would be a second thing to
keep in parity for no gain.

## A category name is a value too

The same trap, one field over. `categories.name` holds canonical
English for a row nobody has renamed and the user's own words for a row
they have, and
[`CategoryDisplayName`](../ledger/category-display-names.md) is the one
place that decides which. A budget nudge is emitted by
`EmitBudgetNudgesJob`, dispatched by the hourly `budgets:emit-nudges`
command with no request behind it, so everything it resolves is resolved
in `config('app.locale')`.
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

## A sentence another module already rendered is not a value either

`PersistSavingsPrompt` was the one listener that did not follow the rule above.
Its body line was `notifications::copy.body.savings_prompt`, whose whole English
value was `':message'`, and `:message` arrived as a plain string —
`SavingsInsightsQuery::render()` had already resolved one of three
`drift-alerts::savings.insight.*_message` lines and formatted the monthly amount
into it. That query runs inside `EmitSavingsPromptsJob`, an hourly job with no
reader, so the sentence froze in the worker's language and the amount froze
under the worker's grouping marks.

A plain string is a legal replacement value and always was — that is what a
merchant name is. What made this one wrong is that it was **copy**, and copy
belongs to a key. `SavingsPromptDue` now carries `messageKey` instead of
`message`, and the listener makes the insight's own line the body:

```php
CopyLine::of($event->messageKey, [
    'name' => $event->name,
    'monthly' => CopyParam::money($event->monthlyMinor, $event->currency),
]);
```

The `':message'` wrapper key is gone from all twenty-six locales — a frame whose
entire content is one placeholder was never translating anything.

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
  half missing reads worse than the whole stored sentence. It reaches
  that decision through `AllOrNothing::map()`, below.
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
Carbon to the recipient's language for the duration of the build, then
restores both. Every listener builds its draft inside that closure.

The translator is the target on purpose. Everywhere else a language
change goes through `LocaleNegotiator::apply()`, which retargets the
whole application — and Laravel relays that to Carbon for free, because
`Carbon\Laravel\ServiceProvider` listens for `LocaleUpdated`. This is a
job rendering for someone who is not the reader of the current request,
so it must not leave `config('app.locale')` pointing at a recipient for
whatever runs next. Swapping the translator alone is the narrow move —
and nothing relays a translator swap to Carbon, which is why the dates
are moved and put back by hand alongside it.

`localeFor()` reads `users.locale` and then goes through
`LocaleNegotiator::resolve()`, the same filter every other locale
decision applies. It matters because the column can hold a code this
release does not ship — a row merged from a device on a newer version, a
restored backup, a language dropped since it was chosen. Handed straight
to Carbon such a code is a **silent no-op**: `setLocale()` on an unknown
language leaves the previous one in place, so the dates would have kept
whatever language the caller was already reading in while the sentence
around them fell back to English. Through `resolve()` the whole
notification reads in English, the same answer the rest of the app gives
that reader.

## Compatibility across versions

`CopyParam::fromArray()` validates the money and category shapes and
returns `null` for a kind it does not know. That failure propagates:
`CopyLine::fromArray()` → `NotificationCopySpec::fromArray()` → a null
spec at `NotificationQuery`, which then reads the stored columns. So an
older release handed a row carrying a `money` param degrades to the
written sentence rather than to a blank or a crash.

Existing rows whose money is still a frozen plain string keep working
untouched — a plain string is a legal replacement value and always was.

`AllOrNothing::map()` is the single place that rule is spelled. It folds
a collection through a decoder and answers `null` the moment one element
does, which is what makes a bad replacement value fail its whole
`CopyLine`, a bad `CopyLine` fail its whole spec, and an unrenderable
body line fail its whole body. It is one rule in both directions: a spec
with a hole in it is worse than no spec at all, because the fallback for
no spec is a whole written sentence and the fallback for a hole is
nothing. Anything else that decodes or renders part of this spec goes
through it rather than writing the fold again.

## The row's own words, beside the stored ones

Two strings on a notification row do not come from the spec at all — the type
chip and the dead-link line — and both were frozen in English until a Dutch
phone showed them beside correctly translated titles.

`NotificationCopy::TYPE_CHIPS` maps a trigger to a **glyph and a lang key**,
never a word. `typeChip()` resolves the key through `Lang::get()` on every
call, so the chip follows whoever is reading rather than whoever read first.
The chip is `aria-hidden` because it repeats what the title already says — that
hides it from a screen reader and not from eyes, so it is user-facing text like
any other. A trigger this build cannot name, and the empty string
`SensitiveColumnCodec` leaves behind for a column it could not open, both fall
to `TYPE_CHIP_UNNAMED`, whose key ships in all twenty-six locales like the rest;
the fallback degrades to a neutral word, never to a raw key.

`notifications::row.dead_link` is **five whole sentences keyed by target kind**,
not one sentence with a `:kind` placeholder. A noun dropped into a sentence has
to agree with it, and nine locales inflect the demonstrative — Dutch would read
"Deze budget" — so the sentence is the unit of translation here. The key is
built on `NotificationDto::targetKind`, which is why
`DeepLinkResolver::renderedKind()` folds a kind this build does not know into
`item` before it gets that far.

## The same spec in a column that has no `params`

`migration_staging_unmapped_items.display_label` and `.reason`, and
`pot_movements.memo`, are single string columns with no JSON blob beside them.
`StoredCopy::of(CopyLine)` packs a spec into one, `StoredCopy::read()` unpacks
it, and anything that is **not** a spec comes back verbatim — which is what
keeps a memo the user typed, and every row written before the seam existed,
rendering exactly as it did.

The envelope carries the sentence as it read at write time under `@said`, for
the same reason the notification row keeps its `title` and `body` columns: a key
renamed in a later release has to degrade to a stale sentence, never to a raw
key. `StoredCopy::keyOf()` and `::names()` answer which line a stored value
names without rendering it, so a query or a test can narrow to the rows the app
itself wrote without any caller learning the envelope's shape.

### Packed in, or riding beside

Packing the envelope into the column is only safe where **an older build would
not have rendered it**. `system_alerts` and `pot_movements` are synced, and
[a peer may be on a newer version](../sync/a-peer-may-be-on-a-newer-version.md)
makes that a standing requirement: a build with no `StoredCopy` echoes the
column, so an envelope arriving from a newer device is raw JSON at a reader who
did nothing wrong.

So there are two shapes, and the column decides which:

| Where the spec goes | When | Read with |
|---|---|---|
| Packed into the column | Nothing older renders it — a staging table, a column no earlier release had | `StoredCopy::read($stored)` |
| Beside the sentence, in a JSON column | The column is synced and a screen echoes it | `StoredCopy::readFromParams($params, $written)` |

`system_alerts` takes the second: `StoredCopy::inParams($line)` writes the spec
under `metadata.copy`, `message` keeps the rendered sentence, and an older peer
renders exactly what it always did. `pot_movements` had no JSON column beside
`memo` and could not grow one — an op naming a column the peer's schema lacks is
quarantined whole — so its release movement stopped being a sentence and became
`PotMovementKind::ReleasedOnArchive`, named from the lang file by the same
`match` that names a fund and a transfer.

## Related

- [`Notifications` architecture](architecture.md) — dedup, delivery
  suppression, the state machine, and retention.
- [Money representation](../ledger/money-formatting.md) — why
  `format()` reads the ambient locale and what the ICU-less path does.
- [Sensitive columns at rest](../sync/sensitive-columns-at-rest.md) —
  what encrypts `params` and what a failed decrypt looks like.
