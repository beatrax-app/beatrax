# One class for a moment in time

A moment in time is a `Carbon\CarbonImmutable`. `Carbon\Carbon` and
`Illuminate\Support\Carbon` — the mutable pair — are not called, instantiated
or type-declared anywhere.

```php
// no
use Illuminate\Support\Carbon;
$now = Carbon::now()->toDateTimeString();
Carbon::setTestNow($frozen);

// yes
use Carbon\CarbonImmutable;
$now = CarbonImmutable::now()->toDateTimeString();
CarbonImmutable::setTestNow($frozen);
```

`AMomentInTimeIsAlwaysTheImmutableOneArchTest` enforces it over every PHP file
the repository walks, Blade templates included.

## Why

On the mutable class `$due->addDay()` moves the value and hands back the same
object, so the caller that passed `$due` in now holds a different date than it
did. On the immutable one the same line returns a new instance and `$due` is
untouched. Two classes with one method list and opposite answers to "did that
move what I was holding?" is a question no reader should have to ask per call
site — and the tree had already answered it 1,280 imports to 23.

`Illuminate\Support\Carbon` is a subclass of `Carbon\Carbon`, so it mutates
too. Both names are named in the rule, because banning only one leaves the
other as a working spelling of the same hazard.

## The two spellings of `setTestNow` are the same call

This is worth stating because 28 lines in the suite were written as if they
were not. `setTestNow()` is declared exactly once in Carbon's
`Carbon\Traits\Test`, and its whole body is:

```php
FactoryImmutable::getDefaultInstance()->setTestNow($testNow);
```

One method, one shared store. `Carbon::setTestNow($t)` and
`CarbonImmutable::setTestNow($t)` are indistinguishable at runtime. Ten
Onboarding tests nonetheless froze the clock twice and thawed it twice, on
consecutive lines, and those 28 lines were deleted rather than converted.

## What was measured

| Spelling | Files importing | Uses |
|---|---|---|
| `Carbon\CarbonImmutable` | 1,280 | 3,699 |
| `Carbon\Carbon` | 15 | — |
| `Illuminate\Support\Carbon` | 8 | — |
| mutable, both names | 23 | 65 |

Every one of the 65 was a static call — `::now()`, `::parse()`,
`::setTestNow()`. Not one site constructed a mutable date or type-declared
one, which is why the conversion could not silently drop a mutation: a
`$date->addDay();` whose result is discarded is a no-op under the immutable
class, and the codemod was written to refuse any file using a mutable Carbon
in any way other than a static call. It refused none, because there were none.

## A docblock is deliberately outside the rule

The guard reads code positions — a static call, a `new`, a type declaration —
and never a docblock. That is not an oversight.

`Modules/Core/Models/User.php` is the one model of 50 that does not cast
`created_at` and `updated_at`, so Eloquent really does hand back
`Illuminate\Support\Carbon` for them, and its `@property Carbon|null` lines are
true. A rule that forced those to say `CarbonImmutable` would be asking the
docblock to lie about the running code. The import that serves them stays for
the same reason.

The 35 models that *do* cast (`'created_at' => 'immutable_datetime'`) document
`CarbonImmutable`, correctly. Whether `User` should join them is a change to
what a runtime attribute returns rather than a change of spelling, so it is not
this convention's to make.
