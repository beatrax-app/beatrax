# The Dev Console on a shipped build

The console is a live operational seam into a running install: an artisan
runner, a read-only SQL box, a queue inspector, the log tailer. On a
developer's machine that is the point. On a build a stranger could be holding,
`users.is_developer` is not enough — it is a column in the user's own database,
set for the first account that ever signed up.

`DevConsoleBuildGate` is the second lock. It answers one question — *does this
build let the console exist at all?* — and `EnsureDeveloperMode` asks it before
it asks anything about the account.

## The rule

| Build | What opens the console |
|---|---|
| Development (`APP_ENV` is `local` or `testing`) | Nothing. Unchanged: `is_developer` alone, exactly as before. |
| Shipped desktop, or a self-hosted server | `BEATRAX_DEV_MODE=true` passed in the launch environment. |
| Shipped mobile | A debuggable build — `APP_DEBUG=true` in the bundle. |

The development row is an allow-list, and it has to be. The gate used to ask
whether `APP_ENV` differed from the single literal `production`, which made a
self-hosted `staging`, a hand-written `prod`, and a `Production` that merely
disagreed about capitalisation all read as a developer's checkout — the artisan
runner, the SQL box and the queue inspector open, on an install a stranger could
be holding, with no second key involved. Naming the two environments this
repository actually writes closes every other spelling at once, and closes the
ones nobody has thought of yet. The value is trimmed and lower-cased before the
comparison, so `Local` is still a development build; anything else needs the
same flag a release does.

Refusal is a `NotFoundHttpException`, never a 403. A 403 confirms the console is
there and merely shut; the whole point is that a shipped build answers a probe
at `/dev/sql` the same way it answers a probe at an address that was never
routed. A test asserts the two statuses are equal.

## Why `APP_ENV` is the production signal

It is not a convention this page is inventing. Every release job in
`.github/workflows/release.yml` — macOS, Windows, Linux and Android alike —
rewrites the `.env` it built from with `APP_ENV=production` and
`APP_DEBUG=false` before it packages anything. A development bundle
(`native:run`, `native:serve`, a checkout) carries `APP_ENV=local`, and the test
suite carries `APP_ENV=testing`. Those two are the whole of the allow-list, and
they are the same pair `NoiseHandshakeState::setEphemeralKeypair()` refuses
outside of — one vocabulary for "this is not a build anybody was shipped".

That was, until recently, the whole of the guarantee: a property of one YAML
file rather than of the build. A phone build driven from anywhere else —
`php artisan mobile:package-android` on a developer's machine, a second
pipeline — started from `mobile-app/.env.example`, which carries `APP_ENV=local`
and `APP_DEBUG=true`, and shipped an APK whose console opened to whoever
installed it. `PackageAndroidCommand` now refuses to package until the `.env`
it is about to bundle carries both keys, the same way it already refuses an
unpinned `NATIVEPHP_APP_ID`; `ShippedEnvironment` is the predicate, and it reads
the first uncommented assignment because that is the one Dotenv lets reach
`config()`.

On a mobile runtime `APP_DEBUG` is the only lever that matters. `dev_mode` is
not consulted on that branch at all, so stripping `BEATRAX_DEV_MODE` from the
bundled `.env` — which the packager does — protects the desktop and nothing
else.

## Why the desktop flag is the environment variable and not a launcher switch

`BEATRAX_DEV_MODE` is listed in `cleanup_env_keys` in `config/nativephp.php`, so
the packager **deletes it** from the `.env` that ships inside the bundle. That
is what makes it a flag rather than a setting: there is no file in the installed
app that turns the console on. The only place the value can come from is the
environment of the process that launched the app —

```sh
BEATRAX_DEV_MODE=true /Applications/Beatrax.app/Contents/MacOS/Beatrax
```

— and Laravel's env repository keeps that value, because the immutable dotenv
loader never overwrites a variable that is already set.

A command-line switch on the launcher was considered and rejected. NativePHP
Desktop's PHP side reads no `argv` at all: a switch would have to be plumbed
from Electron's main process into the environment of the PHP server it spawns,
which is a change to vendor behaviour that cannot be verified from this
repository. The environment variable needs no plumbing and is already wired to
`config('app.dev_mode')`, which the destructive-command triple gate reads too —
one lock, not two that can disagree.

## The mobile signal, and the gap in it

The requirement is that the *device* be in developer mode. **The NativePHP
mobile runtime cannot read that, and this implementation does not pretend to.**

The bridge between PHP and the host exposes twenty-seven functions, registered
in `BridgeFunctionRegistration.kt` on Android and `BridgeFunctionRegistration.swift`
on iOS. `Device.GetInfo` is the closest one, and its Android payload is name,
model, platform, operating system, OS version, SDK version, manufacturer,
language, emulator flag, memory use and WebView version. There is no
`Settings.Global.DEVELOPMENT_SETTINGS_ENABLED`, no `ApplicationInfo.FLAG_DEBUGGABLE`,
and no iOS Developer Mode probe anywhere in the set. Nor is one injected into
the PHP environment: the Android host sets `NATIVEPHP_PLATFORM`,
`NATIVEPHP_TEMPDIR` and the Laravel path variables, and nothing about the
device's developer state.

So the gate uses the closest signal that is actually readable: **whether this is
a debuggable build**, `config('app.debug')`. Getting such a build onto a phone
means sideloading it over adb or a development-signed install, which does
require the device's developer options — but the app is reading the build it is
in, not the setting on the device, and a debuggable build installed on a phone
whose developer options were later switched off still opens.

`BEATRAX_DEV_MODE` is deliberately not the mobile lever. The mobile packager
runs its env-cleaning step on **every** bundle it prepares, development builds
included, so that key is absent from every phone install and could never be the
thing that opens the console there.

## Where the gate lives, and why it is not in this module

`DevConsoleBuildGate` is a `Modules\Core\Public\Services` class, not a
`DevMode` one. Six surfaces have to ask it, and three of them are in other
modules:

- `Shell` draws the sidebar's Dev block and the dashboard's failed-job toast.
  `Shell` is the module allowed to depend on everything, but `DevMode` already
  depends on `Shell` — it reads the sidebar roster so the ⌘K palette offers the
  same screens the rail does — so a `Shell → DevMode` import would close that
  into a cycle.
- `Desktop` builds the native menu bar, including the Developer submenu.

`Core` is the module all three already depend on, so the seam costs no new
edge. This is the same trade
[the module map](../../architecture/module-boundaries.md) records for the
navigation vocabulary: when two modules need a shared value and one of them is
`Shell`, the value goes to `Core` rather than through `Shell`. The gate reads
only `app.env`, `app.debug`, `app.dev_mode` and `UserDataPathService`, all of
which are `Core`'s own, so nothing about the console's internals travelled with
it.

## The surfaces that have to ask it

Route middleware does not run for a Livewire component the layout mounts on
every page, and it does not run for a menu the desktop shell builds at boot.
Each of these answers for itself:

- `EnsureDeveloperMode::handle($request, $next)` — the `/dev/*` routes.
- `CommandPaletteModal::buildRegistry()` emits the ⌘K registry as JSON to the
  browser. Without the gate a shipped build would ship every `/dev/*` address
  and every whitelisted command name to a page that then 404s on all of them.
- `CommandArgPromptModal::open()` and its `submit()` reach `CommandSpawner`
  over the wire, with no route in front of them at all.
- `AppSidebar::render()` — the rail's Dev block, an `/dev` link plus a
  `wire:poll` sub-tree that counts jobs and reads the worker heartbeat. The
  gate is on the same flag the live-data reads are conditional on, so a shipped
  build stops paying for them too.
- `Dashboard::render()` — the failed-chain toast, whose only affordance is a
  deep link to the queue inspector.
- `AppMenuBuilder::build()` — the native Developer submenu. This one carries an
  OS accelerator, `Cmd+.`, so an ungated entry is not merely a dead link: it is
  a key chord that takes the user to a 404.

An entry that survives into a build which cannot open it does not read as a
console that was withheld. It reads as an app that is broken.

## Two neighbours that look like the same thing and are not

`HelpDataLocations` renders `$devModeOn` on `/help/data-locations`, and
`SettingsPage` renders the `is_developer` switch. Neither is gated, and neither
should be:

- The help page never names the console. `$devModeOn` chooses between a
  disabled export-everything stub and the manual-copy instructions, and the
  copy it renders points the reader at Settings.
- The Settings switch is how a user turns their own developer flag on. It is
  the thing the help page tells them to use, and gating it on the build would
  leave a shipped install with no way to set the flag that the desktop launch
  flag or a debuggable mobile build is then paired with. It is an account
  preference that navigates nowhere.
