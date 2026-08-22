# Navigation destinations

Every screen a user can be sent to is named once, as a case of
`Modules\Core\Public\Navigation\Destination`. Nothing else spells a route name
for one of those screens — not a Blade `href`, not a redirect, not the desktop
menu bar, not a notification deep link.

```php
Destination::Transactions->routeName();   // 'transactions.index'
Destination::UnusualCharges->routeParams(); // ['type' => 'anomaly']
Destination::Reports->url(['report' => 7]); // absolute URL, extra params merged
Destination::Settings->path();              // '/settings', root-relative
```

## Why it lives in `Core` and not in `Shell`

The vocabulary started in `Shell`, next to the sidebar that renders it, and a
quality review asked why the feature modules were still writing
`route('imports.new')` by hand instead of naming a destination. The obvious fix
— point them at `Shell` — is the wrong one, and the reason is the invariant
`Shell` exists to hold.

`Shell` composes every other module: the dashboard aggregates nine of them, the
settings page a dozen. That is only free of cycles because **nothing depends
back on it** (see [`Shell` — architecture](../features/shell/architecture.md)).
Routing `Ledger` and `Forecasting` through `Shell\Public\Navigation` would have
created exactly the `Ledger → Shell` and `Forecasting → Shell` edges the
`Core`/`Shell` split was made to remove — trading a string literal for a
dependency cycle.

`Core` is the other end of the graph: every module already depends on it, and it
depends on nothing. A destination named there costs a feature module no new
edge at all. So the split is:

| Owner | What it owns | Why there |
| --- | --- | --- |
| `Core` | Which screens exist, and how each is addressed — case, route name, route params, and the `url()` / `path()` accessors | The vocabulary is shared by everyone, and `Core` is the only module everyone may already name |
| `Shell` | Order, icon, translation key, palette keywords — `AppNavigation` and `ResolvedDestination` | Chrome for the rail and the palette. Moving it to `Core` would drag sidebar layout into the kernel, which is worse than the literals were |

`tests/Contracts/NothingDependsOnTheShellArchTest.php` pins the result: no
production file outside `Modules/Shell/` may name a `Modules\Shell\` symbol,
with one pinned exception (below).

## The one inbound edge, and the one that is not an import

`Modules/DevMode/Providers/DevModeServiceProvider.php` reads
`AppNavigation::destinations()` so the ⌘K palette offers the same roster the
rail does rather than keeping a second list that falls behind. It is pinned in
the invariant with that reason. It is chrome consuming chrome, not a domain
module reaching for a screen.

Five providers — `Anomaly`, `Notifications`, `Chains`, `EmailScan` and
`Forecasting` — also bind a view composer to `shell::livewire.app-sidebar` to
merge a badge count into the rail. That is a dependency no import declares, so
the invariant above cannot see it;
`tests/Contracts/ViewReferencesResolveArchTest.php` is what proves those
bindings still name a view that exists.

## Using the seam

**From Blade**, `@use` the enum and call `url()`. Never a global helper: the
`mobile-app/` peer Composer root does not inherit this root's
`autoload.files`, so a helper defined here is undefined when that root renders
the same shared template.

```blade
@use('Modules\Core\Public\Navigation\Destination')
<a href="{{ Destination::Imports->url() }}">…</a>
```

**From PHP**, inject `UrlGenerator` as the DI-only rule requires and pass it in,
so the destination carries its params and the class keeps its dependency
visible:

```php
$this->redirect(Destination::Transactions->urlFrom($urls), navigate: true);
```

**Where an API takes a route name** rather than a URL — `Menu::route()`,
Livewire's `redirectRoute()`, `Redirector::route()` — use `routeName()`.

## What a case's value is

The value is the palette's registry id and the key the per-user Recent cache
dedupes on, and it mirrors the route name for every case but one.
`UnusualCharges` is `/drift` filtered to the anomaly section: it shares
`DriftAlerts`' route and is told apart by `routeParams()`, so its id cannot
mirror the route name without colliding with it. That is why `routeName()`
exists as a method instead of callers reading `->value`.

## What stays a literal, deliberately

- **The route definitions.** A module's `Routes/web.php` still writes
  `->name('transactions.index')`. That is the definition, not a copy of it, and
  `PaletteReachesEverySidebarDestinationArchTest` fails if any declared
  destination stops resolving to a registered route.
- **`Modules/Mobile/Internal/Native/AppShellScreen::DESTINATIONS`.** The phone's
  bottom bar hard-codes four URL *paths*. It cannot read them from the enum:
  the table is a `const`, which no container-backed accessor can fill, and
  `mobile-app/bootstrap/providers.php` does not boot `ShellServiceProvider`, so
  `dashboard` and `settings` are not registered route names in that root at all.
  Resolving them there would throw where a literal works.

## Related

- [Module boundaries](module-boundaries.md) — the `Public`/`Internal` split and
  the rest of the invariants.
- [`Shell` — architecture](../features/shell/architecture.md) — what the shell
  module owns and why it is not part of `Core`.
