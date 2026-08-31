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

Splitting them removed those six cycles outright. `Shell` is a sink, and "the
shell depends on features" needs no arch rule to defend because it is the
direction dependencies are supposed to run. The other direction now does have
one: `tests/Contracts/NothingDependsOnTheShellArchTest.php` fails when a
production file outside this module names a `Modules\Shell\` symbol. Exactly
one crossing is pinned — `DevMode` reads `AppNavigation::destinations()` so the
⌘K palette offers the same screens the rail does.

That invariant is why the destination vocabulary is not here. `Destination`
named every screen the app can send a reader to, which is what a feature module
wants when it links to `/imports/new` — and pointing `Ledger` at it would have
created the very edge above. It moved to
`Modules\Core\Public\Navigation\Destination`, which every module already
depends on; what stayed is the chrome around it, `AppNavigation` and
`ResolvedDestination` — sidebar order, icons, translation keys, palette
keywords. See
[Navigation destinations](../../architecture/navigation-destinations.md).

The four `Core` outbound edges that remain (`Auth`, `Desktop`, `Search`, `Sync`)
are kernel services rather than screens — the encryption-migration service, the
chrome resolver every layout calls, the doctor command's FTS probe, and the two
writers that raise `EntityMutated`. They stay: removing them is a different
refactor, on the kernel rather than on the shell.

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
  auto-import section, with `Auth`'s app-lock section, with `Onboarding`'s
  country step — through `x-core::country-options`, which names the empty
  option once for all four country pickers — and with `Mobile`'s sync
  screen and import screen, and `Mobile\Internal\Native\AppShellScreen` hard-codes
  `core::sidebar.nav.*` for its bottom bar. Moving the namespace would have been
  a change a paired device can observe.

What did change is the **view** namespace: the blades moved with their classes
and are addressed as `shell::`.

## The trap that namespace change sets

A Blade view is named as a string in more places than it is rendered. Five
providers bind a view *composer* to the sidebar by name — `Anomaly`,
`Notifications`, `Chains`, `EmailScan` and `Forecasting` each call
`$factory->composer('shell::livewire.app-sidebar', …)` to merge their nav
badge count into `navCounts`.

Renaming the view silently unbinds a composer. Nothing throws, the page still
renders, and the badge simply disappears; only `TopNavAnomalyBadgeTest` caught
it. The sidebar snapshot lock did **not** — it renders the component directly,
so composer-injected data is outside what it pins. That is how five composers
came to name the deleted `core::livewire.top-nav` for a whole phase, three of
them holding counts the product then simply did not have.

`tests/Contracts/ViewReferencesResolveArchTest.php` is the static guard now: it
reads every view name out of binding position (`->composer(`, `->creator(`) and
rendering position (`view(`, `Route::view(`, `response()->view(`, `@include`,
`@extends`) and asks the view finder whether anything answers to it, failing
with the file and line that names a view nothing renders. Two channels stay
outside it — `x-<ns>::` component tags, which resolve through the Blade
component resolver rather than the finder, and `$this->view()` on a NativePHP
screen, which resolves against the mobile root.

## The dashboard's empty period

Every figure on the dashboard is scoped to one period, and a reader can be
looking at a period they have no records in. That happens on the very first
run: the setup wizard ends on an import, and a statement covering February to
April, confirmed in August, drops the reader on a screen reading `IN €0.00 OUT
€0.00 NET €0.00`, "No categorized expenses yet", "Nothing here for this
period". Every one of those figures is correct. Together they say the import
failed, immediately after the wizard said it worked — the only hint the data
exists is a non-zero net worth, and the only way to reach it is the `‹` glyph
pressed an unknown number of times.

`Dashboard::render()` asks
[`PopulatedPeriodQuery::latestWithRecords()`](../ledger/architecture.md#populatedperiodquery--where-the-records-actually-are)
for the period worth offering, and the blade renders the notice only when the
answer is not null. So it is an empty *state*, not a standing banner: a
populated period draws nothing, and neither does an install with nothing
imported, because that reader wants `/imports/new` and not a jump to nowhere.
`goToLatestPeriod()` re-derives the target rather than trusting the anchor in
the wire payload, and does nothing at all when the answer has since become
null.

This is deliberately the sibling of `/transactions`' "Nothing in the last 90
days. Your older transactions are still here. **[Show full history]**" — the
same shape (a control inside the empty case), the same voice (state the fact,
then offer the way forward), and the same verb on the button.

Two shapes here are load-bearing and easy to undo by accident. The block sits
at `dashboard-phone-order-2`, above the tiles it explains, because every child
of `.dashboard-main` must carry an order class — one without it falls back to
`order: 0` and sorts above the header. And it deliberately does **not** carry
`dashboard-tile`: that class hides a wrapper whose grandchild is missing, which
is right for a Livewire tile that decided to render nothing and wrong here,
where the children are a paragraph and a button holding text rather than
elements.
