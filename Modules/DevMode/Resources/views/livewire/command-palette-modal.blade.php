@use('Modules\Core\Public\Support\Lang')
@php
    /**
     * @var list<array{id: string, label: string, icon: string, hint: string, source: string, url: ?string, handler: ?string, name: ?string, tier: ?string, keywords: list<string>}> $registry
     * @var list<array{id: string, label: string, icon: string, hint: string, source: string, url: ?string, handler: ?string, name: ?string, tier: ?string}> $recent
     * @var bool $searchAvailable  True when Search module is wired (SearchResultsProvider bound).
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
     *
     * Server-backed search additions:
     *   - Server-backed Transactions + entity sections, gated on
     *     $searchAvailable (null-safe: palette works when Search unbound).
     *   - Token autocomplete overlay — srch-token-suggest CSS class.
     *   - "See all N results →" row navigates to /transactions?q={query}.
     *   - Recent transaction-search entries persisted via pickEntry path.
     *   - Footer kbd hint copy from the Copywriting Contract.
     */
    $searchAvailable = $searchAvailable ?? false;
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
            class="fixed inset-0 z-[9999] flex items-start justify-center bg-slate-950/45 backdrop-blur-sm pt-[12vh] max-md:p-0 max-md:pt-0 max-md:items-start"
            x-on:click.self="close()"
        >
            {{-- Desktop palette (>= 768px) + phone full-screen sheet (< 768px) --}}
            <div
                class="w-[min(760px,92vw)] rounded-xl overflow-hidden shadow-2xl bg-white dark:bg-[#0b1220] text-slate-900 dark:text-slate-100 ring-1 ring-slate-200 dark:ring-slate-700 max-md:w-full max-md:h-full max-md:rounded-none max-md:ring-0 max-md:shadow-none phone-palette-sheet"
                x-ref="panel"
                role="dialog"
                aria-modal="true"
                aria-label="{{ Lang::get('dev::palette.dialog_aria') }}"
            >
                <div class="flex items-center gap-2 px-4 py-3 border-b border-slate-200 dark:border-slate-700 relative">
                    {{-- Loading spinner or search icon. Both are pinned to the
                         same w-4 box: they swap in and out of one slot ahead
                         of the input, and two glyphs of different advance
                         width would shift the query text sideways every time
                         a search starts. --}}
                    <x-core::search-mark
                        size="md"
                        class="ic text-slate-400 dark:text-slate-500"
                        x-show="!serverLoading"
                    />
                    <x-core::spinner
                        class="text-slate-400 dark:text-slate-500"
                        x-show="serverLoading"
                        style="display:none"
                    />

                    {{-- On a phone the sheet covers the scrim completely, so
                         the scrim's click.self never fires and the only way out
                         was a key the device does not have. --}}
                    <x-core::emoji-action
                        :label="Lang::get('dev::palette.close_aria')"
                        class="md:hidden order-last"
                        x-on:click="close()"
                        data-testid="palette-close"
                    >✖️</x-core::emoji-action>

                    <div class="flex-1 relative">
                        <input
                            x-ref="input"
                            x-model="query"
                            x-on:input="onQueryChange()"
                            type="text"
                            placeholder="{{ Lang::get('dev::palette.search_placeholder') }}"
                            aria-label="{{ Lang::get('dev::palette.search_aria') }}"
                            role="combobox"
                            aria-expanded="true"
                            aria-autocomplete="list"
                            aria-controls="palette-results-listbox"
                            :aria-activedescendant="results.length > 0 ? 'palette-option-' + activeIndex : null"
                            class="w-full bg-transparent border-0 outline-none text-sm text-slate-900 dark:text-slate-100 placeholder:text-slate-400 dark:placeholder:text-slate-500"
                            autocomplete="off"
                        />

                        {{-- Token autocomplete overlay (UI-SPEC #7) --}}
                        <template x-if="tokenSuggestVisible && tokenSuggestions.length > 0">
                            <div
                                class="srch-token-suggest"
                                x-on:click.outside="tokenSuggestVisible = false"
                                role="listbox"
                                aria-label="{{ Lang::get('dev::palette.token_suggest_aria') }}"
                            >
                                <template x-for="(suggestion, i) in tokenSuggestions" :key="suggestion">
                                    <button
                                        type="button"
                                        data-palette-row
                                        class="srch-token-suggest-row block w-full text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:focus-visible:ring-slate-100"
                                        :class="i === tokenActiveIndex ? 'srch-token-suggest-row--selected' : ''"
                                        x-on:click="applyTokenSuggestion(suggestion)"
                                        role="option"
                                        :aria-selected="i === tokenActiveIndex"
                                        x-text="suggestion"
                                    ></button>
                                </template>
                            </div>
                        </template>
                    </div>

                    <span class="kbd hidden-touch max-lg:hidden" aria-hidden="true">esc</span>
                </div>

                <div class="palette-body flex min-h-[280px] max-h-[60vh] max-md:max-h-full max-md:flex-1">
                    <aside class="palette-rail w-[180px] p-3 border-r border-slate-200 dark:border-slate-700 text-sm overflow-y-auto max-md:hidden">
                        <div class="text-[10.5px] uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1.5">{{ Lang::get('dev::palette.rail_view') }}</div>
                        <div class="text-[10.5px] uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1.5">{{ Lang::get('dev::palette.rail_dev') }}</div>
                        <div class="text-[10.5px] uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1.5">{{ Lang::get('dev::palette.rail_action') }}</div>
                        <div class="h-px bg-slate-200 dark:bg-slate-700 my-3"></div>
                        <div class="text-[10.5px] uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-1.5">{{ Lang::get('dev::palette.rail_recent') }}</div>
                        <template x-for="r in recent.slice(0, 5)" :key="r.id">
                            <button
                                type="button"
                                data-palette-row
                                class="palette-row block w-full text-left px-1 py-1.5 rounded text-sm text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:focus-visible:ring-slate-100"
                                x-text="r.label"
                                x-on:click="execute(r)"
                            ></button>
                        </template>
                        <template x-if="recent.length === 0">
                            <div class="px-1 py-1.5 text-xs text-slate-400 dark:text-slate-500">{{ Lang::get('dev::palette.no_recent') }}</div>
                        </template>
                    </aside>

                    <main class="flex-1 p-2 overflow-y-auto">
                        {{-- Server-backed sections — only rendered when Search module is wired and query >= 2 chars --}}
                        @if($searchAvailable)
                        <template x-if="query.length >= 2">
                            <div>
                                {{-- Section 1: Transactions (UI-SPEC #6) --}}
                                <div class="palette-section-label px-3 py-1 text-[10.5px] uppercase tracking-wider text-slate-500 dark:text-slate-400 font-semibold">
                                    {{ Lang::get('dev::palette.section_transactions') }}
                                </div>

                                <template x-if="serverTransactionHits.length > 0">
                                    <div>
                                        <template x-for="hit in serverTransactionHits" :key="hit.id">
                                            <button
                                                type="button"
                                                data-palette-row
                                                class="palette-row palette-txn-row flex w-full items-start gap-3 px-4 py-2 rounded-lg text-left cursor-pointer hover:bg-slate-100 dark:hover:bg-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:focus-visible:ring-slate-100"
                                                x-on:click="executeTransactionHit(hit)"
                                            >
                                                <span class="ic w-5 text-center text-slate-400 dark:text-slate-500 mt-0.5 shrink-0" aria-hidden="true">≡</span>
                                                <span class="block flex-1 min-w-0">
                                                    {{-- Line 1: counterparty name + amount --}}
                                                    <span class="flex items-baseline gap-2">
                                                        {{-- x-text, NOT x-html: counterpartyName is raw user input
                                                             (the palette does not highlight the name). Binding it with
                                                             x-html would execute HTML in a merchant name (stored XSS). --}}
                                                        <span
                                                            class="text-sm font-semibold text-slate-900 dark:text-slate-100 truncate"
                                                            x-text="hit.counterpartyName || @js(Lang::get('dev::palette.no_name'))"
                                                        ></span>
                                                        <span
                                                            class="text-xs text-slate-500 dark:text-slate-400 shrink-0 tabular-nums ml-auto"
                                                            x-text="hit.amount"
                                                        ></span>
                                                    </span>
                                                    {{-- Line 2: matched snippet --}}
                                                    <template x-if="hit.snippet">
                                                        <span
                                                            class="block text-xs text-slate-500 dark:text-slate-400 truncate"
                                                            x-html="hit.snippet"
                                                        ></span>
                                                    </template>
                                                </span>
                                                <span class="palette-source--txn text-[10px] uppercase tracking-wider px-1.5 py-0.5 rounded text-slate-500 dark:text-slate-400 bg-slate-100 dark:bg-slate-800 shrink-0">{{ Lang::get('dev::palette.source_txn') }}</span>
                                            </button>
                                        </template>

                                        {{-- "See all N results →" row --}}
                                        <button
                                            type="button"
                                            data-palette-row
                                            class="palette-row flex w-full items-center gap-3 px-4 py-2 rounded-lg text-left cursor-pointer text-slate-500 dark:text-slate-400 hover:bg-slate-100 dark:hover:bg-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:focus-visible:ring-slate-100"
                                            x-on:click="seeAllResults()"
                                        >
                                            <span class="w-5 text-center text-slate-400 dark:text-slate-500 shrink-0" aria-hidden="true">→</span>
                                            <span class="text-sm" x-text="@js(Lang::get('dev::palette.see_all_prefix')) + serverTotalCount + @js(Lang::get('dev::palette.see_all_suffix'))"></span>
                                        </button>
                                    </div>
                                </template>

                                <template x-if="serverTransactionHits.length === 0 && !serverLoading">
                                    <div class="px-4 py-2 text-sm text-slate-400 dark:text-slate-500"
                                         x-text="@js(Lang::get('dev::palette.no_transactions_prefix')) + query + @js(Lang::get('dev::palette.no_transactions_suffix'))">
                                    </div>
                                </template>

                                {{-- Section 2: Counterparties --}}
                                <template x-if="serverEntityHits.filter(e => e.type === 'counterparty').length > 0">
                                    <div>
                                        <div class="palette-section-label px-3 py-1 text-[10.5px] uppercase tracking-wider text-slate-500 dark:text-slate-400 font-semibold mt-2">
                                            {{ Lang::get('dev::palette.section_counterparties') }}
                                        </div>
                                        <template x-for="entity in serverEntityHits.filter(e => e.type === 'counterparty').slice(0, 3)" :key="entity.id + '-' + entity.type">
                                            <button
                                                type="button"
                                                data-palette-row
                                                class="palette-row flex w-full items-center gap-3 px-4 py-2 rounded-lg text-left cursor-pointer text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:focus-visible:ring-slate-100"
                                                x-on:click="execute({ url: entity.url })"
                                            >
                                                <span class="ic w-5 text-center text-slate-400 dark:text-slate-500" aria-hidden="true">◈</span>
                                                <span class="flex-1 text-sm" x-text="entity.label"></span>
                                                <span class="text-[10px] uppercase tracking-wider px-1.5 py-0.5 rounded text-slate-500 dark:text-slate-400 bg-slate-100 dark:bg-slate-800">{{ Lang::get('dev::palette.source_counterparty') }}</span>
                                            </button>
                                        </template>
                                    </div>
                                </template>

                                {{-- Section 3: Categories --}}
                                <template x-if="serverEntityHits.filter(e => e.type === 'category').length > 0">
                                    <div>
                                        <div class="palette-section-label px-3 py-1 text-[10.5px] uppercase tracking-wider text-slate-500 dark:text-slate-400 font-semibold mt-2">
                                            {{ Lang::get('dev::palette.section_categories') }}
                                        </div>
                                        <template x-for="entity in serverEntityHits.filter(e => e.type === 'category').slice(0, 3)" :key="entity.id + '-' + entity.type">
                                            <button
                                                type="button"
                                                data-palette-row
                                                class="palette-row flex w-full items-center gap-3 px-4 py-2 rounded-lg text-left cursor-pointer text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:focus-visible:ring-slate-100"
                                                x-on:click="execute({ url: entity.url })"
                                            >
                                                <span class="ic w-5 text-center text-slate-400 dark:text-slate-500" aria-hidden="true">⊞</span>
                                                <span class="flex-1 text-sm" x-text="entity.label"></span>
                                                <span class="text-[10px] uppercase tracking-wider px-1.5 py-0.5 rounded text-slate-500 dark:text-slate-400 bg-slate-100 dark:bg-slate-800">{{ Lang::get('dev::palette.source_category') }}</span>
                                            </button>
                                        </template>
                                    </div>
                                </template>

                                {{-- Section 4: Goals / Pots / Recurring --}}
                                <template x-if="serverEntityHits.filter(e => ['goal','pot','recurring'].includes(e.type)).length > 0">
                                    <div>
                                        <div class="palette-section-label px-3 py-1 text-[10.5px] uppercase tracking-wider text-slate-500 dark:text-slate-400 font-semibold mt-2">
                                            {{ Lang::get('dev::palette.section_goals_recurring') }}
                                        </div>
                                        <template x-for="entity in serverEntityHits.filter(e => ['goal','pot','recurring'].includes(e.type)).slice(0, 3)" :key="entity.id + '-' + entity.type">
                                            <button
                                                type="button"
                                                data-palette-row
                                                class="palette-row flex w-full items-center gap-3 px-4 py-2 rounded-lg text-left cursor-pointer text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:focus-visible:ring-slate-100"
                                                x-on:click="execute({ url: entity.url })"
                                            >
                                                <span class="ic w-5 text-center text-slate-400 dark:text-slate-500" aria-hidden="true">◎</span>
                                                <span class="flex-1 text-sm" x-text="entity.label"></span>
                                                <span class="text-[10px] uppercase tracking-wider px-1.5 py-0.5 rounded text-slate-500 dark:text-slate-400 bg-slate-100 dark:bg-slate-800" x-text="entity.type"></span>
                                            </button>
                                        </template>
                                    </div>
                                </template>

                                {{-- Section divider before Fuse results --}}
                                <div class="h-px bg-slate-100 dark:bg-slate-800 my-2 mx-3"></div>
                            </div>
                        </template>
                        @endif

                        {{-- Section 5: existing Fuse.js command/view/action results, ordered last.
                             These are the rows the arrow keys walk, so they are the ones the
                             search input names through aria-activedescendant. --}}
                        <div
                            id="palette-results-listbox"
                            role="listbox"
                            aria-label="{{ Lang::get('dev::palette.results_aria') }}"
                        >
                            <template x-for="(hit, i) in results.slice(0, 50)" :key="hit.item.id">
                                <button
                                    type="button"
                                    role="option"
                                    data-palette-row
                                    :data-palette-index="i"
                                    :id="'palette-option-' + i"
                                    :aria-selected="i === activeIndex"
                                    :tabindex="i === activeIndex ? 0 : -1"
                                    class="palette-row flex w-full items-center gap-3 px-3 py-2 rounded-lg text-left cursor-pointer focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 dark:focus-visible:ring-slate-100"
                                    :class="i === activeIndex
                                        ? 'bg-slate-100 dark:bg-slate-800 text-slate-900 dark:text-slate-100'
                                        : 'text-slate-700 dark:text-slate-300 hover:bg-slate-100 dark:hover:bg-slate-800'"
                                    x-on:click="execute(hit.item)"
                                    x-on:mouseenter="activeIndex = i"
                                >
                                    <span class="ic w-5 text-center text-slate-500 dark:text-slate-400" aria-hidden="true" x-text="hit.item.icon"></span>
                                    <span class="block flex-1 min-w-0">
                                        <span class="block text-sm font-medium" x-text="hit.item.label"></span>
                                        <span class="block text-xs text-slate-500 dark:text-slate-400" x-text="hit.item.hint"></span>
                                    </span>
                                    <span
                                        class="text-[10px] uppercase tracking-wider px-1.5 py-0.5 rounded text-slate-500 dark:text-slate-400 bg-slate-100 dark:bg-slate-800"
                                        :class="'palette-source--' + hit.item.source"
                                        x-text="hit.item.sourceLabel"
                                    ></span>
                                    <span class="kbd hidden-touch max-lg:hidden" aria-hidden="true">↩</span>
                                </button>
                            </template>
                        </div>
                        <template x-if="results.length === 0 && serverTransactionHits.length === 0 && serverEntityHits.length === 0">
                            <div class="p-4 text-center text-sm text-slate-500 dark:text-slate-400">{{ Lang::get('dev::palette.no_results') }}</div>
                        </template>
                    </main>
                </div>

                <div class="hidden-touch max-lg:hidden flex items-center gap-3 px-4 py-2 border-t border-slate-200 dark:border-slate-700 text-xs text-slate-500 dark:text-slate-400">
                    <span><span class="kbd">↑</span><span class="kbd">↓</span> {{ Lang::get('dev::palette.foot_navigate') }}</span>
                    <span><span class="kbd">↩</span> {{ Lang::get('dev::palette.foot_select') }}</span>
                    <span><span class="kbd">esc</span> {{ Lang::get('dev::palette.foot_close') }}</span>
                    @if($searchAvailable)
                    <span class="hidden sm:inline text-slate-400 dark:text-slate-500">{{ Lang::get('dev::palette.foot_try') }} <span class="font-mono text-[10px]">account:</span> · <span class="font-mono text-[10px]">after:</span> · <span class="font-mono text-[10px]">amount:&gt;50</span></span>
                    @endif
                    <span class="ml-auto" x-text="results.length + @js(Lang::get('dev::palette.results_suffix'))"></span>
                </div>
            </div>
        </div>
    </template>
</div>
