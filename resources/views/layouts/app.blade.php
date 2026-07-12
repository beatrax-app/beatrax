@inject('currentUser', \Modules\Core\Public\Contracts\CurrentUser::class)
@inject('container', \Illuminate\Contracts\Container\Container::class)
@php
    /*
     * Dark-theme resolution.
     *
     * `theme` is the authenticated user's appearance preference
     * (light / dark / system); guests fall to `system`. For an explicit
     * light/dark choice the `dark` class is decided here, server-side.
     * For `system` the class is left to the pre-paint script below so
     * there is no flash of the wrong theme — the script runs before
     * first paint and reads `prefers-color-scheme`. When running inside
     * the desktop bundle the OsThemeSignal binding lets `system` resolve
     * server-side too; the script still corrects it if it disagrees.
     *
     * `$osTheme` is the resolved OsThemeSignal value: `'light'` /
     * `'dark'` for an explicit OS preference, or `null` when the OS
     * has no explicit choice (or the binding is absent). The pre-paint
     * script below runs whenever the resolution is `null` so the
     * client-side `prefers-color-scheme` read is the authoritative
     * source in that branch.
     *
     * Authentication state and container lookups go through @inject'd
     * contracts so the layout honours the project's DI-only rule (no
     * auth() / app() / config() global helpers in non-test code). The
     * dev-shell layout uses the same pattern.
     */
    $userTheme = $currentUser->isAuthenticated() ? ($currentUser->user()->theme ?? 'system') : 'system';

    $osTheme = null;
    if ($userTheme === 'system' && $container->bound(\Modules\Desktop\Public\Contracts\OsThemeSignal::class)) {
        $osTheme = $container->make(\Modules\Desktop\Public\Contracts\OsThemeSignal::class)->currentOsTheme();
    }

    $isDark = $userTheme === 'dark' || ($userTheme === 'system' && $osTheme === 'dark');
    // The pre-paint script runs for every system-themed render unless
    // the OS has already given us an explicit light/dark answer. A null
    // $osTheme — bundle says "no explicit preference" or local dev has no
    // binding — falls through to the script's matchMedia read.
    $needsPrePaintScript = $userTheme === 'system' && $osTheme === null;
@endphp
<!doctype html>
<html
    lang="en"
    class="bg-white text-slate-900 dark:bg-slate-950 dark:text-slate-100 {{ $isDark ? 'dark' : '' }}"
    style="font-feature-settings: 'tnum';"
>
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
        {{--
            PWA head block (D-14/D-18/D-21, PWA-01/02).
            - Manifest link: tells browsers/OS install affordance where to find
              the app name, icons, and display mode (standalone).
            - Dual theme-color: light #f8fafc and dark #020617 so the browser
              chrome (address bar, status bar) matches the app palette in both
              colour schemes (UI-SPEC §13).
            - apple-touch-icon: iOS Safari uses this for the home-screen icon
              when the user chooses "Add to Home Screen". Must be opaque
              (180×180 with #f8fafc background) — iOS ignores transparency.
        --}}
        <link rel="manifest" href="/site.webmanifest" />
        <meta name="theme-color" content="#f8fafc" media="(prefers-color-scheme: light)" />
        <meta name="theme-color" content="#020617" media="(prefers-color-scheme: dark)" />
        <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png" />
        <meta name="csrf-token" content="{{ csrf_token() }}" />
        <title>{{ $title ?? 'beatrax' }}</title>
        @if ($needsPrePaintScript)
            {{--
                Pre-paint theme script. Runs synchronously in <head>
                before the body renders, so a `system`-theme user never
                sees a flash of the wrong theme. Reads the OS-level
                `prefers-color-scheme` media query — a fixed, app-authored
                snippet with no dynamic interpolation.

                Emitted whenever the OsThemeSignal returned null (no
                explicit OS preference, or the desktop binding is
                absent in local dev) — the script's `matchMedia` read is
                the authoritative source in that case. When the bundle
                resolved an explicit `light` / `dark`, the server-side
                `dark` class is already correct and the script would
                only undo / re-do the same answer.
            --}}
            <script>
                (function () {
                    try {
                        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                            document.documentElement.classList.add('dark');
                        } else {
                            document.documentElement.classList.remove('dark');
                        }
                    } catch (e) {}
                })();
            </script>
        @endif
        @vite(['resources/css/app.css', 'resources/js/app.js'])
        @livewireStyles
        @fluxAppearance
    </head>
    <body
        class="antialiased bg-white text-slate-900 dark:bg-slate-950 dark:text-slate-100"
        style="font-family: 'Inter', system-ui, -apple-system, sans-serif;"
        x-data="{
            onKey(e) {
                /*
                 * Global command-palette keybind handler (D-42, 16-08).
                 *
                 *   - Cmd+K / Ctrl+K → dispatch 'palette:open' (the
                 *     CommandPaletteModal Livewire component listens
                 *     and pops the Flux modal).
                 *   - Cmd+. / Ctrl+. → jump to /dev (opens the Dev
                 *     Console; non-developers receive 404 from
                 *     EnsureDeveloperMode).
                 *
                 * I-7 fix: do NOT steal keystrokes when focus is inside
                 * a text field. Without this carve-out a developer
                 * typing 'k' inside a search input while holding Cmd
                 * (e.g. Cmd+Left / Cmd+Right to navigate words on macOS, then a
                 * 'k') would have the palette open over their input.
                 * The standard browser bindings inside INPUT /
                 * TEXTAREA / contentEditable stay primary.
                 *
                 * Raw U+2318 glyphs are banned from this template: x-data
                 * comments render into the HTML attribute and trip
                 * AppSidebarKbdTest's not-contains-glyph assertion (D-04).
                 */
                const t = document.activeElement;
                if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.isContentEditable)) {
                    return;
                }
                const mod = e.metaKey || e.ctrlKey;
                if (mod && (e.key === 'k' || e.key === 'K')) {
                    e.preventDefault();
                    if (window.Livewire) {
                        window.Livewire.dispatch('palette:open');
                    }
                    return;
                }
                if (mod && e.key === '.') {
                    e.preventDefault();
                    window.location.href = '/dev';
                }
            }
        }"
        x-on:keydown.window="onKey($event)"
    >
        @auth
            {{-- max-lg:flex-col — below 1024px the top bar must stack ABOVE main,
                 not sit beside it as a flex-row column (the drawer is fixed and
                 out of flow at phone width, so column order is top-bar → main). --}}
            <div class="flex max-lg:flex-col min-h-screen">
                {{--
                    Drawer wrapper (D-01/D-03, Phase 4 Plan 03).
                    The sidebar is mounted exactly ONCE inside the drawer component.
                    At >=1024px: .drawer-container is position:static — desktop static sidebar.
                    At <1024px: slides in as a focus-trapped overlay from the left.
                    The original @livewire('core.app-sidebar') call is now inside <x-core::drawer>.
                --}}
                <x-core::drawer />
                {{--
                    Mobile top bar (D-01/D-02/D-05, Phase 4 Plan 03).
                    CSS-hidden at >=1024px — desktop layout is unchanged.
                    Inserts before <main> so it stacks above the main content column on mobile.
                --}}
                <x-core::mobile-top-bar class="top-bar-global" />
                <main class="flex-1 min-w-0 overflow-auto">
                    @livewire('core.system-alerts-banner')
                    @livewire('categorization.rule-form-modal')
                    @livewire('categorization.correction-divergence-toast')
                    @livewire('receipts.receipt-conflict-toast')
                    @livewire('community.suggest-mapping-modal')
                    @livewire('email-scan.oauth-client-wizard-modal')
                    @yield('content')
                </main>
            </div>
            {{--
                Global command-palette modal (16-08, DEVUI-09). Mounted
                once for the entire authenticated session; the body-level
                Alpine keybind handler above dispatches `palette:open`
                which the modal listens to. Server-side JSON registry
                merges nav + dev (SAFE only, devs only) + actions; dev
                rows are filtered server-side so non-developers never
                see the labels.
            --}}
            @livewire('dev.command-palette-modal')
            {{-- Search palette endpoint — provides server-backed transaction + entity
                 hits to the ⌘K palette via $wire.search(q) from palette.js.
                 Mounted alongside the palette modal so the JS can reach $wire on
                 every authenticated page (Plan 08-05, SRCH-02). --}}
            @livewire('search.palette-search-endpoint')
            {{-- Arg-prompt modal for SAFE-tier commands with args
                 (config:show, beatrax:reset-password, etc.). The
                 palette dispatches `command-args:prompt` when the
                 picked command's CommandSpec carries argsSchema;
                 the form submits as `spawn-command` so the runner
                 page's onSpawnCommand listener fires the actual
                 spawn.  --}}
            @livewire('dev.command-arg-prompt-modal')

            {{-- Global toast stack — see Modules/DevMode/Resources/views/layouts/dev-shell.blade.php
                 for the full docblock. Mounted in both layouts so the
                 same $this->dispatch('toast', message: '...') reaches
                 a visible surface no matter which layout the current
                 request resolved through. --}}
            <div
                x-data="{
                    toasts: [],
                    push(detail) {
                        const id = Date.now() + Math.random();
                        this.toasts.push({ id, message: (detail && detail.message) || '' });
                        setTimeout(() => { this.toasts = this.toasts.filter(t => t.id !== id); }, 5000);
                    },
                    dismiss(id) { this.toasts = this.toasts.filter(t => t.id !== id); },
                }"
                x-on:toast.window="push($event.detail)"
                class="pointer-events-none fixed bottom-4 right-4 z-[10000] flex w-[min(380px,calc(100vw-2rem))] flex-col-reverse gap-2"
                role="status"
                aria-live="polite"
                data-testid="toast-host"
            >
                <template x-for="t in toasts" :key="t.id">
                    <div
                        x-on:click="dismiss(t.id)"
                        class="pointer-events-auto cursor-pointer rounded-md bg-slate-900 px-4 py-3 text-sm text-white shadow-lg ring-1 ring-slate-800 dark:bg-slate-100 dark:text-slate-900 dark:ring-slate-300"
                        role="alert"
                        data-testid="toast"
                        x-text="t.message"
                    ></div>
                </template>
            </div>
            {{--
                Privacy veil (plan 05-03, D-07, T-05-11).
                Drops synchronously on window background/blur (lock.js) to hide
                financial data before OS screenshots. Starts opacity-0 /
                pointer-events-none; lock.js flips classes on visibilitychange or
                blur. 80ms CSS transition (motion-reduce:duration-0 for instant on
                reduced-motion devices). Role/aria-modal/aria-label managed by JS.
            --}}
            <div
                id="beatrax-veil"
                class="fixed inset-0 z-[9999] flex items-center justify-center
                       bg-white dark:bg-slate-950
                       opacity-0 pointer-events-none
                       transition-opacity duration-[80ms] motion-reduce:duration-0"
                aria-hidden="true"
            >
                <img
                    src="/icon.png"
                    width="48"
                    height="48"
                    alt=""
                    class="rounded-xl opacity-40"
                    aria-hidden="true"
                />
            </div>
            {{--
                Idle-timeout injection (plan 05-03, D-17, T-05-13).
                Emits window.beatraxIdleMs (milliseconds) ONLY when the app lock
                is enabled for the current user, using their configured
                idle_timeout_minutes preset (1/5/15/30 — plan 05-04). lock.js
                reads this value to calibrate the idle watcher; when the
                variable is absent, lock.js no-ops the idle tracker and never
                shows the veil — users without a lock must not be idle-locked
                behind a veil they cannot dismiss. Resolved through the Auth
                Public AppLockClientConfig service (module-boundary rule: the
                layout never queries user_app_lock_configs directly).
            --}}
            @php($beatraxIdleMs = $currentUser->isAuthenticated()
                ? $container->make(\Modules\Auth\Public\Services\AppLockClientConfig::class)->idleTimeoutMs($currentUser->user()->id)
                : null)
            @if ($beatraxIdleMs !== null)
                <script>window.beatraxIdleMs = {{ $beatraxIdleMs }};</script>
            @endif
        @endauth
        @guest
            @yield('content')
        @endguest
        @livewireScripts
        @fluxScripts
        @auth
            {{--
                PWA service-worker registration (D-17/D-18/D-19, PWA-03).
                Registered on every authenticated page load — one code path
                for both the web browser and the NativePHP desktop shell
                (D-19). The feature check (`'serviceWorker' in navigator`)
                guards against environments that do not implement the SW API
                (legacy browsers, certain WebView contexts). Deferring to the
                `load` event ensures the SW registration does not block the
                initial render or compete with critical resource fetches.
            --}}
            <script>
                if ('serviceWorker' in navigator) {
                    window.addEventListener('load', function () {
                        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () {
                            // SW registration failed — app continues to work without offline support.
                        });
                    });
                }
            </script>
            @if (Route::has('desktop.close-action'))
                {{--
                    D-08 close-window JS glue (plan 15-04). The
                    CloseWindowPrompt Livewire component dispatches
                    `close-window-choice` (`choice: 'quit' | 'tray'`) after
                    the user picks an action. This hook listens for the
                    Livewire-emitted browser event and POSTs the choice
                    to the `desktop.close-action` endpoint, which calls
                    `App::quit()` / `Window::current()->hide()` inside the
                    bundle. Outside the bundle the POST is harmless — the
                    facades only have any effect when NativePHP is
                    running.

                    `Route::has()`-guarded (15-11-PLAN.md Task 2 fix-forward,
                    T-15-34): the Mobile module's own `mobile-app/` root does
                    not load `Modules\Desktop` (15-TOPOLOGY-SPIKE-FINDINGS.md
                    — Desktop has no `module.json` and is dropped from the
                    mobile shell's `bootstrap/providers.php` manifest), so
                    the named route does not exist there. Every authenticated
                    page shares this layout, so an unguarded `route()` call
                    here previously 500'd every mobile surface —
                    MobileSurfaceParityTest run from `mobile-app/` caught it.
                --}}
                <script>
                    (function () {
                        if (typeof window === 'undefined' || typeof document === 'undefined') {
                            return;
                        }
                        var token = document.querySelector('meta[name="csrf-token"]')?.content;
                        if (! token) {
                            return;
                        }
                        window.addEventListener('close-window-choice', function (event) {
                            try {
                                var choice = event?.detail?.choice;
                                if (choice !== 'quit' && choice !== 'tray') {
                                    return;
                                }
                                fetch('{{ route('desktop.close-action') }}', {
                                    method: 'POST',
                                    credentials: 'same-origin',
                                    headers: {
                                        'Accept': 'application/json',
                                        'Content-Type': 'application/json',
                                        'X-CSRF-TOKEN': token,
                                    },
                                    body: JSON.stringify({ choice: choice }),
                                });
                            } catch (e) {}
                        });
                    })();
                </script>
            @endif
        @endauth
    </body>
</html>
