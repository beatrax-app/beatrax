@use('Modules\Core\Public\Support\Lang')
@inject('container', \Illuminate\Contracts\Container\Container::class)
@inject('router', \Illuminate\Routing\Router::class)
@inject('devSidebarItems', \Modules\DevMode\Internal\Navigation\DevSidebarItems::class)
@php
    // Dev Console shell — the same dark/lang chrome as the app layout,
    // resolved once, then the main app sidebar is swapped for the Dev
    // Console sidebar on every /dev/* page (a hard layout swap, not nesting).
    $chrome = $container->make(\Modules\Core\Public\Support\AppChromeResolver::class)->resolve();

    // Dev sidebar nav items from the canonical DevSidebarItems registry.
    // `enabled` is informational; Router::has('dev.{slug}') is the runtime
    // truth that drives the nav-disabled class, so the dual representation
    // surfaces config drift rather than masking it. Horizon's 'conditional'
    // entry is DOM-absent when its route is unregistered (guarded below).
    $devNavItems = $devSidebarItems->all();
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

        {{-- See UploadSignalArchTest: every layout hosting posting UI has to
             tell the client which upload transport this runtime can carry. --}}
        @if (($beatraxEncodedUploads ?? false) === true)
            <meta name="beatrax-upload-transport" content="base64" />
        @endif
        <title>{{ $title ?? Lang::get('dev::shell.title_default') }}</title>
        <x-core::theme-prepaint :enabled="$chrome->needsPrePaintScript" />
        <x-core::head-assets />
    </head>
    <body
        class="antialiased bg-white text-slate-900 dark:bg-slate-950 dark:text-slate-100"
        style="font-family: 'Inter', system-ui, -apple-system, sans-serif;"
        x-data="{
            onKey(e) {
                /*
                 * Global command-palette keybind handler —
                 * duplicated verbatim from
                 * resources/views/layouts/app.blade.php so the
                 * palette + Dev Console keybind also work inside
                 * /dev/* (this layout is a hard layout swap and
                 * does NOT inherit body attributes from the main
                 * layout). Includes a carve-out for text inputs so
                 * ⌘K inside a search field types `k` instead of
                 * stealing focus into the palette.
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
        <div class="flex min-h-screen">
            <aside class="dev-side" aria-label="{{ Lang::get('dev::shell.sidebar_aria') }}" style="--side-w-dev: 220px;">
                <div class="dev-side-head">
                    <span>{{ Lang::get('dev::shell.heading') }}</span>
                    <span role="img" class="dev-on-chip" aria-label="{{ Lang::get('dev::shell.on_chip_aria') }}">{{ Lang::get('dev::shell.on_chip') }}</span>
                </div>

                @foreach ($devNavItems as $item)
                    @php
                        $routeExists = $router->getRoutes()->hasNamedRoute($item['route']);
                        // Conditionally-registered items (Horizon) render as
                        // DOM-absent rather than nav-disabled when the
                        // matching route is not registered. Every other
                        // slug renders with the disabled class so the
                        // operator can see which panels are pending.
                        $skip = ($item['enabled'] ?? false) === 'conditional' && ! $routeExists;
                    @endphp
                    @if (! $skip)
                        @php
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
                    @endif
                @endforeach

                <div class="side-foot">
                    <a href="/" class="dev-back-link" aria-label="{{ Lang::get('dev::shell.back_to_app') }}">
                        <span aria-hidden="true">←</span>
                        {{ Lang::get('dev::shell.back_to_app') }}
                    </a>
                </div>
            </aside>

            <main class="flex-1 min-w-0 overflow-auto">
                @livewire('core.system-alerts-banner')
                {{ $slot }}
            </main>
        </div>

        {{-- Global TripleGateModal — any /dev/* page can fire
             Livewire.dispatch('triple-gate:open', { command, args })
             to open the rose-tinted three-lock confirmation modal.
             The modal enforces all three gates server-side (Dev
             Mode env on + session Advanced on + typed "Beatrax"
             matches via hash_equals); on success it dispatches
             `triple-gate:confirmed`, which downstream listeners
             (DestructiveSpawnController for artisan,
             QueueInspectorPage::executeBulkDelete for queue rows)
             re-validate before acting. --}}
        @livewire('dev.triple-gate-modal')

        {{-- Global command-palette modal mounted inside the
             dev-shell as well as the main-app layout so ⌘K /
             Ctrl+K works inside /dev/* too. The Livewire component
             is the same; mounting both keeps the dispatch sink
             available regardless of which layout the request
             resolved through. --}}
        @livewire('dev.command-palette-modal')
        {{-- Search palette endpoint — provides server-backed transaction + entity
             hits to the ⌘K palette via $wire.search(q). Mounted in the dev-shell
             as well as the main app layout so ⌘K palette search works inside
             /dev/* pages. --}}
        @livewire('search.palette-search-endpoint')

        {{-- Arg-prompt modal for SAFE-tier commands with args.
             Listens for `command-args:prompt` — palette picks for
             commands with argsSchema dispatch that event instead of
             `spawn-command` so the operator fills the form before
             the spawn fires. --}}
        @livewire('dev.command-arg-prompt-modal')

        <x-core::toast-host />

        @livewireScripts
        @fluxScripts
    </body>
</html>
