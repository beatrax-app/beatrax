# `DriftAlerts` — what the savings card is allowed to cache

`SavingsInsightsQuery::forUser()` is cached because the suggestion it
builds costs a resolution fan-out per approved subscription — the
recurring series, its counterparty, that counterparty's support-corpus
entry, an FX rate per currency in play. The dashboard renders that card
on every load, so the fan-out is worth keeping.

What it caches is the part of that work that has no reader. **A cache
entry outlives the request that filled it, and the next reader may not
be reading in the same language.**

## The shape that was wrong

The entry used to be the finished `SavingsInsight` list, and a
`SavingsInsight` carries two things that follow the reader:

- `message` and `actionLabel`, built with `Lang::get()`
- the amount inside `message`, built with `Money::format()`, which
  renders in the **reader's** active locale
  ([money representation](../ledger/money-formatting.md))

The key was `savings-insights:<user id>` and the TTL is ten minutes; a
packaged install runs `CACHE_STORE=file`, so the entry survives
requests. One English page load therefore pinned the card to English —
and to English money — for every language the same user switched to
inside the window. What that looked like on a German dashboard was half
a translated card:

```
heading : Sparmöglichkeiten     (de, rendered fresh)
rows    : Gebruik je KPN nog? Het kost € 45,00/mnd.   (nl, from cache)
```

Dutch symbol placement included, where a German reader must read
`45,00 €`.

## Why the locale is not part of the key

Adding the reader's language to the cache key would fix the sentence and
leave the rest:

- `Money::format()` follows the reader's locale **and** the currency the
  amount is denominated in. A change of reporting currency, or an FX
  rate arriving, does not change the locale — so a locale-keyed entry
  still hands back an amount written against the old setting.
- It multiplies the entries by the number of shipped languages, per
  user, in a file store, to cache text that costs nothing to render.
- It is the shape this repo has already ruled out once. A stored
  notification keeps the key and the values, never the sentence, for
  exactly this reason ([copy that follows the
  reader](../notifications/reader-language-copy.md)).

## The shape now

The entry is a list of `InsightFacts` — kind, series, name, minor
amount, currency, action URL, counterparty slug. No sentence and no
formatted amount among them. `forUser()` maps that list through a
render step on every call, so the copy and the money are built in the
locale of the request that asks for them, and the cache still holds the
fan-out.

`SavingsInsightKind` owns the two translation keys each kind renders
(`messageKey()`, `actionKey()`) alongside the dismissal key it already
owned, so the three suggestion types differ in one place rather than in
three near-identical constructor calls.

The cache key carries a `facts` segment. It is not decoration: an
install upgrading mid-window would otherwise hand the new code an entry
holding serialised `SavingsInsight` objects, and the render step would
be passed the wrong type.

## Related

- [`DriftAlerts` architecture](architecture.md) — the query's place in
  the module.
- [`Notifications` — copy that follows the reader](../notifications/reader-language-copy.md)
  — the same rule, enforced on a stored row rather than a cache entry.
- [Money representation](../ledger/money-formatting.md) — why
  `format()` reads the ambient locale.
