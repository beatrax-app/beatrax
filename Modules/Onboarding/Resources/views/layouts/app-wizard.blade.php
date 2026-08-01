@use('Modules\Core\Public\Support\Lang')
@inject('container', \Illuminate\Contracts\Container\Container::class)
@php
    // First-run setup wizard shell — body-only chrome (no sidebar, alerts
    // banner, or palette): the calm setup page is the entire focal point.
    // It carries the same dark/lang chrome as the app layout, resolved once.
    $chrome = $container->make(\Modules\Core\Public\Support\AppChromeResolver::class)->resolve();
@endphp
<!doctype html>
<html
    lang="{{ $chrome->locale }}"
    class="bg-white text-slate-900 dark:bg-slate-950 dark:text-slate-100 {{ $chrome->isDark ? 'dark' : '' }}"
    style="font-feature-settings: 'tnum';"
>
    <head>
        <meta charset="utf-8" />
        <meta name="viewport" content="width=device-width, initial-scale=1" />
        <meta name="csrf-token" content="{{ csrf_token() }}" />
        <title>{{ $title ?? Lang::get('onboarding::wizard.page_title').' · beatrax' }}</title>
        <x-core::theme-prepaint :enabled="$chrome->needsPrePaintScript" />
        <x-core::head-assets />
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
