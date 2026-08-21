# `Shell` — architecture

The `Shell` module owns the application's own screens: the primary navigation
(`AppSidebar` and the phone drawer that wraps it), the dashboard, the settings
page, and the two dashboard cards that read across every module
(`NetWorthCard`, `SpendingTrendCard`). It owns no domain slice. Every other
module answers a question about money; this one arranges those answers into the
three surfaces a user actually opens.

## Why it is not part of `Core`

`Core` is the kernel: `User`, `BelongsToUser`, `UserScope`, `LockStore`,
`SystemAlert`, `UserPreference`, and the services around them. Every module
depends on it, which means `Core` must depend on as little as possible.

The shell is the exact opposite shape — it must depend on everything, because a
dashboard that aggregates nine modules imports nine modules. While both lived in
`Core`, that single module was simultaneously the thing everyone depends on and
a thing that depends on everyone, and the result was a dependency cycle against
six other modules for no reason other than co-location.

Splitting them removed those six cycles outright. `Shell` has in-degree 0: it is
a sink, nothing imports it, and "the shell depends on features" needs no arch
rule to defend because it is the direction dependencies are supposed to run.

The four `Core` outbound edges that remain (`Auth`, `Desktop`, `Search`, `Sync`)
are kernel services rather than screens — the encryption-migration service, the
chrome resolver every layout calls, the doctor command's FTS probe, and the two
writers that raise `EntityMutated`. Removing those is a different refactor and
was deliberately not attempted here.

## The names that did not change

The move is invisible from outside the module, and deliberately so:

- **Route names and URLs** — `dashboard` at `/` and `settings` at `/settings`
  are linked from every module's views. They moved file, not identity.
- **Livewire aliases** — the five components stay registered as `core.dashboard`,
  `core.settings-page`, `core.app-sidebar`, `core.net-worth-card` and
  `core.spending-trend-card`. An alias is rendered into the page as `wire:name`
  and is locked by `tests/.pest/snapshots/.../SidebarTest`, so it is observable
  output, not a naming convention. `ShellServiceProvider` registers a `core.`
  prefix on purpose; `pinnedCrossModuleLivewireMounts` resolves ownership from
  the registering provider, never from the prefix.
- **Translation keys** — `core::dashboard.*`, `core::sidebar.*`,
  `core::settings.*`, `core::net_worth.*` and `core::spending_trend.*` stay in
  `Core`. `core::settings.*` is shared with `Core`'s own locale switcher and
  auto-import section, with `Auth`'s app-lock section and with `Mobile`'s sync
  screen, and `Mobile\Internal\Native\AppShellScreen` hard-codes
  `core::sidebar.nav.*` for its bottom bar. Moving the namespace would have been
  a change a paired device can observe.

What did change is the **view** namespace: the blades moved with their classes
and are addressed as `shell::`.

## The trap that namespace change sets

A Blade view is named as a string in more places than it is rendered. Two
providers bind a view *composer* to the sidebar by name —
`AnomalyServiceProvider` and `NotificationsServiceProvider` both call
`$factory->composer('shell::livewire.app-sidebar', …)` to inject their nav
badge counts.

Renaming the view silently unbinds a composer. Nothing throws, the page still
renders, and the badge simply disappears; only `TopNavAnomalyBadgeTest` caught
it. The sidebar snapshot lock did **not** — it renders the component directly,
so composer-injected data is outside what it pins.

If a view under `Modules/Shell/Resources/views/` is ever renamed again, grep for
the old name in *binding* position (`->composer(`, `->creator(`) as well as in
rendering position (`view(`, `->make(`, `@include`, `@extends`, `x-<ns>::`).
There is no static guard on this channel today.
