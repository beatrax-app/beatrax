@inject('currentUser', \Modules\Core\Public\Contracts\CurrentUser::class)
@inject('container', \Illuminate\Contracts\Container\Container::class)
@inject('router', \Illuminate\Routing\Router::class)
@inject('devSidebarItems', \Modules\DevMode\Internal\Navigation\DevSidebarItems::class)
@php
    /*
     * Dev Console layout shell.
     *
     * Mirrors resources/views/layouts/app.blade.php's head + theme
     * resolution + Livewire wiring, then swaps the main app sidebar
     * (.side, 248px) for the Dev Console sidebar (.dev-side, 220px)
     * on every /dev/* page. The swap is a hard layout swap (not
     * nesting).
     *
     * Theme resolution mirrors app.blade.php's intent — no flash of
     * wrong theme on `system` users — but routes the user lookup
     * through the CurrentUser contract via @inject (which resolves
     * through the container) so the layout honours the project's
     * DI-only rule (no auth() / Auth:: facade calls inside Modules/*).
     * The OsThemeSignal optional binding is resolved through the
     * injected container, never through the app() global.
     *
     * Sidebar nav items render `/dev/{slug}` URLs; entries whose
     * matching route is not registered render with the
     * `nav-disabled` class so the operator can see which panels are
     * pending.
     */
    $userTheme = $currentUser->isAuthenticated() ? ($currentUser->user()->theme ?? 'system') : 'system';

    $osTheme = null;
    if ($userTheme === 'system' && $container->bound(\Modules\Desktop\Public\Contracts\OsThemeSignal::class)) {
        $osTheme = $container->make(\Modules\Desktop\Public\Contracts\OsThemeSignal::class)->currentOsTheme();
    }

    $isDark = $userTheme === 'dark' || ($userTheme === 'system' && $osTheme === 'dark');
    $needsPrePaintScript = $userTheme === 'system' && $osTheme === null;

    /*
     * Dev sidebar nav items.
     *
     * Sourced from the canonical DevSidebarItems registry. The
     * `enabled` field on each item is informational; the runtime
     * truth that drives the `nav-disabled` class is
     * Router::has('dev.{slug}'). The dual representation surfaces
     * config drift rather than masking it.
     *
     * Horizon's `enabled` value is the string 'conditional': the
     * matching route is only registered when both the dev_mode env
     * flag is true AND the Horizon package's ServiceProvider class
     * is present. The Router::has() guard below covers the
     * conditional case — when the route is absent the entry is
     * DOM-absent; non-developers see 404 via EnsureDeveloperMode
     * regardless.
     */
    $devNavItems = $devSidebarItems->all();
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
            <aside class="dev-side" aria-label="Dev Console" style="--side-w-dev: 220px;">
                <div class="dev-side-head">
                    <span>Dev Console</span>
                    <span class="dev-on-chip" aria-label="Developer mode on">ON</span>
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

        {{-- Global TripleGateModal — any /dev/* page can fire
             Livewire.dispatch('triple-gate:open', { command, args })
             to open the rose-tinted three-lock confirmation modal.
             The modal enforces all three gates server-side (Dev
             Mode env on + session Advanced on + typed "beatrax"
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
             /dev/* pages (Plan 08-05, SRCH-02). --}}
        @livewire('search.palette-search-endpoint')

        {{-- Arg-prompt modal for SAFE-tier commands with args.
             Listens for `command-args:prompt` — palette picks for
             commands with argsSchema dispatch that event instead of
             `spawn-command` so the operator fills the form before
             the spawn fires. --}}
        @livewire('dev.command-arg-prompt-modal')

        {{-- Global toast stack.

             Every $this->dispatch('toast', message: '...') in the
             project fires a browser CustomEvent on the window with
             the message in $event.detail.message; this Alpine
             listener pushes it onto a 5-second auto-dismiss stack
             rendered bottom-right. Without this host, the dispatch
             reached no UI — user-visible symptom: "I clicked Run and
             nothing happened" despite the spawn firing fine on disk.

             Identical snippet lives in the main app layout so toasts
             work uniformly inside and outside /dev/*. --}}
        <div
            x-data="{
                toasts: [],
                push(detail) {
                    const id = Date.now() + Math.random();
                    this.toasts.push({ id, message: (detail && detail.message) || '' });
                    setTimeout(() => { this.toasts = this.toasts.filter(t => t.id !== id); }, 5000);
                },
                dismiss(id) { this.toasts = this.toasts.filter(t => t.id !== id); },
            }"
            x-on:toast.window="push($event.detail)"
            class="pointer-events-none fixed bottom-4 right-4 z-[10000] flex w-[min(380px,calc(100vw-2rem))] flex-col-reverse gap-2"
            aria-atomic="true"
            aria-live="polite"
            data-testid="toast-host"
        >
            <template x-for="t in toasts" :key="t.id">
                <div
                    x-on:click="dismiss(t.id)"
                    class="pointer-events-auto cursor-pointer rounded-md bg-slate-900 px-4 py-3 text-sm text-white shadow-lg ring-1 ring-slate-800 dark:bg-slate-100 dark:text-slate-900 dark:ring-slate-300"
                    role="alert"
                    data-testid="toast"
                    x-text="t.message"
                ></div>
            </template>
        </div>

        @livewireScripts
        @fluxScripts
    </body>
</html>
