# Phase 15: Desktop Shell (NativePHP Integration) - Pattern Map

**Mapped:** 2026-05-22
**Files analyzed:** 18 new/modified files
**Analogs found:** 16 / 18

> Phase 15 is largely greenfield (a brand-new `Modules/Desktop/` module + native chrome
> + a full dark-theme retrofit). It nonetheless maps cleanly onto well-established
> codebase conventions: module ServiceProvider shape, Public `final readonly` events,
> anonymous-class migrations, stateless Livewire components, scoped `Schedule::call`
> entries, and the `BoundaryArchTest` carve-out idiom. Every analog below is current
> (Phases 11-14, the most recently authored module code).

## File Classification

| New/Modified File | Role | Data Flow | Closest Analog | Match Quality |
|-------------------|------|-----------|----------------|---------------|
| `Modules/Desktop/Providers/DesktopServiceProvider.php` | provider | event-driven | `Modules/Receipts/Providers/ReceiptsServiceProvider.php` | exact |
| `Modules/Desktop/Internal/NativeAppServiceProvider.php` | provider (native-chrome) | request-response | `Modules/Core/Providers/CoreServiceProvider.php` | role-match |
| `Modules/Desktop/Public/Events/FileOpenedFromOs.php` | event | event-driven | `Modules/Forecasting/Public/Events/ForecastShortfallDetected.php` | exact |
| `Modules/Desktop/Internal/Native/AppMenuBuilder.php` | utility (native-chrome builder) | request-response | — (NativePHP facade code, no analog) | no analog |
| `Modules/Desktop/Internal/Native/TrayMenuBuilder.php` | utility (native-chrome builder) | request-response | — (NativePHP facade code, no analog) | no analog |
| `Modules/Desktop/Internal/Listeners/SurfaceWorkerCrashAlert.php` | listener | event-driven | `Modules/EmailScan/Internal/Listeners/EmitOAuthReauthRequiredAlert.php` | exact |
| `Modules/Desktop/Internal/Listeners/DispatchOsNotification.php` | listener | event-driven | `Modules/Receipts/Internal/Listeners/DispatchChainHintsFromReceipt.php` | role-match |
| `Modules/Desktop/Internal/Http/Livewire/SetupScreen.php` | component (Livewire) | request-response | `Modules/Core/Internal/Http/Livewire/SystemAlertsBanner.php` | role-match |
| `Modules/Desktop/Internal/Http/Livewire/WelcomeScreen.php` | component (Livewire) | request-response | `Modules/Core/Internal/Http/Livewire/SystemAlertsBanner.php` | role-match |
| `Modules/Desktop/Internal/Http/Livewire/FileStagingPage.php` | component (Livewire) | request-response | `Modules/Core/Internal/Http/Livewire/SettingsPage.php` | role-match |
| `Modules/Desktop/Internal/Http/Livewire/CloseWindowPrompt.php` | component (Livewire) | request-response | `Modules/Core/Internal/Http/Livewire/SettingsPage.php` (instant-apply toggle) | role-match |
| `Modules/Desktop/Routes/web.php` | route | request-response | `Modules/Import/Routes/web.php` | exact |
| `Modules/Desktop/Database/Migrations/*_add_theme_to_users.php` | migration | CRUD | `Modules/Core/Database/Migrations/..._add_auto_import_drop_folder_to_users.php` | exact |
| `Modules/Core/Internal/Http/Livewire/SettingsPage.php` (theme control) | component (modified) | request-response | self — extend the existing instant-apply `toggleAutoImport` pattern | exact |
| `tests/Contracts/BoundaryArchTest.php` (new invariant) | test (arch) | — | `tests/Contracts/BoundaryArchTest.php` — `noHorizonImportsInShippedBuildCode` | exact |
| `bootstrap/providers.php` (register Desktop provider) | config (modified) | — | self — the `class_exists()`-guarded `array_filter` list | exact |
| `routes/console.php` (timer email-scan entry) | config (modified) | event-driven | self — the existing `Schedule::call(...)` entries | exact |
| `resources/css/app.css` (dark variant) | config (modified) | — | self — minimal Tailwind v4 file; dark is greenfield | partial |

## Pattern Assignments

### `Modules/Desktop/Providers/DesktopServiceProvider.php` (provider, event-driven)

**Analog:** `Modules/Receipts/Providers/ReceiptsServiceProvider.php`

This is the module's `bootstrap/providers.php` entry point — it registers DI bindings,
loads migrations/routes/views, registers Livewire components, and subscribes listeners.
Mirror the analog exactly.

**Class shape + `boot()` resource loading** (`ReceiptsServiceProvider.php` lines 50, 119-145):
```php
final class ReceiptsServiceProvider extends ServiceProvider
{
    public function boot(LivewireManager $livewire, Dispatcher $events): void
    {
        if (is_dir(__DIR__.'/../Database/Migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        }
        if (is_file(__DIR__.'/../Routes/web.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        }
        if (is_dir(__DIR__.'/../Resources/views')) {
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'receipts');
        }
        $livewire->component('receipts.wizard-email-file-step', WizardEmailFileStep::class);
        // Subscribe a listener to a cross-module event in boot() — depends on
        // the injected Dispatcher; the listener itself is a register()-bound singleton.
        $events->listen(TransactionImported::class, [DispatchChainHintsFromReceipt::class, 'handle']);
    }
}
```
**Apply for Desktop:** load views under the `desktop` namespace; register the
`SetupScreen` / `WelcomeScreen` / `FileStagingPage` / `CloseWindowPrompt` Livewire
components; subscribe `SurfaceWorkerCrashAlert` to NativePHP's `ProcessExited` event and
`DispatchOsNotification` to the in-app domain events (D-12 categories).

**`register()` singleton-binding + `class_exists()` guard** (`ReceiptsServiceProvider.php` lines 76-98):
```php
public function register(): void
{
    foreach (self::PIPELINE_FQNS as $fqn) {
        if (class_exists($fqn)) {
            $this->app->singleton($fqn);
        }
    }
    $this->app->singleton(RecordReceipt::class);
    $this->app->singleton(DispatchChainHintsFromReceipt::class);
}
```
**Apply for Desktop:** bind the menu/tray builders, the listeners, and any
`WindowFocusState`-style focus-tracking service as singletons.

> **Provider naming note (RESEARCH.md):** `config/nativephp.php`'s `provider` key must
> point at `Modules\Desktop\Internal\NativeAppServiceProvider` (the native-chrome
> provider), NOT at `DesktopServiceProvider`. `DesktopServiceProvider` is the one
> registered in `bootstrap/providers.php`.

---

### `Modules/Desktop/Internal/NativeAppServiceProvider.php` (provider, native-chrome)

**Analog:** `Modules/Core/Providers/CoreServiceProvider.php` (closest in-repo provider shape)

This is the NativePHP-booted provider — its `boot()` is the **only** place
`Native\Desktop\Facades\*` calls are permitted. No in-repo analog exists for the
NativePHP facade calls themselves; see RESEARCH.md Pattern 1 + Code Examples for the
`Menu::create()` / `Window::open()->rememberState()` / `MenuBar::create()` shape.

**Crucial constraint:** This file uses NativePHP facades, which the DI-only rule
(`feedback_laravel_di_only.md`) and `BoundaryArchTest`'s "no Laravel facade usage in
module code" rule normally forbid. It must be added to the facade-rule allow-list AND
mirrored in `phpstan.neon` ignoreErrors — exactly like the `LockStore` carve-out
(see the arch-test + phpstan sections below). NativePHP facades live in
`Native\Desktop\Facades\*`, not `Illuminate\Support\Facades\*`, so the existing
facade arch rule does not catch them — but the DI-only project rule still does; the
allow-list documents the deliberate exception.

---

### `Modules/Desktop/Public/Events/FileOpenedFromOs.php` (event, event-driven)

**Analog:** `Modules/Forecasting/Public/Events/ForecastShortfallDetected.php`

Every Public event in the codebase is a `final readonly` class with a constructor-only
payload and no behavior. Copy the shape verbatim.

**Full event pattern** (`ForecastShortfallDetected.php` lines 23-35):
```php
namespace Modules\Forecasting\Public\Events;

/**
 * Emitted by ... when ... . Operational hooks subscribe to ... .
 */
final readonly class ForecastShortfallDetected
{
    public function __construct(
        public int $userId,
        public int $accountId,
        public ?int $scenarioId,
        public string $currency,
    ) {}
}
```
Simpler precedent: `Modules/Core/Public/Events/UserInstalled.php` —
`final class UserInstalled { public function __construct(public readonly int $userId) {} }`.

**Apply for `FileOpenedFromOs`:** payload is the validated file path + extension
(`'eml'|'csv'`) + the originating-OS-event marker. **Security (RESEARCH.md V5/V12):**
the OS-supplied path is untrusted — the extension allow-list check belongs at the
emission boundary, not in the event itself. Keep the event a dumb DTO.

---

### `Modules/Desktop/Internal/Listeners/SurfaceWorkerCrashAlert.php` (listener, event-driven)

**Analog:** `Modules/EmailScan/Internal/Listeners/EmitOAuthReauthRequiredAlert.php`

This is the closest analog in the codebase for D-07: a listener that writes a
`system_alerts` row. It demonstrates constructor DI, the de-dup guard, and the
`SystemAlert::query()->create()` write.

**Constructor DI + de-dup + write** (`EmitOAuthReauthRequiredAlert.php` lines 36-87):
```php
final class EmitOAuthReauthRequiredAlert
{
    private const REAUTH_KIND = 'oauth.reauth_required';

    public function __construct(
        private readonly Filesystem $files,
        private readonly CurrentUser $currentUser,
        private readonly DatabaseManager $db,
        private readonly UserDataPathService $paths,
    ) {}

    public function handle(): void
    {
        $userId = $this->currentUser->id();
        $connection = $this->db->connection();

        // De-dup: an un-acknowledged alert of this kind is already on the banner.
        $alreadyAlerted = $connection->table('system_alerts')
            ->where('user_id', $userId)
            ->where('kind', self::REAUTH_KIND)
            ->whereNull('acknowledged_at')
            ->exists();
        if ($alreadyAlerted) {
            return;
        }

        SystemAlert::query()->create([
            'user_id' => $userId,
            'kind' => self::REAUTH_KIND,
            'severity' => 'warning',
            'message' => self::MESSAGE,
        ]);
    }
}
```
**Apply for Desktop:** subscribe to NativePHP's `ProcessExited` event; use
`kind = 'worker.crashed'`, `severity = 'critical'`. The "repeated failure" counter
(RESEARCH.md Pattern 3 gotcha — NativePHP auto-restarts, so a single exit is not the
signal) lives here as a windowed `ProcessExited`-count check before escalating. Copy
verbatim copy: title "Background work stopped" (UI-SPEC). After the alert write, the
listener also fires the OS notification when the window is unfocused (D-13) — see
`DispatchOsNotification`.

---

### `Modules/Desktop/Internal/Http/Livewire/{SetupScreen,WelcomeScreen,FileStagingPage,CloseWindowPrompt}.php` (component, request-response)

**Analog:** `Modules/Core/Internal/Http/Livewire/SystemAlertsBanner.php` (stateless screens)
and `Modules/Core/Internal/Http/Livewire/SettingsPage.php` (instant-apply action pattern).

**CRITICAL Livewire constraint** (`SystemAlertsBanner.php` lines 24-31, 35-46):
```php
/**
 * No constructor DI — phpstan-strict-rules bans it on Livewire
 * Component subclasses. Method-parameter DI on render()/actions instead.
 */
final class SystemAlertsBanner extends Component
{
    public function render(
        CurrentUser $currentUser,
        SystemAlertQuery $query,
        ViewFactory $views,
    ): View {
        return $views->make('core::livewire.system-alerts-banner', ['alerts' => ...]);
    }

    public function acknowledge(int $alertId, AcknowledgeSystemAlert $action, CurrentUser $currentUser): void
    {
        $action($alertId, $currentUser->user());
    }
}
```
**Apply to all four Desktop Livewire screens:** never constructor-inject; resolve
collaborators as `render()` / action-method parameters. `SetupScreen` and
`WelcomeScreen` are stateless (zero properties — like `SystemAlertsBanner`).

**Instant-apply action + raw query-builder write** (`SettingsPage.php` lines 117-129) —
the pattern for the `CloseWindowPrompt` "Remember my choice" persistence (D-08) and the
Settings theme control:
```php
public function toggleAutoImport(CurrentUser $currentUser, DatabaseManager $db, Clock $clock): void
{
    $this->autoImportFromDropFolder = ! $this->autoImportFromDropFolder;
    $user = $currentUser->user();
    $db->connection()
        ->table('users')
        ->where('id', $user->id)
        ->update([
            'auto_import_drop_folder' => $this->autoImportFromDropFolder,
            'updated_at' => $clock->now()->toDateTimeString(),
        ]);
}
```

**`mount()` reads the user row, validation via `#[Validate]` attribute**
(`SettingsPage.php` lines 43, 96-105) — for the theme preference (`light`/`dark`/`system`):
```php
#[Validate('required|in:eur_only,original')]
public string $defaultCurrencyView = 'eur_only';

public function mount(CurrentUser $currentUser): void
{
    $this->defaultCurrencyView = $currentUser->user()->default_currency_view;
}
```
For the theme control: `#[Validate('required|in:light,dark,system')]`.

**Security note (RESEARCH.md V4):** all four staging/screen routes sit behind `auth`
middleware; the pending-file-intent (D-04) is session-scoped and must re-check
`CurrentUser` after login before continuing — never trust a request-supplied user id
(the `SettingsPage` docblock states this rule explicitly).

---

### `Modules/Desktop/Routes/web.php` (route, request-response)

**Analog:** `Modules/Import/Routes/web.php`

**Full route pattern** (`Modules/Import/Routes/web.php` lines 9-23):
```php
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(static function (): void {
    Route::view('/imports/new', 'import::wizard')->name('imports.new');

    Route::get('/imports/{id}/preview', static function (string $id, ViewFactory $views): Response {
        return new Response($views->make('import::preview', ['id' => (int) $id])->render());
    })->where('id', '[0-9]+')->name('imports.preview');
});
```
**Apply for Desktop:** the staging-page routes (`.csv`/`.eml`) go behind
`['web','auth']`. The "Setting up…" boot route (D-21) sits **outside** `auth` —
it renders before any user exists. `Route::view(...)` for the static welcome/boot
screens; `Route::get` closure with method-injected `ViewFactory` where a param is
needed. The `Route` facade is fine here — `routes/` and module `Routes/` files are
outside the `Modules\` *namespace* so the facade arch rule (`->not->toBeUsedIn('Modules')`)
does not apply (confirmed by the `routes/console.php` docblock).

---

### `Modules/Desktop/Database/Migrations/*_add_theme_to_users.php` (migration, CRUD)

**Analog:** `Modules/Core/Database/Migrations/2026_05_17_010007_add_auto_import_drop_folder_to_users.php`

> Migration ownership: per the established convention (CONTEXT.md "Migrations live
> inside the owning module's `Database/Migrations/`") the theme column is conceptually
> a Core/Auth `users` concern. RESEARCH.md notes it "likely" lands on the `users` table
> via Auth/Core. The planner should place this migration in `Modules/Core/Database/Migrations/`
> (Core owns the `users` table and every prior `users` column-add lives there) — NOT in
> `Modules/Desktop/`. Listed here under Desktop scope because it is Phase-15 work.

**Full migration shape — anonymous class + lazy `DatabaseManager`** (analog lines 12-50):
```php
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->string('theme', 16)
                ->default('system')
                ->after('auto_import_drop_folder');
        });
    }

    public function down(): void
    {
        $this->schema()->table('users', static function (Blueprint $table): void {
            $table->dropColumn('theme');
        });
    }

    private function schema(): Builder
    {
        return $this->db()->connection($this->getConnection())->getSchemaBuilder();
    }

    private function db(): DatabaseManager
    {
        if ($this->resolvedDb === null) {
            $db = Container::getInstance()->make(DatabaseManager::class);
            $this->resolvedDb = $db;
        }
        return $this->resolvedDb;
    }
};
```
If the planner needs a CHECK-style rail on the `theme` enum value, the
`system_alerts` migration (`2026_05_20_010001_create_system_alerts_table.php` lines
73-82) shows the `CREATE TRIGGER ... RAISE(ABORT, ...)` idiom for SQLite enum
enforcement.

---

### `tests/Contracts/BoundaryArchTest.php` — new `noNativePhpImportsOutsideDesktopModule` invariant

**Analog:** the `noHorizonImportsInShippedBuildCode` test inside the same file
(`BoundaryArchTest.php` lines 1151-1212) — a near-perfect template: scoped namespace
containment with a precise file allow-list.

**The allow-list arch-test idiom** (`noHorizonImportsInShippedBuildCode`, lines 1166-1211):
```php
it('does not allow Native\\Desktop imports outside Modules/Desktop (noNativePhpImportsOutsideDesktopModule)', function (): void {
    $allowList = [
        // Desktop module is the sole sanctioned home — but the test walks
        // the WHOLE tree and only Modules/Desktop/* paths pass implicitly.
    ];
    $hits = [];
    foreach (['app', 'Modules', 'bootstrap', 'routes'] as $root) {
        $abs = base_path($root);
        if (! is_dir($abs)) { continue; }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($abs, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if (! $file->isFile() || preg_match('/\.php$/', $file->getPathname()) !== 1) { continue; }
            $path = $file->getPathname();
            if (str_contains($path, '/tests/')) { continue; }
            if (str_contains($path, '/Modules/Desktop/')) { continue; }  // the carve-out
            $contents = (string) file_get_contents($path);
            $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;
            if (preg_match('/Native\\\\Desktop\\\\/', $stripped) === 1) {
                $hits[] = str_replace(base_path().'/', '', $path);
            }
        }
    }
    expect($hits)->toBe([], "Native\\Desktop\\* may only be imported inside Modules/Desktop. Offenders:\n  ".implode("\n  ", $hits));
});
```
> **⚠ Namespace correction (RESEARCH.md Assumptions Log A2):** CONTEXT.md says
> `Native\Laravel\*` — that is the **v1** namespace. NativePHP **v2** uses
> `Native\Desktop\*`. The invariant must target `Native\Desktop` or it passes
> vacuously. Verify the exact namespace from a fresh `composer require nativephp/desktop`
> before finalizing the regex.

The simpler arch helper form is also available — `arch('...')->expect('Native\\Desktop')->toOnlyBeUsedIn('Modules\\Desktop')` — modeled on the `Money\Money` rule at lines 69-71. The grep-based `it()` form is preferred for parity with the Horizon precedent and because it can also strip comments.

**Facade allow-list carve-out** — the DI-only exception for `NativeAppServiceProvider`
follows the `LockStore` carve-out (`BoundaryArchTest.php` lines 52-67):
```php
arch('no Laravel facade usage in module code')
    ->expect('Illuminate\\Support\\Facades')
    ->not->toBeUsedIn('Modules')
    ->ignoring([
        'Modules\\Core\\Public\\Support\\LockStore',
    ]);
```
Add `Modules\Desktop\Internal\NativeAppServiceProvider` (and the two native builders)
to a comparable allow-list — note the `Native\Desktop\Facades\*` namespace differs from
`Illuminate\Support\Facades`, so the existing rule does not catch it; the carve-out is
about documenting the DI-only-rule exception, mirrored in `phpstan.neon`.

---

### `bootstrap/providers.php` — register `DesktopServiceProvider`

**Analog:** self — the existing `class_exists()`-guarded `array_filter` list.

**Current shape** (`bootstrap/providers.php` lines 25-39):
```php
return array_values(array_filter([
    class_exists(Laravel\Horizon\HorizonServiceProvider::class) ? HorizonServiceProvider::class : null,
    CoreServiceProvider::class,
    AuthServiceProvider::class,
    // ... all module providers ...
    ForecastingServiceProvider::class,
]));
```
**Apply:** append `\Modules\Desktop\Providers\DesktopServiceProvider::class` to the
list. Unlike Horizon it needs no `class_exists()` guard — it is first-party module
code always present in the tree. The Horizon entry is the precedent for a *conditional*
guard if a NativePHP-package-absent (`--no-dev` of the desktop dep) scenario ever
needs one — but per RESEARCH.md `nativephp/desktop` is a hard `require` (not dev), so
an unconditional entry is correct.

---

### `routes/console.php` — timer-based email-scan entry (D-06)

**Analog:** self — the existing `Schedule::call(...)` entries in the same file.

D-06's timer-based email auto-scan is a plain scheduler entry — RESEARCH.md Pattern 2
confirms the NativePHP-bundled scheduler runs automatically. Mirror the existing
per-user fan-out entry (`routes/console.php`, the `email-scan.incremental` block):
```php
Schedule::call(function (DatabaseManager $db, Dispatcher $bus): void {
    $inboxIds = $db->connection()->table('inboxes')->/* ... */->pluck('inboxes.id');
    foreach ($inboxIds as $id) {
        $bus->dispatch(new IncrementalScanJob((int) $id));
    }
})->name('email-scan.incremental')->hourly()->withoutOverlapping(30);
```
**Apply:** add an `->everyFifteenMinutes()` entry (the ~15-min fallback cadence, D-06).
**Critical method-order rule** (documented repeatedly in the file): `.name()` MUST come
before `.everyFifteenMinutes()->withoutOverlapping(...)` — `CallbackEvent::withoutOverlapping`
throws `LogicException` if the description is not set first. Closure DI only — no
facades reach module code; the `Schedule` facade itself is legal here (root file,
outside `Modules\`).

---

### `resources/css/app.css` — Tailwind v4 dark variant (D-15)

**Analog:** self (partial) — the file is minimal; dark mode is greenfield.

**Current file** (`resources/css/app.css`, full — 19 lines): `@import "tailwindcss";`
plus an Inter `font-family` `:root` block and a `tabular-nums` rule. There is **zero**
dark config and **zero** `dark:` class anywhere in the codebase.

**Apply (RESEARCH.md Pattern 5 + UI-SPEC):** add the class-strategy custom variant:
```css
@import "tailwindcss";
@custom-variant dark (&:where(.dark, .dark *));
```
Then toggle a `dark` class on `<html>` server-side from the user `theme` preference.
See the Shared Patterns section for the layout-file change.

## Shared Patterns

### Module structure (Public/Internal split)
**Source:** every `Modules/*/` directory; canonical tree in RESEARCH.md "Recommended
Project Structure".
**Apply to:** the whole `Modules/Desktop/` module.
- `Public/Events/` — `FileOpenedFromOs` (the only cross-module surface).
- `Internal/` — `NativeAppServiceProvider`, `Native/*` builders, `Listeners/*`,
  `Http/Livewire/*`. `Internal` is enforced module-private by an arch rule —
  add `arch('Modules\\Desktop\\Internal is only used inside Modules\\Desktop')
  ->expect('Modules\\Desktop\\Internal')->toOnlyBeUsedIn('Modules\\Desktop')` to
  `BoundaryArchTest.php`, mirroring lines 8-50.
- `Providers/DesktopServiceProvider.php` — the `bootstrap/providers.php` target.
- Cross-module access only via Public services/events.

### DI-only rule
**Source:** `CLAUDE.md` / `feedback_laravel_di_only.md`; enforced by `BoundaryArchTest`.
**Apply to:** all Desktop module code.
- Constructor injection everywhere; no facades / global helpers. Eloquent models
  direct is OK (`SystemAlert::query()->create()` in the listener analog).
- **The one exception:** `Native\Desktop\Facades\*` in `NativeAppServiceProvider` +
  the native builders. Allow-list it in `BoundaryArchTest` AND `phpstan.neon`
  ignoreErrors — the `LockStore` carve-out (arch lines 52-67 / phpstan lines 23-37)
  is the exact precedent: a per-file precise list with a justifying docblock.

### Path resolution (Phase 13)
**Source:** `Modules/Core/Public/Services/UserDataPathService.php`; enforced by
`noStoragePathHardCodedOutsideUserDataPathService` (`BoundaryArchTest.php` lines 1081-1149).
**Apply to:** the first-launch DB bootstrap (D-21/D-23) and any Desktop file I/O.
- Never call `database_path()` / `storage_path()` / `base_path()` in module code —
  inject `UserDataPathService`. The arch test fails loudly on raw helpers.

### system_alerts write (worker-health, D-07)
**Source:** `Modules/EmailScan/Internal/Listeners/EmitOAuthReauthRequiredAlert.php`;
table from `Modules/Core/Database/Migrations/..._create_system_alerts_table.php`.
**Apply to:** `SurfaceWorkerCrashAlert`.
- `severity` is constrained by a SQLite trigger to `'info'|'warning'|'critical'` —
  worker crash uses `'critical'`. `kind` is free-form. Always de-dup on
  `(user_id, kind, acknowledged_at IS NULL)` before inserting.
- Worker alerts are likely system-wide (`user_id` nullable — the column docblock
  covers this); a crashed background process is not user-scoped.

### Layout dark-class wiring (D-15/D-16)
**Source:** `resources/views/layouts/app.blade.php` (the single app layout).
**Apply to:** the layout `<html>` / `<body>` tags.
- Current tags hard-code light: `<html class="bg-white text-slate-900">` and
  `<body class="antialiased bg-white text-slate-900">`.
- The dark retrofit toggles a `dark` class on `<html>` from the user `theme` column;
  for `theme=system`, an inline `<head>` script reads `prefers-color-scheme` before
  first paint (UI-SPEC — prevents the flash). Every module's Blade view then gains
  `dark:` companions per the UI-SPEC token table (`bg-white`→`dark:bg-slate-950`,
  `text-slate-900`→`dark:text-slate-100`, caption `text-slate-500`→`dark:text-slate-400`).
- The OS theme signal feeds `system` resolution via a Desktop-module service that
  wraps `Native\Desktop\Facades\System::theme()` — the read is quarantined inside
  `Modules/Desktop/` (anti-pattern in RESEARCH.md: even the boot screen's theme read
  goes through a Desktop-module service).

### Livewire component registration + stateless screens
**Source:** `Modules/Core/Providers/CoreServiceProvider.php` lines 73-76;
`Modules/Core/Internal/Http/Livewire/SystemAlertsBanner.php`.
**Apply to:** the four Desktop Livewire screens.
- Register via `$livewire->component('desktop.setup-screen', SetupScreen::class)` in
  `DesktopServiceProvider::boot()`.
- Never constructor-inject on a `Component` subclass — method-parameter DI on
  `render()` / actions. `CloseWindowPrompt` uses `flux:modal` (UI-SPEC) — Flux UI 2 is
  already a `composer.json` dependency; the modal markup follows existing
  `flux:modal` usage in the Categorization/Receipts toast components.

## No Analog Found

| File | Role | Data Flow | Reason |
|------|------|-----------|--------|
| `Modules/Desktop/Internal/Native/AppMenuBuilder.php` | utility (native-chrome) | request-response | No NativePHP code exists in the repo yet. Follow RESEARCH.md Pattern 1 + Code Examples (`Menu::create(Menu::app(), Menu::file(), ...)`). The App-menu additions (D-11) and tray menu (D-09) are pure NativePHP facade composition — greenfield. |
| `Modules/Desktop/Internal/Native/TrayMenuBuilder.php` | utility (native-chrome) | request-response | Same — `MenuBar::create()->icon(...)->withContextMenu(...)` per RESEARCH.md. The monochrome template-image tray icon (D-19) is an asset concern, not a code analog. |

> The published Electron project (`native:install --publish`) — the `main.js`
> file-association handlers, single-instance lock, `electron-builder` config, and the
> macOS entitlements `.plist` (PKG-08) — are JavaScript / plist artifacts with **no PHP
> analog at all**. They are the RESEARCH.md-flagged spike (`.eml`/`.csv` cross-OS
> file-association). The planner should treat that wave as self-contained per RESEARCH.md.

## Metadata

**Analog search scope:** `Modules/` (all 13 modules), `tests/Contracts/`,
`bootstrap/`, `routes/`, `resources/css/`, `resources/views/layouts/`.
**Files scanned:** ~30 (service providers, Public events, migrations, Livewire
components, route files, listeners, arch tests, the scheduler file, the app layout).
**Pattern extraction date:** 2026-05-22
</content>
</invoke>
