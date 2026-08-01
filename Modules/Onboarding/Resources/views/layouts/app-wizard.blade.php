@use('Modules\Core\Public\Support\Lang')
@inject('currentUser', \Modules\Core\Public\Contracts\CurrentUser::class)
@inject('container', \Illuminate\Contracts\Container\Container::class)
@php
    /*
     * First-run setup wizard layout shell.
     *
     * Body-only chrome — deliberately omits the main app sidebar
     * (resources/views/layouts/app.blade.php's `.side` rail), the
     * SystemAlertsBanner, and the command-palette modal. A user mid-
     * wizard has no sidebar entries that are meaningful to them yet
     * and the calm setup chrome is the entire focal point of the
     * page.
     *
     * Theme resolution mirrors resources/views/layouts/app.blade.php
     * and Modules/DevMode/Resources/views/layouts/dev-shell.blade.php
     * verbatim so a `system`-theme user never sees a flash of the
     * wrong theme. User-theme + OsThemeSignal lookups go through
     * @inject'd contracts so the layout honours the DI-only rule
     * (no `auth()` / `app()` / `config()` global helpers).
     */
    $userTheme = $currentUser->isAuthenticated() ? ($currentUser->user()->theme ?? 'system') : 'system';

    $osTheme = null;
    if ($userTheme === 'system' && $container->bound(\Modules\Desktop\Public\Contracts\OsThemeSignal::class)) {
        $osTheme = $container->make(\Modules\Desktop\Public\Contracts\OsThemeSignal::class)->currentOsTheme();
    }

    $isDark = $userTheme === 'dark' || ($userTheme === 'system' && $osTheme === 'dark');
    $needsPrePaintScript = $userTheme === 'system' && $osTheme === null;

    // Matches <html lang> to the request's resolved UI language, same as
    // the full app layout.
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
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <meta name="csrf-token" content="{{ csrf_token() }}" />
        <title>{{ $title ?? Lang::get('onboarding::wizard.page_title').' · beatrax' }}</title>
        @if ($needsPrePaintScript)
            {{--
                Pre-paint theme script — duplicated verbatim from the
                main app + dev-shell layouts so a `system`-theme user
                never sees a flash of the wrong theme. Reads the OS
                `prefers-color-scheme` media query before <body> paints.
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
    >
        <div class="wiz-page">
            {{ $slot }}
        </div>
        @livewire('email-scan.oauth-client-wizard-modal')
        @livewireScripts
        @fluxScripts
    </body>
</html>
