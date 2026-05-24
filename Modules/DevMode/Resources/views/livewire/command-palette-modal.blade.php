@php
    /**
     * @var list<array{id: string, label: string, icon: string, hint: string, source: string, url: ?string, handler: ?string, name: ?string, tier: ?string, keywords: list<string>}> $registry
     * @var list<array{id: string, label: string, icon: string, hint: string, source: string, url: ?string, handler: ?string, name: ?string, tier: ?string}> $recent
     *
     * Global command-palette modal mounted in both base layouts
     * (resources/views/layouts/app.blade.php and the dev-shell
     * Modules/DevMode/Resources/views/layouts/dev-shell.blade.php).
     *
     * The shell is server-rendered Blade + Flux; the fuzzy-search
     * runtime is the `palette()` Alpine factory registered in
     * resources/js/palette.js. NO inline <script> blocks here —
     * inline scripts violate CSP and bypass Vite bundling.
     *
     * Server emits the JSON registry (filtered by `is_developer`
     * at the Livewire component layer) and the seeded Recent list.
     * The Alpine factory wires Fuse.js with the locked weights
     * (label 0.65, hint 0.20, keywords 0.15) + threshold 0.35 +
     * ignoreLocation true.
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
            class="palette-scrim"
            style="
                position: fixed; inset: 0; z-index: 9999;
                background: rgba(8, 12, 20, 0.45);
                backdrop-filter: blur(4px) saturate(120%);
                display: flex; align-items: flex-start; justify-content: center;
                padding-top: 12vh;
            "
            x-on:click.self="close()"
        >
            <div
                class="palette"
                role="dialog"
                aria-modal="true"
                aria-label="Command palette"
                style="
                    width: min(760px, 92vw);
                    background: var(--color-bg, #fff);
                    color: var(--color-text, #0b1220);
                    border-radius: 12px;
                    box-shadow: 0 24px 64px rgba(8, 12, 20, 0.35);
                    overflow: hidden;
                "
            >
                <div class="palette-input" style="display: flex; align-items: center; gap: 8px; padding: 12px 16px; border-bottom: 1px solid rgba(8, 12, 20, 0.1);">
                    <span class="ic" aria-hidden="true">⌕</span>
                    <input
                        x-ref="input"
                        x-model="query"
                        type="text"
                        placeholder="Type to search views, commands, and actions. Press Esc to close."
                        aria-label="Type to search views, commands, and actions"
                        style="flex: 1; border: none; outline: none; background: transparent; font: inherit; color: inherit;"
                    />
                    <span class="kbd" aria-hidden="true">esc</span>
                </div>

                <div class="palette-body" style="display: flex; min-height: 280px; max-height: 60vh;">
                    <aside class="palette-rail" style="width: 180px; padding: 12px; border-right: 1px solid rgba(8, 12, 20, 0.06); font-size: 0.85rem; overflow-y: auto;">
                        <div class="palette-rail-label" style="opacity: 0.6; text-transform: uppercase; font-size: 0.7rem; letter-spacing: 0.06em; margin-bottom: 6px;">View</div>
                        <div class="palette-rail-label" style="opacity: 0.6; text-transform: uppercase; font-size: 0.7rem; letter-spacing: 0.06em; margin-bottom: 6px;">Dev</div>
                        <div class="palette-rail-label" style="opacity: 0.6; text-transform: uppercase; font-size: 0.7rem; letter-spacing: 0.06em; margin-bottom: 6px;">Action</div>
                        <div class="palette-rail-divider" style="height: 1px; background: rgba(8, 12, 20, 0.08); margin: 12px 0;"></div>
                        <div class="palette-rail-label" style="opacity: 0.6; text-transform: uppercase; font-size: 0.7rem; letter-spacing: 0.06em; margin-bottom: 6px;">Recent</div>
                        <template x-for="r in recent.slice(0, 5)" :key="r.id">
                            <div
                                class="palette-row palette-row--mini"
                                style="padding: 6px 4px; cursor: pointer; border-radius: 6px;"
                                x-text="r.label"
                                x-on:click="execute(r)"
                            ></div>
                        </template>
                        <template x-if="recent.length === 0">
                            <div style="opacity: 0.5; font-size: 0.75rem; padding: 6px 4px;">No recent picks yet.</div>
                        </template>
                    </aside>

                    <main class="palette-results" style="flex: 1; padding: 8px; overflow-y: auto;">
                        <template x-for="(hit, i) in results.slice(0, 50)" :key="hit.item.id">
                            <div
                                class="palette-row"
                                :class="{ 'palette-row--active': i === activeIndex }"
                                style="display: flex; align-items: center; gap: 12px; padding: 8px 12px; border-radius: 8px; cursor: pointer;"
                                x-on:click="execute(hit.item)"
                                x-on:mouseenter="activeIndex = i"
                            >
                                <span class="ic" aria-hidden="true" x-text="hit.item.icon" style="width: 20px; text-align: center;"></span>
                                <div class="palette-row-text" style="flex: 1; min-width: 0;">
                                    <div class="palette-row-label" x-text="hit.item.label" style="font-weight: 500;"></div>
                                    <div class="palette-row-hint" x-text="hit.item.hint" style="opacity: 0.7; font-size: 0.8rem;"></div>
                                </div>
                                <span class="palette-source" :class="'palette-source--' + hit.item.source" x-text="hit.item.source" style="font-size: 0.7rem; padding: 2px 6px; border-radius: 4px; opacity: 0.8;"></span>
                                <span class="kbd" aria-hidden="true">↩</span>
                            </div>
                        </template>
                        <template x-if="results.length === 0">
                            <div style="opacity: 0.6; padding: 16px; text-align: center;">No results.</div>
                        </template>
                    </main>
                </div>

                <div class="palette-foot" style="display: flex; align-items: center; gap: 12px; padding: 8px 16px; border-top: 1px solid rgba(8, 12, 20, 0.06); font-size: 0.75rem; opacity: 0.75;">
                    <span><span class="kbd">↑</span><span class="kbd">↓</span> navigate</span>
                    <span><span class="kbd">↩</span> select</span>
                    <span><span class="kbd">esc</span> close</span>
                    <span style="margin-left: auto;" x-text="results.length + ' results'"></span>
                </div>
            </div>
        </div>
    </template>
</div>
