@inject('currentUser', \Modules\Core\Public\Contracts\CurrentUser::class)
@inject('container', \Illuminate\Contracts\Container\Container::class)
@php
    /*
     * Dev Console layout shell (Phase 16-03 D-04).
     *
     * Mirrors `resources/views/layouts/app.blade.php`'s head + theme
     * resolution + Livewire wiring, then swaps the main app sidebar
     * (.side, 248px) for the Dev Console sidebar (.dev-side, 220px) on
     * every /dev/* page. The hard swap (not nesting) is sketch-locked
     * per UI-SPEC § Dev Console sidebar.
     *
     * Theme resolution mirrors app.blade.php's intent — no flash of
     * wrong theme on `system` users — but routes the user lookup
     * through the CurrentUser contract (via @inject, which resolves
     * through the container) so the layout honours the project's
     * DI-only rule (no auth() / Auth:: facade calls inside Modules/*).
     * The OsThemeSignal optional binding is resolved through the
     * injected container, never through app() global.
     *
     * The sidebar's nav items render `/dev/{slug}` URLs; only `dev.overview`
     * is registered in this plan, so every other link gets the
     * `nav-disabled` class. Downstream plans (16-04 / 16-05 / 16-06 /
     * 16-07) register the routes and the disabled class drops off
     * automatically via the `Route::has(...)` check.
     */
    $userTheme = $currentUser->isAuthenticated() ? ($currentUser->user()->theme ?? 'system') : 'system';

    $osTheme = null;
    if ($userTheme === 'system' && $container->bound(\Modules\Desktop\Public\Contracts\OsThemeSignal::class)) {
        $osTheme = $container->make(\Modules\Desktop\Public\Contracts\OsThemeSignal::class)->currentOsTheme();
    }

    $isDark = $userTheme === 'dark' || ($userTheme === 'system' && $osTheme === 'dark');
    $needsPrePaintScript = $userTheme === 'system' && $osTheme === null;

    /*
     * Dev sidebar nav items. Each entry: label, href, icon, optional
     * route-name to gate the `nav-disabled` class on (a missing route
     * means the route is not yet registered — the plan that adds it
     * also removes the disabled affordance). The Horizon entry is
     * conditionally rendered downstream when the dev-mode env var is
     * on AND the Horizon package is present (D-38); for now it just
     * renders with `nav-disabled` until 16-06 wires its route.
     */
    $devNavItems = [
        ['label' => 'Overview', 'slug' => 'overview', 'icon' => '◆', 'route' => 'dev.overview'],
        ['label' => 'Artisan',  'slug' => 'artisan',  'icon' => '›_', 'route' => 'dev.artisan'],
        ['label' => 'Audit',    'slug' => 'audit',    'icon' => '⌗',  'route' => 'dev.audit'],
        ['label' => 'Logs',     'slug' => 'logs',     'icon' => '≡',  'route' => 'dev.logs'],
        ['label' => 'Queue',    'slug' => 'queue',    'icon' => '↻',  'route' => 'dev.queue'],
        ['label' => 'Doctor',   'slug' => 'doctor',   'icon' => '⚙',  'route' => 'dev.doctor'],
        ['label' => 'SQL',      'slug' => 'sql',      'icon' => '⌕',  'route' => 'dev.sql'],
        ['label' => 'Horizon',  'slug' => 'horizon',  'icon' => '↗',  'route' => 'dev.horizon'],
        ['label' => 'System',   'slug' => 'system',   'icon' => '◇',  'route' => 'dev.system'],
    ];
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
        <title>{{ $title ?? 'Dev Console — beatrax' }}</title>
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
        @vite(['resources/css/app.css'])
        @livewireStyles
    </head>
    <body class="antialiased bg-white text-slate-900 dark:bg-slate-950 dark:text-slate-100" style="font-family: 'Inter', system-ui, -apple-system, sans-serif;">
        <div class="flex min-h-screen">
            <aside class="dev-side" aria-label="Dev Console" style="--side-w-dev: 220px;">
                <div class="dev-side-head">
                    <span>Dev Console</span>
                    <span class="dev-on-chip" aria-label="Developer mode on">ON</span>
                </div>

                @foreach ($devNavItems as $item)
                    @php
                        $routeExists = \Illuminate\Support\Facades\Route::has($item['route']);
                        $href = $routeExists ? route($item['route']) : '/dev/'.$item['slug'];
                        $disabledClass = $routeExists ? '' : ' nav-disabled';
                    @endphp
                    <a
                        href="{{ $href }}"
                        class="side-item{{ $disabledClass }}"
                        @if (! $routeExists) aria-disabled="true" tabindex="-1" @endif
                    >
                        <span class="ic" aria-hidden="true">{!! $item['icon'] !!}</span>
                        {{ $item['label'] }}
                    </a>
                @endforeach

                <div class="side-foot">
                    <a href="/" class="dev-back-link" aria-label="Back to app">
                        <span aria-hidden="true">←</span>
                        Back to app
                    </a>
                </div>
            </aside>

            <main class="flex-1 min-w-0 overflow-auto">
                @livewire('core.system-alerts-banner')
                {{ $slot }}
            </main>
        </div>
        @livewireScripts
    </body>
</html>
