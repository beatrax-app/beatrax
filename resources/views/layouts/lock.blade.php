@inject('container', \Illuminate\Contracts\Container\Container::class)
@php
    // Minimal lock-screen shell — no sidebar, top bar, veil, or modals
    // (UI-SPEC §1); the lock screen is itself the privacy gate. It keeps the
    // same theme/lang chrome as the full app layout, resolved once here.
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
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
        <x-core::pwa-head />
        <meta name="csrf-token" content="{{ csrf_token() }}" />
        <title>{{ $title ?? 'beatrax' }}</title>
        <x-core::theme-prepaint :enabled="$chrome->needsPrePaintScript" />
        <x-core::head-assets />
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
