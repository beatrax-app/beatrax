# ADR 0002 — Dependency injection only; no facades or global helpers

- **Status:** Accepted
- **Date:** 2026-05-27
- **Graduated from:** Phase 17, decision D-32

## Context

Laravel's facade layer and global helper functions (`auth()`, `request()`,
`config()`, `app()`, `now()`, `Auth::user()`, `DB::table()`,
`Cache::get()`, `Log::info()`) are convenient and ubiquitous in the
ecosystem. They are also, for code that has to remain testable under
Larastan level 10 strict and provable at the module boundary, a slow
trap.

Three concrete pains made the case during the early phases:

- **Unit tests acquired hidden setup.** A service that called `auth()`
  internally needed a full Laravel container to test. A service that
  declared `User $user` in its constructor needed nothing — instantiate
  with a stub, call the method, assert. The first style accreted a
  `RefreshDatabase` requirement and slowed the test suite; the second
  style ran in microseconds.
- **Larastan couldn't see across facade calls.** `Auth::user()` returns
  `User|null` regardless of whether the calling context guarantees an
  authenticated user. A constructor-injected `User $user` is
  non-nullable by construction. Level 10 strict caught the entire class
  of bugs the facade form silently allowed.
- **Module boundaries leaked through helpers.** A `request()` call inside
  `Modules/Forecasting/` doesn't look like a cross-module dependency —
  but it pulls the current HTTP request into a domain service that has
  no business knowing about HTTP. The constructor-injection style makes
  the leak visible because the request object has to be passed in
  explicitly.

The DI-only rule was tried tentatively in Phase 1, formalized into an
arch invariant in Phase 2, and held without exception through the eleven
phases that shipped v1.0.

## Decision

All collaborators are constructor-injected. Specifically:

- **Forbidden:** facade static calls
  (`Auth::user()`, `DB::table()`, `Cache::get()`, `Log::info()`,
  `Storage::disk()`, `Bus::dispatch()`, ...), and global helper
  functions (`auth()`, `request()`, `config()`, `app()`, `now()`,
  `today()`, `view()`, ...).
- **Allowed:** Eloquent models used directly — instantiation
  (`new Transaction()`), static lookups (`Transaction::find($id)`),
  relationship traversal (`$user->transactions`), and query-builder
  via `$model->newQuery()`. The model itself is treated as a value type
  the consumer is allowed to know about; only the global facade indirection
  is forbidden.
- **Logging** uses `LoggerInterface` (constructor-injected,
  PSR-3-compliant), not `Log::info()`.
- **Time** uses `Carbon\CarbonImmutable` instances passed in, not
  `now()` or `Carbon::now()` at the call site.
- **Configuration** is read via constructor-injected typed config
  objects or, where unavoidable, via a `ConfigRepository` collaborator.
  Free-form `config('foo.bar')` is forbidden in module code.

The single allow-listed exception: Laravel service providers and the
`Modules/Core/Internal/Console/` console-bootstrap layer may use the
facade form during framework bootstrap, because they run before the
container has resolved enough to inject. This carve-out is itself
enforced by [`noFacadeCallsFromCoreConsoleCommands`](#)
and [`noLaravelGlobalHelpersInCoreConsoleCommands`](#) arch invariants.

## Consequences

- **Tests stay fast and honest.** Most unit tests instantiate a class
  with stub collaborators and run in microseconds. Feature tests that
  need a database still use `RefreshDatabase`, but they do so by
  choice, not because a buried `auth()` call forced the framework
  upgrade.
- **Larastan level 10 strict is sustainable.** Every dependency has a
  declared type. Nullable vs non-nullable is decided at the constructor,
  not at every call site. The static analyser produces fewer "this might
  be null" warnings because the upstream contract already ruled them
  out.
- **Module boundaries stay visible.** A service that needs the
  authenticated user has to declare it in its constructor signature, so
  the dependency shows up in the import graph. There is no way to
  smuggle a cross-module dependency through a global helper without
  someone noticing.
- **Onboarding cost.** A developer joining the project must un-learn
  the facade form for a week or two. The reward — every test is fast,
  every boundary is visible — is paid back immediately.

## Alternatives considered

- **Half-DI: facades allowed for cross-cutting concerns (logging,
  events).** Rejected — the carve-out always grew. "Just logging" became
  "logging plus events" became "logging plus events plus cache". The
  arch invariant was easier to defend at the boundary than in the
  middle.
- **Custom facade wrapper that exposed the typed interface but stayed
  globally accessible.** Rejected — same testability problem as the
  facade itself, plus a layer of indirection to debug.

## Related

- [ADR 0001 — Modular architecture](0001-modular-architecture.md) —
  explains the `Public/Internal/Models/` directory split this rule
  operates inside.
- [Architecture — Module boundaries](../architecture/module-boundaries.md)
  — names the arch invariants that enforce this rule.
