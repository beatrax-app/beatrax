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
arch('Modules\\Ledger\\Internal is only used inside Modules\\Ledger')
    ->expect('Modules\\Ledger\\Internal')
    ->toOnlyBeUsedIn('Modules\\Ledger');
```

They are the right tool when the rule is about *who imports what*. Two limits
matter. First, the walk only sees files that resolve to a class in a namespace:
a module's `Routes/web.php` is a set of closures, so the `Route` facade it uses
is invisible to the facade rule. Second, an import written as an inline
fully-qualified `\Modules\X\Internal\...` reference rather than a `use`
statement is not always visible.

**Filesystem greps** — an `it(...)` block that walks a directory with
`RecursiveDirectoryIterator` and matches a regular expression — cover the rest:
raw query-builder calls, string literals, column names inside an `update()`
payload, and the paths the static analyser is configured to skip.

The custom PHPStan rule `App\PhpStan\Rules\BoundaryRule` enforces the
import half of the same invariant at `phpstan analyse` time, one layer earlier.
The arch rules add the coverage it cannot reach: inline fully-qualified
`\Modules\<X>\Internal\...` references, and imports from paths excluded from
analysis (`Modules/*/Database/Seeders`, `Modules/*/Routes`).

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

**`Database/Migrations/` is excluded** from the sole-mutator rules. A migration
seeds initial rows and declares the schema itself, including the columns whose
later mutation the rule restricts. Migrations are anonymous classes, outside the
namespace-based rules entirely.

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

## The self-policing guard

`everyInternalNamespaceHasABoundaryRule` reads
`BoundaryArchTest.php`'s own source and asserts that every module directory with
a populated `Internal/` namespace appears as a top-level `expect()` target in it.

This exists because the hand-maintained list drifted once already: eleven modules
shipped an `Internal/` namespace with no boundary rule, so a cross-module reach
into their internals would have passed the build unnoticed. The guard turns
"every `Internal` namespace is guarded" from something a reviewer has to
remember into something the build proves.

Its needle is the exact single-quoted top-level target — `'Modules\X\Internal'`,
closing quote immediately after `Internal` — so a rule naming only a deeper
symbol (`'Modules\Ledger\Internal\Console\RederiveFingerprintsCommand'`) does not
falsely satisfy it.

## Where a rule's rationale lives

The failure message, not a comment. Each `expect(...)->toBe([], "…")` carries the
explanation the contributor who trips the rule needs, at the moment they trip it:
what the rule protects, what to do instead, and which file offended. A comment
above the test that repeats the failure message is redundant; a comment that
names something neither the test name nor the message says — a rejected
alternative, a magic number, a surprising exemption — is the one worth writing.

## Related

- [Module boundaries](../architecture/module-boundaries.md) — the rules these
  invariants enforce, and the `Public`/`Internal`/`Models` split behind them
- [Comment policy](00-index.md) — what belongs in a comment at all
