@use('Modules\Core\Public\Support\Lang')
@use('Modules\Ledger\Public\Services\BaseCurrency')
@inject('currentUser', \Modules\Core\Public\Contracts\CurrentUser::class)
@inject('container', \Illuminate\Contracts\Container\Container::class)
@php
    // The <html> dark class and lang attribute are resolved once in
    // AppChromeResolver (dark from the user's theme + desktop OsThemeSignal,
    // lang from the SetLocale-primed translator) so all four page shells
    // derive them the same way rather than repeating the block inline.
    $chrome = $container->make(\Modules\Core\Public\Support\AppChromeResolver::class)->resolve();

    // Whether this page may draw the menubar and the search affordance at all.
    // Signed in is not the same as having an application to navigate: signup,
    // the recovery-code hand-over, the migration splash and the phone's
    // provisioning all happen signed in, and this block used to ask only
    // @auth. The whole shell hung off that one answer, so those screens
    // offered twenty-five destinations and a search box before there was
    // anything behind them — and, on the one screen the recovery codes are
    // ever shown, a way off it that loses them for good.
    $appShell = $container->make(\Modules\Core\Public\Support\AppShellVisibility::class)->visible();

    // Chart chrome the ApexCharts helpers in app.js read off <html>: the
    // money axis needs the base currency, and ApexCharts names its own
    // <svg> in English ("donut chart with 14 data series") unless told.
    // A guest has no reporting currency to draw an axis in, and asking for
    // one is refused, so the login shell takes what the install ships with.
    $baseCurrency = $container->make(BaseCurrency::class);
    $chartCurrency = $currentUser->isAuthenticated()
        ? $baseCurrency->code()
        : $baseCurrency->installDefault();

    $chartLabels = json_encode([
        'donut' => Lang::get('core::components.chart.donut'),
        'bar' => Lang::get('core::components.chart.bar'),
        'line' => Lang::get('core::components.chart.line'),
        'rangeArea' => Lang::get('core::components.chart.range_area'),
    ], JSON_THROW_ON_ERROR);
@endphp
<!doctype html>
<html
    lang="{{ $chrome->locale }}"
    data-base-currency="{{ $chartCurrency }}"
    data-chart-labels="{{ $chartLabels }}"
    class="bg-white text-slate-900 dark:bg-slate-950 dark:text-slate-100 {{ $chrome->rootThemeClass() }}"
    {{-- Only when the server already KNOWS the theme. An inline style beats
         every stylesheet rule, so emitting it while the pre-paint script is
         authoritative pinned a dark-OS phone to a white <html> and the page
         flashed light against its own dark content. --}}
    @if (! $chrome->needsPrePaintScript)
        style="font-feature-settings: 'tnum'; background-color: {{ $chrome->isDark ? '#020617' : '#ffffff' }};"
    @else
        style="font-feature-settings: 'tnum';"
    @endif
>
    <head>
        <meta charset="utf-8" />
        {{-- interactive-widget=resizes-content, not the `resizes-visual`
             default: with the default the soft keyboard offsets the VISUAL
             viewport and leaves the layout viewport where it was, so every
             fixed and sticky element stays anchored off-screen. Measured on a
             Galaxy S24 Ultra at 384px: tapping a budget assign box put
             visualViewport.offsetTop at 303 with .top-bar still at layout y 0,
             and the page drew its own content through the status bar. --}}
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, interactive-widget=resizes-content" />
        <x-core::pwa-head />
        <meta name="csrf-token" content="{{ csrf_token() }}" />

        {{-- Set only by the Mobile provider, which also registers the
             middleware that decodes it, so the two cannot disagree about
             which runtime encodes its uploads. --}}
        @if (($beatraxEncodedUploads ?? false) === true)
            <meta name="beatrax-upload-transport" content="base64" />
        @endif
        <title>{{ $title ?? 'Beatrax' }}</title>
        <x-core::theme-prepaint :enabled="$chrome->needsPrePaintScript" />
        <x-core::head-assets :chrome="$chrome" />
    </head>
    <body
        class="antialiased bg-white text-slate-900 dark:bg-slate-950 dark:text-slate-100"
        style="font-family: system-ui, -apple-system, sans-serif;"
        x-data="{
            onKey(e) {
                /*
                 * Global command-palette keybind handler.
                 *
                 *   - Cmd+K / Ctrl+K → dispatch 'palette:open' (the
                 *     CommandPaletteModal Livewire component listens
                 *     and pops the Flux modal).
                 *   - Cmd+. / Ctrl+. → jump to /dev (opens the Dev
                 *     Console; non-developers receive 404 from
                 *     EnsureDeveloperMode).
                 *
                 * Do NOT steal keystrokes when focus is inside
                 * a text field. Without this carve-out a developer
                 * typing 'k' inside a search input while holding Cmd
                 * (e.g. Cmd+Left / Cmd+Right to navigate words on macOS, then a
                 * 'k') would have the palette open over their input.
                 * The standard browser bindings inside INPUT /
                 * TEXTAREA / contentEditable stay primary.
                 *
                 * Raw U+2318 glyphs are banned from this template: x-data
                 * comments render into the HTML attribute and trip
                 * AppSidebarKbdTest's not-contains-glyph assertion.
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
        {{-- Bound only where the palette it opens is mounted. Left bound on a
             pre-setup screen, Cmd+K dispatched into nothing and Cmd+. still
             navigated to /dev — a keyboard way out of a ceremony whose visible
             ways out had just been taken away. --}}
        @if ($appShell)
            x-on:keydown.window="onKey($event)"
        @endif
    >
        @auth
            {{-- max-lg:flex-col — below 1024px the top bar must stack ABOVE main,
                 not sit beside it as a flex-row column (the drawer is fixed and
                 out of flow at phone width, so column order is top-bar → main). --}}
            <div class="flex max-lg:flex-col min-h-screen">
                {{--
                    The menubar, in its two forms — the drawer that is the static
                    sidebar from 1024px up, and the top bar below it. Both are
                    drawn or neither is: a page that kept one of the pair still
                    offered the app at the width that drew it, and the width that
                    did not was the only one anybody looked at.
                --}}
                @if ($appShell)
                    {{--
                        Drawer wrapper.
                        The sidebar is mounted exactly ONCE inside the drawer component.
                        At >=1024px: .drawer-container is position:static — desktop static sidebar.
                        At <1024px: slides in as a focus-trapped overlay from the left.
                        The original @livewire('core.app-sidebar') call is now inside <x-shell::drawer>.
                    --}}
                    <x-shell::drawer />
                    {{--
                        Mobile top bar.
                        CSS-hidden at >=1024px — desktop layout is unchanged.
                        Inserts before <main> so it stacks above the main content column on mobile.
                    --}}
                    {{-- The palette covers the top bar and carries its own close
                         control, so it may go inert too. The DRAWER must never
                         inert it: the scrim is aria-hidden, so the hamburger is a
                         screen reader's only way back out. --}}
                    <x-core::mobile-top-bar class="top-bar-global" x-bind:inert="$store.overlay.has('palette') || null" />
                @endif
                {{--
                    inert while any overlay covers the page. Both the drawer
                    and the command palette are role=dialog aria-modal=true, and
                    nothing made the page behind them unreachable: read off an
                    iPhone 12 mini with VoiceOver's own tree, the open drawer
                    still exposed "August 2026", "This period totals" and the
                    €3,202.14 behind it. Controls left focusable outside the
                    dialog — drawer 15, palette 97 (the whole ledger).

                    One attribute on one element, which is what x-shell::drawer
                    asks for — x-trap.inert walked the document marking siblings
                    inert and killed the Android renderer.

                    `|| null` is load-bearing: inert is a BOOLEAN attribute, so
                    inert="false" is still inert. Measured in WebKit — "", "true"
                    and "false" all give 0 focusable descendants. null is the
                    only value that removes it.

                    The top bar stays reachable on purpose: the scrim is
                    aria-hidden, so the hamburger is a screen reader's only way
                    back out of the drawer.
                --}}
                <main class="flex-1 min-w-0 overflow-auto" x-bind:inert="$store.overlay.blocking || null">
                    {{-- The application's own machinery, mounted beside every
                         page that is part of the application. A wire:snapshot is
                         a bearer token for the component it names, so mounting
                         these on a screen the reader has not reached the app
                         through handed out five endpoints the screen has no use
                         for — and on the migration splash, four of them query
                         tables the pending migrations have not created yet. A
                         first-run screen that needs one mounts it itself, which
                         is what the wizard shell does with the OAuth modal. --}}
                    @if ($appShell)
                        @livewire('core.system-alerts-banner')
                        @livewire('categorization.rule-form-modal')
                        @livewire('receipts.receipt-conflict-toast')
                        @livewire('community.suggest-mapping-modal')
                        @livewire('email-scan.oauth-client-wizard-modal')

                        {{-- Phone only, and drawing nothing. The operating
                             system refuses every notification until it has
                             been asked, and the shipped defaults have two
                             triggers on, so the ask has to sit on a page
                             every reader reaches rather than behind the
                             settings form they may never open. --}}
                        @if (\Modules\Core\Public\Services\UserDataPathService::isMobileRuntime())
                            @livewire('mobile.notification-permission')
                        @endif
                    @endif
                    @yield('content')
                </main>
            </div>
            {{-- The search affordance, whichever way it is reached. The sidebar
                 box, the top bar's magnifier and the keybind all dispatch
                 palette:open, so the palette IS the search on every surface —
                 which is why a pre-setup screen drops the three of them
                 together rather than hiding the two it can see. --}}
            @if ($appShell)
                {{--
                    Global command-palette modal. Mounted
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
                     every authenticated page. --}}
                @livewire('search.palette-search-endpoint')
                {{-- Arg-prompt modal for SAFE-tier commands with args
                     (config:show, beatrax:reset-password, etc.). The
                     palette dispatches `command-args:prompt` when the
                     picked command's CommandSpec carries argsSchema;
                     the form submits as `spawn-command` so the runner
                     page's onSpawnCommand listener fires the actual
                     spawn.  --}}
                @if (auth()->check() && auth()->user()->is_developer === true)
                    @livewire('dev.command-arg-prompt-modal')
                @endif
            @endif

            <x-core::toast-host />
            {{--
                Idle-timeout injection.
                Emits window.beatraxIdleMs (milliseconds) ONLY when the app lock
                is enabled for the current user, using their configured
                idle_timeout_minutes preset (1/5/15/30). lock.js
                reads this value to calibrate the idle watcher; when the
                variable is absent, lock.js no-ops the idle tracker and never
                shows the veil — users without a lock must not be idle-locked
                behind a veil they cannot dismiss. Resolved through the Auth
                Public AppLockClientConfig service (module-boundary rule: the
                layout never queries user_app_lock_configs directly).
            --}}
            @php($beatraxAppLock = $container->make(\Modules\Auth\Public\Services\AppLockClientConfig::class))
            @php($beatraxIdleMs = $currentUser->isAuthenticated()
                ? $beatraxAppLock->idleTimeoutMs($currentUser->user()->id)
                : null)
            @if ($beatraxIdleMs !== null)
                {{-- The lock URL travels with the timeout: lock.js used to
                     navigate to a hardcoded /lock, which is the DESKTOP screen.
                     On a phone that rendered the wrong lock surface first and
                     only corrected itself a moment later. The server already
                     knows which route applies, so it says so. --}}
                @php($beatraxLockUrl = \Modules\Core\Public\Services\UserDataPathService::isMobileRuntime()
                    && $container->make(\Illuminate\Routing\Router::class)->has('mobile.lock')
                        ? route('mobile.lock')
                        : route('auth.lock'))
                {{-- The grace window leaving the foreground locks on travels
                     the same way, and for the same reason the lock URL does:
                     the server owns it. It is the window the app-lock settings
                     copy discloses, so a second spelling in lock.js would be a
                     number the screen could start lying about. --}}
                <script nonce="{{ Vite::cspNonce() }}">
                    window.beatraxIdleMs = {{ $beatraxIdleMs }};
                    window.beatraxGraceMs = {{ $beatraxAppLock->backgroundGraceMs() }};
                    window.beatraxLockUrl = @js($beatraxLockUrl);
                </script>
            @endif
        @endauth
        @guest
            @yield('content')
        @endguest
        @livewireScripts
        @fluxScripts
        @auth
            {{--
                PWA service-worker registration.
                Registered on every authenticated page load — one code path
                for both the web browser and the NativePHP desktop shell.
                The feature check (`'serviceWorker' in navigator`)
                guards against environments that do not implement the SW API
                (legacy browsers, certain WebView contexts). Deferring to the
                `load` event ensures the SW registration does not block the
                initial render or compete with critical resource fetches.
            --}}
            @unless (\Modules\Core\Public\Services\UserDataPathService::isMobileRuntime())
                {{-- Not in the mobile shells. A service-worker script fetch does
                     not pass through the WebView's request interceptor, so it
                     escapes to a real network that has nothing on 127.0.0.1 and
                     fails on every single page load. There is no offline story
                     to lose: the app IS local there. --}}
                <script nonce="{{ Vite::cspNonce() }}">
                    if ('serviceWorker' in navigator) {
                        window.addEventListener('load', function () {
                            navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(function () {
                                // SW registration failed — app continues to work without offline support.
                            });
                        });
                    }
                </script>
            @endunless
            @if (Route::has('desktop.close-action'))
                {{--
                    Close-window JS glue. The
                    CloseWindowPrompt Livewire component dispatches
                    `close-window-choice` (`choice: 'quit' | 'tray'`) after
                    the user picks an action. This hook listens for the
                    Livewire-emitted browser event and POSTs the choice
                    to the `desktop.close-action` endpoint, which calls
                    `App::quit()` / `Window::current()->hide()` inside the
                    bundle. Outside the bundle the POST is harmless — the
                    facades only have any effect when NativePHP is
                    running.

                    `Route::has()`-guarded: the Mobile module's own `mobile-app/` root does
                    not load `Modules\Desktop` (15-TOPOLOGY-SPIKE-FINDINGS.md
                    — Desktop has no `module.json` and is dropped from the
                    mobile shell's `bootstrap/providers.php` manifest), so
                    the named route does not exist there. Every authenticated
                    page shares this layout, so an unguarded `route()` call
                    here previously 500'd every mobile surface —
                    MobileSurfaceParityTest run from `mobile-app/` caught it.
                --}}
                <script nonce="{{ Vite::cspNonce() }}">
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
        {{-- Last child of <body> ON PURPOSE. Nested inside the page wrapper
             it sat in a stacking context of its own, so Flux modals — which
             portal to the body root — painted straight over it and the QR
             stayed readable through the privacy veil. --}}
        {{--
            Privacy veil.
            Drops synchronously on window background/blur (lock.js) to hide
            financial data before OS screenshots. Starts opacity-0 /
            pointer-events-none; lock.js flips classes on visibilitychange or
            blur. 80ms CSS transition (motion-reduce:duration-0 for instant on
            reduced-motion devices). Role/aria-modal/aria-label managed by JS.
        --}}
        <div
            id="beatrax-veil"
            {{-- Opened with showPopover() so the veil sits in the browser's
                 top layer. A z-index cannot beat a dialog that opens its own
                 top-layer entry, which is why modals kept showing through. --}}
            popover="manual"
            {{-- Background comes from CSS, not `bg-white dark:bg-slate-950`:
                 the dark variant needs the `dark` class to already be on
                 <html>, so the veil painted WHITE for a frame on a dark
                 device — a flash of the exact colour it exists to hide. --}}
            class="beatrax-veil fixed inset-0 z-[9999] flex items-center justify-center
                   opacity-0 pointer-events-none
                   transition-opacity duration-[80ms] motion-reduce:duration-0"
            aria-hidden="true"
            {{-- lock.js promotes the veil to role="dialog" when it raises it,
                 and a dialog needs an accessible name. The name is authored
                 here rather than in the script so it localises. --}}
            data-locked-label="{{ Lang::get('core::components.veil_locked') }}"
        >
            <x-core::app-mark alt="" class="rounded-xl opacity-40" />
        </div>
    </body>
</html>
