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
    {{-- Background from CSS, not `bg-white dark:bg-slate-950`. The dark
         variant needs the class that the pre-paint script adds a beat later,
         so the utility painted white first and the page visibly flipped. --}}
    class="beatrax-shell text-slate-900 dark:text-slate-100 {{ $chrome->isDark ? 'dark' : 'light' }}"
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
        <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover" />
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
        <x-core::head-assets />
    </head>
    <body
        class="beatrax-shell antialiased text-slate-900 dark:text-slate-100"
        style="font-family: 'Inter', system-ui, -apple-system, sans-serif;"
    >
        @yield('content')
        @livewireScripts
        @fluxScripts
    </body>
</html>
