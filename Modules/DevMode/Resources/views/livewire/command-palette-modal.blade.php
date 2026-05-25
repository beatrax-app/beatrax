@php
    /**
     * @var list<array{id: string, label: string, icon: string, hint: string, source: string, url: ?string, handler: ?string, name: ?string, tier: ?string, keywords: list<string>}> $registry
     * @var list<array{id: string, label: string, icon: string, hint: string, source: string, url: ?string, handler: ?string, name: ?string, tier: ?string}> $recent
     *
     * Global command-palette modal mounted in both base layouts
     * (resources/views/layouts/app.blade.php and the dev-shell
     * Modules/DevMode/Resources/views/layouts/dev-shell.blade.php).
     *
     * The shell is server-rendered Blade; the fuzzy-search runtime is
     * the `palette()` Alpine factory registered in
     * resources/js/palette.js. NO inline <script> blocks here —
     * inline scripts violate CSP and bypass Vite bundling.
     *
     * Server emits the JSON registry (filtered by `is_developer`
     * at the Livewire component layer) and the seeded Recent list.
     * The Alpine factory wires Fuse.js with the locked weights
     * (label 0.65, hint 0.20, keywords 0.15) + threshold 0.35 +
     * ignoreLocation true.
     *
     * Surfaces use explicit Tailwind utilities with `dark:` variants
     * for every colored surface. The previous CSS-variable + inline
     * `style="background: var(--color-bg)"` approach produced an
     * empty/white panel in the bundled NativePHP context — utility
     * classes compile to deterministic `:where(.dark, .dark *)`
     * selectors that survive whatever cascade quirk the inline path
     * tripped over, and match the dark-mode pattern used by every
     * other Dev Console page.
     */
@endphp

<div
    wire:ignore.self
    x-data="palette({{ Js::from($registry) }}, {{ Js::from($recent) }})"
    x-on:palette:open.window="open()"
    x-on:palette:opened.window="open()"
    x-on:keydown.window="onKey($event)"
    data-testid="command-palette-modal"
>
    <template x-if="visible">
        <div
            class="palette-scrim fixed inset-0 z-[9999] flex items-start justify-center bg-slate-950/45 backdrop-blur-sm pt-[12vh]"
            x-on:click.self="close()"
        >
            <div
                class="palette w-[min(760px,92vw)] rounded-xl overflow-hidden shadow-2xl bg-white dark:bg-[#0b1220] text-slate-900 dark:text-slate-100 ring-1 ring-slate-200 dark:ring-slate-700"
                role="dialog"
                aria-modal="true"
                aria-label="Command palette"
            >
                <div class="palette-input flex items-center gap-2 px-4 py-3 border-b border-slate-200 dark:border-slate-700">
                    <span class="ic text-slate-400 dark:text-slate-500" aria-hidden="true">⌕</span>
                    <input
                        x-ref="input"
                        x-model="query"
                        type="text"
                        placeholder="Type to search views, commands, and actions. Press Esc to close."
                        aria-label="Type to search views, commands, and actions"
                        class="flex-1 min-w-0 bg-transparent border-0 outline-none text-sm text-slate-900 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500"
                    />
                    <span class="kbd" aria-hidden="true">esc</span>
                </div>

                <div class="palette-body flex min-h-[280px] max-h-[60vh]">
                    <aside class="palette-rail w-[180px] p-3 border-r border-slate-200 dark:border-slate-700 text-sm overflow-y-auto">
                        <div class="palette-rail-label text-[10.5px] uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1.5">View</div>
                        <div class="palette-rail-label text-[10.5px] uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1.5">Dev</div>
                        <div class="palette-rail-label text-[10.5px] uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1.5">Action</div>
                        <div class="palette-rail-divider h-px bg-slate-200 dark:bg-slate-700 my-3"></div>
                        <div class="palette-rail-label text-[10.5px] uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1.5">Recent</div>
                        <template x-for="r in recent.slice(0, 5)" :key="r.id">
                            <div
                                class="palette-row palette-row--mini px-1 py-1.5 rounded text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer"
                                x-text="r.label"
                                x-on:click="execute(r)"
                            ></div>
                        </template>
                        <template x-if="recent.length === 0">
                            <div class="px-1 py-1.5 text-xs text-slate-400 dark:text-slate-500">No recent picks yet.</div>
                        </template>
                    </aside>

                    <main class="palette-results flex-1 p-2 overflow-y-auto">
                        <template x-for="(hit, i) in results.slice(0, 50)" :key="hit.item.id">
                            <div
                                class="palette-row flex items-center gap-3 px-3 py-2 rounded-lg cursor-pointer"
                                :class="i === activeIndex
                                    ? 'palette-row--active bg-slate-100 dark:bg-slate-800 text-slate-900 dark:text-slate-100'
                                    : 'text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'"
                                x-on:click="execute(hit.item)"
                                x-on:mouseenter="activeIndex = i"
                            >
                                <span class="ic w-5 text-center text-slate-500 dark:text-slate-400" aria-hidden="true" x-text="hit.item.icon"></span>
                                <div class="palette-row-text flex-1 min-w-0">
                                    <div class="palette-row-label text-sm font-medium" x-text="hit.item.label"></div>
                                    <div class="palette-row-hint text-xs text-slate-500 dark:text-slate-400" x-text="hit.item.hint"></div>
                                </div>
                                <span
                                    class="palette-source text-[10px] uppercase tracking-wider px-1.5 py-0.5 rounded text-slate-500 dark:text-slate-400 bg-slate-100 dark:bg-slate-800"
                                    :class="'palette-source--' + hit.item.source"
                                    x-text="hit.item.source"
                                ></span>
                                <span class="kbd" aria-hidden="true">↩</span>
                            </div>
                        </template>
                        <template x-if="results.length === 0">
                            <div class="p-4 text-center text-sm text-slate-500 dark:text-slate-400">No results.</div>
                        </template>
                    </main>
                </div>

                <div class="palette-foot flex items-center gap-3 px-4 py-2 border-t border-slate-200 dark:border-slate-700 text-xs text-slate-500 dark:text-slate-400">
                    <span><span class="kbd">↑</span><span class="kbd">↓</span> navigate</span>
                    <span><span class="kbd">↩</span> select</span>
                    <span><span class="kbd">esc</span> close</span>
                    <span class="ml-auto" x-text="results.length + ' results'"></span>
                </div>
            </div>
        </div>
    </template>
</div>
