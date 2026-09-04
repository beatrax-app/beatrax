# Writing an arch invariant

An arch invariant is a test that fails when the *shape* of the codebase drifts,
rather than when a behaviour is wrong. They live in `tests/Contracts/`, and the
largest collection is `tests/Contracts/BoundaryArchTest.php`, which holds the
module-boundary rules described in
[Module boundaries](../architecture/module-boundaries.md).

The rules themselves are documented there. This page describes the *mechanics*
every invariant in that file shares, so an individual test does not have to
re-explain them and a contributor adding one knows the house style.

## Two enforcement styles, and why both exist

**`arch(...)` rules** come from `pest-plugin-arch`. They classify PHP files by
the namespace they declare and assert on the import graph:

```php
arch('DriftEvaluator is never imported by Modules\\DriftAlerts\\Internal\\Http')
    ->expect('Modules\\DriftAlerts\\Internal\\DriftEvaluator')
    ->not->toBeUsedIn(['Modules\\DriftAlerts\\Internal\\Http', 'Modules\\DriftAlerts\\Resources']);
```

They are the right tool for a *named symbol* whose importers all declare
classes. They are the wrong tool for a whole-tree rule, and this is not a
matter of degree — read `ObjectDescriptionBase::make()`: a file is dropped from
the graph unless it declares a class, trait, interface or enum with a resolvable
`namespacedName` **and** that name autoloads. Three large categories of this
repository fail that test: functional Pest files (which declare nothing), module
migrations (`return new class extends Migration`, anonymous), and helper classes
declared inside a Pest file (global namespace). A rule of the
`->toOnlyBeUsedIn('Modules\X')` shape therefore passes while those files import
`Modules\X\Internal\` freely, and reads as a guarantee it never gave. Thirty-four
such rules were deleted for that reason; `pinnedCrossModuleInternalImports`
replaced them with a textual scan.

**Filesystem greps** — an `it(...)` block that walks a directory with
`RecursiveDirectoryIterator` and matches a regular expression — cover the rest:
raw query-builder calls, string literals, column names inside an `update()`
payload, cross-module imports, Blade Livewire mounts, and the paths the static
analyser is configured to skip.

The custom PHPStan rule `App\PhpStan\Rules\BoundaryRule` enforces the
import half of the same invariant at `phpstan analyse` time, one layer earlier
and, on the paths it does analyse, more strictly: its allow-list is `Public` and
`Models` only, so a cross-module `Database\`, `Providers\`, `Routes\`, `Http\`
or `Commands\` import fails it too. What it cannot reach is inline
fully-qualified `\Modules\<X>\Internal\...` references (it hooks `UseItem`
nodes), every path in `phpstan.neon`'s `excludePaths` (migrations, seeders,
routes, all tests), and any file outside `Modules\` — the rule returns early
when it cannot resolve an importing module.

## The shared conventions of a grep-style invariant

Every grep-style invariant in `BoundaryArchTest.php` follows the same five
conventions. None of them are restated at the individual tests.

**Comments are stripped before matching.**

```php
$stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
```

Without this, the codebase's own explanatory prose trips the rules it is
describing: a `@see` tag naming `Auth::user()`, or an event docblock naming the
listener that consumes it, would be indistinguishable from a real call site.
Blade comments (`{{-- … --}}`) are stripped too wherever `.blade.php` files are
in scope.

**`tests/` is excluded.** These are production-code rules. Test factories
populate `transactions` rows directly, harnesses call `actingAs()`, and doubles
name the forbidden symbols on purpose — all legitimate, none of it shipped.
`pinnedCrossModuleInternalImports` is the exception: it scans tests too, into a
second pinned list, because a test reaching into a neighbour's `Internal\` welds
itself to a private shape that neighbour is entitled to change.

**`Database/Migrations/` is excluded** from the sole-mutator rules. A migration
seeds initial rows and declares the schema itself, including the columns whose
later mutation the rule restricts. Being anonymous classes, migrations are also
invisible to every namespace-based rule — which is why the boundary invariants
that must cover them are greps.

**A missing directory satisfies the rule.** Several invariants open with:

```php
if (! is_dir($someDir)) {
    expect(true)->toBeTrue();

    return;
}
```

The invariant is written before, or independently of, the subtree it guards, and
must not fail merely because that subtree is absent. It binds as soon as the
directory exists.

**`(?<![>:])` excludes the method-call shape.** A bare-function ban such as
`base_path(` or `session(` would otherwise also match `$obj->base_path(...)` and
`SomeClass::session(...)`, which are unrelated methods that merely share a name.
The negative lookbehind is what keeps the ban to the global-function form.

## Allow-lists

Where a rule has exceptions, they are an explicit array of relative paths or
fully-qualified class names — never a glob, never a directory prefix:

```php
$allowList = [
    'Modules/Auth/Public/Actions/LoginAction.php',
    'Modules/Auth/Internal/Fortify/Authenticator.php',
];
```

A glob would silently absorb a future file. A per-file list means adding an
entry is a visible diff that a reviewer has to agree with, which is the whole
point of the exception being reviewable. Several allow-lists are mirrored by an
`ignoreErrors` entry in `phpstan.neon` covering the same files for the
equivalent static-analysis rule; the two lists are kept in step by hand.

`pinnedDesktopFacadeAllowList` goes one step further and pins two of those lists
to a literal expected set, so the lists cannot grow at all without the test
being edited in the same commit.

## Prefer an exhaustive scan to a per-module list

`BoundaryArchTest.php` used to carry one boundary rule per module plus a
meta-test, `everyInternalNamespaceHasABoundaryRule`, that read the file's own
source and failed when a module with a populated `Internal/` had no rule. The
meta-test existed because the hand-maintained list had already drifted once:
eleven modules shipped an `Internal/` namespace with no rule at all.

Both are gone. A guard that needs a second guard to check it has been enumerated
one module at a time, and enumeration is the defect — a module added tomorrow
either appears in the list or does not. `pinnedCrossModuleInternalImports` walks
the tree instead, so a new module is in scope the moment its first file exists,
and there is nothing for a meta-test to police.

What *is* pinned is the far smaller set of accepted crossings, and that list is
compared with `toBe()` in both directions: a crossing that disappears fails the
test as loudly as one that appears, so the pin cannot rot into a stale
allow-list. Pin outcomes, not coverage.

### A pin states its reason, and the reason is re-checked

A pinned exemption that carries only a path is a claim nobody can audit: the
reader has to go and work out why it was granted, and nothing notices when the
answer stops being true. The newer guards pin an entry rather than a path —

```php
'Modules/Ledger/Models/Transaction.php' => [
    'reason' => 'the Eloquent cast map: it declares the columns types, and writes none of them',
    'proves' => '/function casts\(\)/',
],
```

— and a second rule re-runs every `proves` pattern against the file it names.
The reason is prose for the reader; the pattern is the half a test can hold.
When the file stops matching, the exemption has outlived what earned it and the
guard fails there, naming the reason it no longer reads as, rather than waving
the site on for another year.

Three rules pin this way today: `TheFourAmountColumnsMoveAsASetArchTest`,
`AColumnAScreenReadsBackHoldsNoSentenceArchTest` and
`ABladeNeverSpeaksEnglishOfItsOwnArchTest`. Each pairs it with the
disappearing-pin test above, so a pin fails in both directions.

### A walk that stops reading must say so

`preg_match_all` answers `false` when the engine gives up — a backtrack limit, a
JIT stack limit on a long template — and every rule that reads its result as a
count reads that `false` as "nothing matched". A guard which stops reading then
reports a clean tree, which is worse than no guard: it is a green light nobody
earned.

`Modules\Core\Public\Support\PatternScan` is the one home for the reading, for
guards and for production alike, and it raises `PatternScanFailedException`
naming the pattern and `preg_last_error_msg()` rather than handing back an empty
answer. The result shape is chosen by which method you call rather than by a
flags argument — `all()` and `sets()` for pattern and set order,
`allWithOffsets()` and `setsWithOffsets()` for the same two with offsets,
`count()` for a tally, `first()` and `matches()` for a single-shot read — because
the flags are what decide the shape, and naming it is what lets a caller be told
the type of what it reads. `ARegexThatNeverRanIsNotNoMatchArchTest` holds the
whole tree to it, accepting `=== 1` and `=== false` beside the seam because both
spellings separate a give-up from an empty answer.

The replacers are the quieter half of the same defect. A stripper that gives up
answers `null`, and `(string) preg_replace(…)` turns that into an **empty
subject** for the scan below it, which then finds nothing and calls the file
clean — with no count to look wrong. `PatternScan::replace()` and
`PatternScan::replaceCallback()` throw instead. `preg_replace(…) ?? $source` is
left alone deliberately: it degrades to scanning the unstripped text, which
biases toward a false positive somebody investigates rather than a silent green.

`AStoppedScanIsNeverReadAsAnEmptyOneArchTest` holds the guard tree itself to a
stricter rule than the tree-wide one, because a wrong answer here is a false
green rather than a bug. It tokenises every file under `tests/Contracts/` and
`Modules/*/tests/Arch/` and fails on a direct `preg_match_all` call in any form,
on any PCRE call whose answer is discarded, and on any whose answer is turned
into an empty subject by a `(string)` cast or a `?? ''`. `preg_match_all` is
barred outright rather than merely checked because its backtracking accumulates
across a whole subject, so a file that grows crosses the limit — that is the
failure this tree has actually shipped, and `=== false` then `continue` is the
shape it shipped as. The single-shot `preg_match` reads left raw stop at the
first hit and are held only to the two shared rules. It reads with the tokeniser
rather than a pattern of its own, because a regex scan of the regex scanners
would be the very thing it guards against.

It asserts both denominators — the files walked and the calls found — before any
verdict is read. That rule earns its place: narrowing the walk to a single
directory leaves the other three rules **green** over sixteen files, which is
precisely the failure the whole section is about. Beyond it, every walk asserts a
floor on what it scanned — files, echoes, payload keys — so a scan that ran over
nothing fails on that assertion rather than on the offender list it never built.

## Where a rule's rationale lives

The failure message, not a comment. Each `expect(...)->toBe([], "…")` carries the
explanation the contributor who trips the rule needs, at the moment they trip it:
what the rule protects, what to do instead, and which file offended. A comment
above the test that repeats the failure message is redundant; a comment that
names something neither the test name nor the message says — a rejected
alternative, a magic number, a surprising exemption — is the one worth writing.

## Related

- [Invariants written after a shipped failure](invariants-from-shipped-failures.md)
  — the field history behind the rules in `tests/Contracts/`: what each one cost
  and why nothing else caught it
- [Module boundaries](../architecture/module-boundaries.md) — the rules these
  invariants enforce, and the `Public`/`Internal`/`Models` split behind them
- [Comment policy](00-index.md) — what belongs in a comment at all
