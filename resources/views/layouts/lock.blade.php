@inject('currentUser', \Modules\Core\Public\Contracts\CurrentUser::class)
@inject('container', \Illuminate\Contracts\Container\Container::class)
@php
    /*
     * Minimal lock-screen layout — no sidebar, no top bar, no veil, no modals.
     *
     * UI-SPEC §1 (Lock Screen): "Full-viewport, no sidebar, no top bar.
     * Replaces the entire authenticated layout while locked." This layout
     * honours that requirement while keeping the same theme/PWA head block
     * as the full app layout.
     *
     * The veil and idle-tracker are intentionally omitted: the lock screen
     * IS the privacy gate; showing the veil on top of the lock screen is
     * redundant and confusing. Idle tracking on the lock screen is also
     * unnecessary — the user is already locked out.
     *
     * WebAuthn JS (lock.js) is still needed for the biometric button.
     */
    $userTheme = $currentUser->isAuthenticated() ? ($currentUser->user()->theme ?? 'system') : 'system';

    $osTheme = null;
    if ($userTheme === 'system' && $container->bound(\Modules\Desktop\Public\Contracts\OsThemeSignal::class)) {
        $osTheme = $container->make(\Modules\Desktop\Public\Contracts\OsThemeSignal::class)->currentOsTheme();
    }

    $isDark = $userTheme === 'dark' || ($userTheme === 'system' && $osTheme === 'dark');
    $needsPrePaintScript = $userTheme === 'system' && $osTheme === null;

    // Matches <html lang> to the language the SetLocale middleware resolved
    // for this request, same as the full app layout.
    $currentLocale = $container->make(\Illuminate\Contracts\Translation\Translator::class)->getLocale();
@endphp
<!doctype html>
<html
    lang="{{ $currentLocale }}"
    class="bg-white text-slate-900 dark:bg-slate-950 dark:text-slate-100 {{ $isDark ? 'dark' : '' }}"
    style="font-feature-settings: 'tnum';"
>
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
        <link rel="manifest" href="/site.webmanifest" />
        <meta name="theme-color" content="#f8fafc" media="(prefers-color-scheme: light)" />
        <meta name="theme-color" content="#020617" media="(prefers-color-scheme: dark)" />
        <link rel="apple-touch-icon" href="/icons/apple-touch-icon.png" />
        <meta name="csrf-token" content="{{ csrf_token() }}" />
        <title>{{ $title ?? 'beatrax' }}</title>
        @if ($needsPrePaintScript)
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
    >
        @yield('content')
        @livewireScripts
        @fluxScripts
    </body>
</html>
