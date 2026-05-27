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
    // $osTheme — bundle says "no explicit preference" or Herd has no
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
        <meta name="viewport" content="width=device-width, initial-scale=1" />
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
                absent under Herd) — the script's `matchMedia` read is
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
                 *   - ⌘K / Ctrl+K → dispatch 'palette:open' (the
                 *     CommandPaletteModal Livewire component listens
                 *     and pops the Flux modal).
                 *   - ⌘. / Ctrl+. → jump to /dev (opens the Dev
                 *     Console; non-developers receive 404 from
                 *     EnsureDeveloperMode).
                 *
                 * I-7 fix: do NOT steal keystrokes when focus is inside
                 * a text field. Without this carve-out a developer
                 * typing 'k' inside a search input while holding ⌘
                 * (e.g. ⌘← / ⌘→ to navigate words on macOS, then a
                 * 'k') would have the palette open over their input.
                 * The standard browser bindings inside INPUT /
                 * TEXTAREA / contentEditable stay primary.
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
            <div class="flex min-h-screen">
                @livewire('core.app-sidebar')
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
            {{-- Arg-prompt modal for SAFE-tier commands with args
                 (config:show, beatrax:reset-password, etc.). The
                 palette dispatches `command-args:prompt` when the
                 picked command's CommandSpec carries argsSchema;
                 the form submits as `spawn-command` so the runner
                 page's onSpawnCommand listener fires the actual
                 spawn.  --}}
            @livewire('dev.command-arg-prompt-modal')
        @endauth
        @guest
            @yield('content')
        @endguest
        @livewireScripts
        @fluxScripts
        @auth
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
        @endauth
    </body>
</html>
