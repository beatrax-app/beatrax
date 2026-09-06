# Writing an arch invariant

An arch invariant is a test that fails when the *shape* of the codebase drifts,
rather than when a behaviour is wrong. A repo-wide one lives in
`tests/Contracts/`; a rule that only ever reads one module's own tree may live
beside it, in that module's `tests/Contracts/` or `tests/Arch/`. The largest
collection is `tests/Contracts/BoundaryArchTest.php`, which holds the
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
    'Modules/Community/Public/Actions/OpenExternalUrlAction.php',
    'Modules/Community/Internal/Shell/NoOpShell.php',
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

Do not write one boundary rule per module. A hand-maintained list of modules
drifts: a module added tomorrow either appears in it or does not, and the
version of that list this repository once carried had already let eleven
modules ship an `Internal/` namespace with no rule covering it. A guard that
needs a second guard to check its own coverage is a guard that was enumerated
one module at a time, and the enumeration is the defect.

`pinnedCrossModuleInternalImports` walks the tree instead, so a new module is
in scope the moment its first file exists and there is no coverage list for
anything to police.

What *is* pinned is the far smaller set of accepted crossings, and that list is
compared with `toBe()` in both directions: a crossing that disappears fails the
test as loudly as one that appears, so the pin cannot rot into a stale
allow-list. Pin outcomes, not coverage.

### A scanner accounts for the whole tree

A guard narrower than the claim it makes passes, and it passes because it never
looked. Three shipped instances wore three different mechanisms: a hand-written
root list that opened five directories of fourteen, a single-root walk that made
`app/` structurally invisible while a command raw-deleted from two travelling
tables inside it, and a whole-file substring exemption that hid seventy-one
files from the encryption guard — including the file where a plaintext IBAN
leak lived.

One shape underneath: a scanner's declared scope stopped describing the tree,
and nothing was watching the difference. `Tests\Contracts\Support\RepoTree` is
the one place a scope is declared, and each names the roots it `covers`, the
roots it `declines` with the reason somebody else reads them, and the path
fragments it `skips`. `AScannerAccountsForTheWholeTreeArchTest` holds every
declaration to the tree git actually holds: a root holding first-party files of
that kind and named in neither list fails, a `declines` entry for a root that
holds nothing of the kind fails as an exemption excusing nothing, and a covered
root the walk reached no file in fails as a scan that stopped reading.

`NEVER_WALKED` is the other half — code this repository did not write, sitting
inside it. Vendored trees are the obvious members; the generated ones are the
quieter half, because the accounting above reads its roots out of `git ls-files`
and cannot see a file git never held. A generated `bootstrap/cache/modules.php`
naming every service provider in the application sat under a root every scope
covers, and the cycle guard read it as one module citing another — an edge
nobody wrote. Each fragment carries its reason, and the guard puts a path under
every one of them to `RepoTree::refuses()` rather than asking whether the
directory happens to hold a file today, which answers about the build machine.

A guard that needs its own root list says so in
`SCANNERS_NAMING_THEIR_OWN_ROOTS`, with the reason the narrowness is deliberate
and a `proves` pattern re-checked against the file.

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

This is the house style for a new pinned exemption, and many of the guards in
`tests/Contracts/` already pin this way — `TheFourAmountColumnsMoveAsASetArchTest`,
`AColumnAScreenReadsBackHoldsNoSentenceArchTest` and
`ABladeNeverSpeaksEnglishOfItsOwnArchTest` among them. Each pairs it with a
companion case, *still holds each pinned exemption to the reason it was granted
for*, so a pin fails in both directions.

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
green rather than a bug. A guard is not only a file under `tests/Contracts/`, so
it tokenises `tests/Contracts/`, `tests/Helpers/`, `tests/Support/` and both
`Modules/*/tests/Arch/` and `Modules/*/tests/Contracts/` — the last two of those
held thirteen guards the walk had never opened, and `tests/Helpers/CssRule.php`
held the exact shape the rule bars, a `(string) preg_replace_callback(…)` that
blanks a whole stylesheet on a give-up and leaves five CSS guards reporting a
clean sheet. `Modules/*/tests/{Feature,Unit}` stays out on evidence rather than
by omission: twenty-seven call sites there would fail these rules, and a
behaviour test is not a guard. It fails on a direct `preg_match_all` call in any
form — including the fully-qualified `\preg_match_all(`, which PHP hands back as
one `T_NAME_FULLY_QUALIFIED` token a reader keyed on `T_STRING` cannot see — on
any PCRE call whose answer is discarded, and on any whose answer is turned
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

## A declaration no autoloader reaches

Composer builds its classmap by psr-4 rule: a class is reachable because the
file it sits in is named after it, under the directory its namespace maps to.
A class declared at the top level of a `*Test.php` satisfies neither half — the
file is named for the test — so Composer prints a warning and **skips** it. The
class then exists only while the one file that declares it is loaded, which
makes three things true at once. A second file naming it fatals. Two files
declaring the same name in one shard fatal the whole parallel run rather than
one test. And `composer install` prints a warning per site until nobody reads
warnings at all.

The tree had 45 of them, and the second hazard was already being managed by
hand: seven stub adapters in one module carried the initials of their own test
file as a prefix — `Acoa`, `Afn`, `Sncr`, `Soja`, `Ofs`, `Oms`, `Atws` — and
4,284 free helper functions across 1,668 test files carry the same dodge. A
naming convention nobody can enforce was standing in for a namespace.

The shape a double takes instead is the one several modules already used:

```text
Modules/<Module>/tests/Support/<ClassName>.php
namespace Modules\<Module>\Tests\Support;
```

imported with a compound `use`. A **non-compound** global `use` in a
namespace-less test file aborts the whole parallel run, and a `namespace`
declaration must never be added to a Pest file — Pest resolves it by path.
`Support/` is safe to add because no `<testsuite>` collects a file that does not
end in `Test.php`.

`ATestDoubleTheAutoloaderCannotFindArchTest` reads both halves off one walk: no
`*Test.php` declares a top-level type, and nothing under a declared psr-4
directory declares a name that directory cannot resolve — which is Composer's
own check, restated where the suite can fail on it. It reads with the tokeniser
and not with a pattern, because around thirty arch tests in this tree plant a
violation by writing a class into a heredoc and scanning the string, and to a
regex that body is indistinguishable from a declaration. The lexer sees a
heredoc as one string token and never as a `class` keyword.

The exemptions are pinned with their reasons and re-checked against the walk, so
a pin whose site has moved fails as loudly as a new violation. The four
`app/PhpStan/Rules/Fixtures/` files are the durable ones: the custom
`BoundaryRule` fires on a class whose namespace is a module `Internal`, so a
fixture naming its own directory would not be a subject of the rule at all — and
an autoloadable class in that `Internal\Examples` namespace would be real code
inside a module's private namespace, which every boundary reader would then
judge as shipped. Being unreachable is the point.

## The name is part of the interface

A named invariant carries its name in the `it(...)` description, in parentheses
at the end: a verdict word — `no`, `pinned`, `every`, `only`, `one`, `cross` —
then what it forbids or pins, in camel case. `noOtherCardStatementStateMutator`,
`pinnedCrossModuleInternalImports`, `everyDevModeRouteAppliesEnsureDeveloperModeMiddleware`.
The name is how a page, a failure message and a commit refer to a rule without
quoting it, so it is an interface and renaming it is a rename everywhere.

That matters because a page saying "the `noXxx` invariant forbids this" makes a
promise about the build. A reviewer reads the sentence, trusts the suite, and
approves — and nothing fails, because the name was never in the tree or was
renamed out from under the page. Both directions cost something: a name that
never existed is a protection somebody believes is there, and a name that was
renamed reads as a guard someone deleted, which is how a second copy of it comes
to be written.

`ADocNamesOnlySymbolsThatExistArchTest` reads both shapes out of every page this
repository hands a reader. A backticked identifier ending in `Test` has to name
a file under `tests/` or `Modules/*/tests/`; a backticked invariant name has to
appear somewhere in that same suite. It holds the citations, not the sentences
around them — the prose is not checkable and the guard does not pretend
otherwise. Renaming a rule is therefore a change to the test and to every page
that cites it, in one commit.

Which identifiers count as invariant names is read off the suite rather than
written down. The first version matched `no…`, `pinned…` and `every…`, and could
not see `crossModuleRawTableWrites` — the most-cited rule in the tree — or
`onlyOneSuppressionEvaluator`, or `oneWindowDefinition`. A guard keyed on a list
of prefixes cannot see a rival answering to a different one, so the prefixes come
from the names the suite declares, and a case asserts the derived set still
covers every one of them.

When nothing enforces a claim, a page saying so plainly costs a reader nothing.
A page claiming a gate that is not there costs them the review.

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
