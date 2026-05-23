@php
    /*
     * Dark-theme resolution (Phase 15 D-15 / D-16).
     *
     * `theme` is the authenticated user's appearance preference
     * (light / dark / system); guests fall to `system`. For an explicit
     * light/dark choice the `dark` class is decided here, server-side.
     * For `system` the class is left to the pre-paint script below so
     * there is no flash of the wrong theme — the script runs before
     * first paint and reads `prefers-color-scheme`. When running inside
     * the desktop bundle the OsThemeSignal binding lets `system` resolve
     * server-side too; the script still corrects it if it disagrees.
     */
    $userTheme = auth()->check() ? (auth()->user()->theme ?? 'system') : 'system';

    $osTheme = null;
    if ($userTheme === 'system' && app()->bound(\Modules\Desktop\Public\Contracts\OsThemeSignal::class)) {
        $osTheme = app(\Modules\Desktop\Public\Contracts\OsThemeSignal::class)->currentOsTheme();
    }

    $isDark = $userTheme === 'dark' || ($userTheme === 'system' && $osTheme === 'dark');
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
        <title>{{ $title ?? 'diederik' }}</title>
        @if ($userTheme === 'system')
            {{--
                Pre-paint theme script. Runs synchronously in <head>
                before the body renders, so a `system`-theme user never
                sees a flash of the wrong theme. Reads the OS-level
                `prefers-color-scheme` media query — a fixed, app-authored
                snippet with no dynamic interpolation.
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
        @vite(['resources/css/app.css'])
        @livewireStyles
    </head>
    <body class="antialiased bg-white text-slate-900 dark:bg-slate-950 dark:text-slate-100" style="font-family: 'Inter', system-ui, -apple-system, sans-serif;">
        @auth
            @isset($impersonatingPartnerUsername)
                @include('auth::partials.impersonation-banner', ['username' => $impersonatingPartnerUsername])
            @endisset
            @livewire('core.top-nav')
            @livewire('core.system-alerts-banner')
            @livewire('categorization.rule-form-modal')
            @livewire('categorization.correction-divergence-toast')
            @livewire('receipts.receipt-conflict-toast')
        @endauth
        @yield('content')
        @livewireScripts
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
