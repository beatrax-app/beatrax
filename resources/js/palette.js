import Fuse from 'fuse.js';

/**
 * Alpine factory for the command palette modal (16-08, DEVUI-09).
 *
 * Mounted by `Modules/DevMode/Resources/views/livewire/command-palette-modal.blade.php`
 * via `<div x-data="palette(registry, recent)">`. The two arguments
 * are the server-emitted JSON registry (already filtered by
 * `is_developer` at the controller layer) and the seeded Recent
 * list.
 *
 * Fuse.js config is LOCKED per UI-SPEC § Component inventory:
 *
 *   - keys[0] label    weight: 0.65
 *   - keys[1] hint     weight: 0.20
 *   - keys[2] keywords weight: 0.15
 *   - threshold: 0.35
 *   - ignoreLocation: true
 *
 * Server-backed search (08-05, SRCH-02):
 *   - When query.length >= 2, a debounced (200ms) server fetch fires via
 *     $wire.search(q) on the mounted search.palette-search-endpoint component.
 *   - Server hits are merged into the results pane ABOVE Fuse results (D-01).
 *   - Previous server hits shown while a new fetch is in-flight (no blank flash).
 *   - A loading spinner appears in the input trailing slot during fetch.
 *
 * Token autocomplete (D-26):
 *   - When the query ends with a recognized token prefix (account:, amount:,
 *     after:, before:, category:), a suggestion overlay appears.
 *   - Esc dismisses the overlay without closing the palette.
 *
 * Selecting a row:
 *   - source `dev` (or `dev.cmd.*` id)    → dispatch `spawn-command`
 *     with the resolved-args payload; the artisan runner page is the
 *     SOLE listener (per <interfaces> I-3 fix). The palette is a
 *     calmer surface that dispatches the spawn intent only — it
 *     does NOT render an inline arg form here.
 *   - any other row with `url`            → window.location.href.
 *   - any other row with `handlerEvent`   → Livewire.dispatch().
 *
 * Every selection also dispatches `palette:picked` so the Livewire
 * `CommandPaletteModal` component can write the entry into the
 * per-user Recent cache (`dev_mode.palette_recent.{userId}`, 30-day
 * TTL, deduped, capped at 5 per UI-SPEC).
 */

/** Token prefixes recognized for autocomplete. */
const TOKEN_PREFIXES = ['account:', 'amount:', 'after:', 'before:', 'category:'];

/** Token display suggestions (labels for the overlay). */
const TOKEN_SUGGESTIONS = {
    'account:': ['account:ASN', 'account:ICS', 'account:PayPal'],
    'amount:':  ['amount:>50', 'amount:<100', 'amount:>0'],
    'after:':   ['after:2026-01', 'after:2025-01', 'after:2024-01'],
    'before:':  ['before:2026-12', 'before:2025-12'],
    'category:': ['category:Groceries', 'category:Subscriptions', 'category:Travel'],
};

export const palette = (registry, recent) => ({
    visible: false,
    query: '',
    activeIndex: 0,
    recent: Array.isArray(recent) ? recent : [],
    registry: Array.isArray(registry) ? registry : [],
    fuse: null,

    // Server-backed search state (08-05)
    serverTransactionHits: [],
    serverEntityHits: [],
    serverTotalCount: 0,
    serverLoading: false,
    _debounceTimer: null,
    _searchEndpoint: null,

    // Token autocomplete state
    tokenSuggestions: [],
    tokenSuggestVisible: false,
    tokenActiveIndex: 0,

    init() {
        this.fuse = new Fuse(this.registry, {
            keys: [
                { name: 'label', weight: 0.65 },
                { name: 'hint', weight: 0.20 },
                { name: 'keywords', weight: 0.15 },
            ],
            threshold: 0.35,
            ignoreLocation: true,
        });

        // Resolve the search.palette-search-endpoint Livewire component
        // so we can call $wire.search() on it. Use a lazy resolver so
        // the component is guaranteed to be mounted by the time the user
        // starts typing.
        this._resolveSearchEndpoint();
    },

    _resolveSearchEndpoint() {
        // Find the mounted PaletteSearchEndpoint component by its
        // data-testid attribute. The component renders a hidden root
        // element with data-testid="palette-search-endpoint".
        const findEndpoint = () => {
            try {
                const el = document.querySelector('[data-testid="palette-search-endpoint"]');
                if (el && window.Livewire) {
                    this._searchEndpoint = window.Livewire.find(el.getAttribute('wire:id'));
                }
            } catch (_) {
                // Non-fatal — will retry on next debounce
            }
        };

        // Attempt immediately and once after a short delay (components
        // might not be mounted at Alpine init time).
        findEndpoint();
        setTimeout(findEndpoint, 500);
    },

    open() {
        this.query = '';
        this.activeIndex = 0;
        this.serverTransactionHits = [];
        this.serverEntityHits = [];
        this.serverTotalCount = 0;
        this.serverLoading = false;
        this.tokenSuggestVisible = false;
        this.visible = true;
        this.$nextTick(() => {
            try {
                this.$refs.input?.focus();
            } catch (e) {
                // best-effort focus — non-fatal if the ref is not
                // mounted yet (the template guard ensures the input
                // exists when `visible` flips true).
            }
            // Resolve endpoint lazily if not yet found
            if (!this._searchEndpoint) {
                this._resolveSearchEndpoint();
            }
        });
    },

    close() {
        this.tokenSuggestVisible = false;
        this.visible = false;
    },

    get results() {
        if (!this.query) {
            return this.registry.map((item) => ({ item }));
        }
        if (!this.fuse) {
            return [];
        }
        return this.fuse.search(this.query);
    },

    /**
     * Called when the query input changes. Triggers token autocomplete
     * detection and debounced server fetch.
     */
    onQueryChange() {
        this.activeIndex = 0;
        this._updateTokenSuggestions();
        this._scheduleServerFetch();
    },

    /**
     * Detect if the query ends with a recognized token prefix and
     * populate tokenSuggestions for the overlay.
     */
    _updateTokenSuggestions() {
        const q = this.query;
        let matched = null;

        for (const prefix of TOKEN_PREFIXES) {
            if (q.endsWith(prefix) || (q.includes(' ') && q.split(' ').pop() === prefix.slice(0, -1) + ':')) {
                matched = prefix;
                break;
            }
            // Check if the query ends with a prefix without the colon yet
            // (progressive typing: 'account' → show 'account:' suggestion)
            if (q.endsWith(prefix.slice(0, -1)) && !q.includes(prefix)) {
                matched = prefix;
                break;
            }
        }

        if (matched && TOKEN_SUGGESTIONS[matched]) {
            this.tokenSuggestions = TOKEN_SUGGESTIONS[matched];
            this.tokenSuggestVisible = true;
            this.tokenActiveIndex = 0;
        } else {
            this.tokenSuggestions = [];
            this.tokenSuggestVisible = false;
        }
    },

    /**
     * Apply a token autocomplete suggestion: replace the token prefix
     * in the query with the full suggestion.
     */
    applyTokenSuggestion(suggestion) {
        // Replace the last word in the query with the suggestion
        const words = this.query.split(' ');
        words[words.length - 1] = suggestion;
        this.query = words.join(' ') + ' ';
        this.tokenSuggestVisible = false;
        this.$nextTick(() => {
            try { this.$refs.input?.focus(); } catch (_) {}
        });
        this._scheduleServerFetch();
    },

    /**
     * Debounced (200ms) server fetch — fires when query.length >= 2.
     * Keeps previous hits visible while the new fetch is in-flight (D-01).
     */
    _scheduleServerFetch() {
        if (this._debounceTimer) {
            clearTimeout(this._debounceTimer);
        }

        const q = this.query;

        if (q.length < 2) {
            this.serverTransactionHits = [];
            this.serverEntityHits = [];
            this.serverTotalCount = 0;
            this.serverLoading = false;
            return;
        }

        this._debounceTimer = setTimeout(() => {
            this._doServerFetch(q);
        }, 200);
    },

    async _doServerFetch(q) {
        // Resolve endpoint lazily
        if (!this._searchEndpoint) {
            this._resolveSearchEndpoint();
        }

        if (!this._searchEndpoint) {
            return;
        }

        this.serverLoading = true;
        try {
            await this._searchEndpoint.call('search', q);
            // Read fresh state from the Livewire component after the call
            this.serverTransactionHits = this._searchEndpoint.get('transactionHits') || [];
            this.serverEntityHits = this._searchEndpoint.get('entityHits') || [];
            this.serverTotalCount = this._searchEndpoint.get('totalCount') || 0;
        } catch (_) {
            // Non-fatal — gracefully degrade to Fuse-only results
        } finally {
            this.serverLoading = false;
        }
    },

    execute(item) {
        if (!item) {
            return;
        }
        // Persist the pick into the Recent cache via the Livewire
        // component (so a refresh keeps the rail seeded).
        try {
            if (window.Livewire) {
                window.Livewire.dispatch('palette:picked', { entry: item });
            }
        } catch (e) {
            // non-fatal — the Recent persistence is a UX nicety.
        }
        this.dispatchEntry(item);
        this.close();
    },

    /**
     * Execute a transaction search hit — navigate to its detail page and
     * persist the search query as a recent entry (D-10, D-13).
     */
    executeTransactionHit(hit) {
        // Persist as a recent transaction-search entry (D-10).
        // The entry URL is /transactions?q={query} so re-running navigates
        // to the full-results page (D-13).
        try {
            if (window.Livewire) {
                window.Livewire.dispatch('palette:picked', {
                    entry: {
                        id: 'search:txn:' + (hit.id || ''),
                        label: this.query,
                        icon: '⌕',
                        hint: 'Transaction search',
                        source: 'search',
                        url: '/transactions?q=' + encodeURIComponent(this.query),
                        handler: null,
                        name: null,
                        tier: null,
                    },
                });
            }
        } catch (_) {}

        if (hit.url) {
            window.location.href = hit.url;
        }
        this.close();
    },

    /**
     * Navigate to /transactions?q={query} and persist the search as a recent
     * entry (D-01 "See all results" row, D-10 recent searches).
     */
    seeAllResults() {
        const q = this.query;
        if (!q) {
            return;
        }

        try {
            if (window.Livewire) {
                window.Livewire.dispatch('palette:picked', {
                    entry: {
                        id: 'search:all:' + q,
                        label: q,
                        icon: '⌕',
                        hint: 'See all results',
                        source: 'search',
                        url: '/transactions?q=' + encodeURIComponent(q),
                        handler: null,
                        name: null,
                        tier: null,
                    },
                });
            }
        } catch (_) {}

        window.location.href = '/transactions?q=' + encodeURIComponent(q);
        this.close();
    },

    dispatchEntry(item) {
        if (item.source === 'dev' && item.name) {
            // DESTRUCTIVE-tier commands never reach this branch (the
            // server-side JSON-emit filter excludes them; the runner
            // page owns destructive execution through the triple-
            // gate).
            //
            // Two SAFE-tier sub-paths:
            //
            //   1. Command HAS args (the CommandSpec carries an
            //      argsSchema, surfaced as `hasArgs: true` on the
            //      registry JSON) → open the arg-prompt modal so
            //      the operator fills the form before the spawn
            //      fires. The modal is mounted globally and listens
            //      for `command-args:prompt` from every layout.
            //
            //   2. Command has NO args → dispatch `spawn-command`
            //      directly. The runner page's onSpawnCommand
            //      listener fires the actual spawn when ON
            //      /dev/artisan; off-page, fall through to the
            //      ?spawn= navigation so the runner page's mount()
            //      consumes the intent.
            if (item.hasArgs) {
                if (window.Livewire) {
                    window.Livewire.dispatch('command-args:prompt', {
                        name: item.name,
                        tier: item.tier || 'safe',
                        prefill: item.resolvedArgs || {},
                    });
                }
                return;
            }

            const onRunnerPage = typeof window !== 'undefined'
                && window.location.pathname.startsWith('/dev/artisan');

            if (onRunnerPage) {
                if (window.Livewire) {
                    window.Livewire.dispatch('spawn-command', {
                        name: item.name,
                        args: item.resolvedArgs || {},
                        tier: item.tier || 'safe',
                    });
                }
                return;
            }

            // Carry the spawn intent across the navigation so the
            // runner page picks it up after mount. encodeURIComponent
            // keeps a hostile-but-server-validated name safe in the
            // query string.
            window.location.href = '/dev/artisan?spawn=' + encodeURIComponent(item.name);
            return;
        }
        if (item.url) {
            window.location.href = item.url;
            return;
        }
        if (item.handler && window.Livewire) {
            window.Livewire.dispatch(item.handler);
        }
    },

    onKey(e) {
        if (!this.visible) {
            return;
        }
        if (e.key === 'Escape') {
            e.preventDefault();
            // Esc dismisses token autocomplete overlay first (D-26),
            // then closes the palette if no overlay was open.
            if (this.tokenSuggestVisible) {
                this.tokenSuggestVisible = false;
                return;
            }
            this.close();
            return;
        }

        // Token autocomplete keyboard nav
        if (this.tokenSuggestVisible && this.tokenSuggestions.length > 0) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                this.tokenActiveIndex = Math.min(this.tokenActiveIndex + 1, this.tokenSuggestions.length - 1);
                return;
            }
            if (e.key === 'ArrowUp') {
                e.preventDefault();
                this.tokenActiveIndex = Math.max(this.tokenActiveIndex - 1, 0);
                return;
            }
            if (e.key === 'Enter' || e.key === 'Tab') {
                e.preventDefault();
                const suggestion = this.tokenSuggestions[this.tokenActiveIndex];
                if (suggestion) {
                    this.applyTokenSuggestion(suggestion);
                }
                return;
            }
        }

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            this.activeIndex = Math.min(this.activeIndex + 1, this.results.length - 1);
            return;
        }
        if (e.key === 'ArrowUp') {
            e.preventDefault();
            this.activeIndex = Math.max(this.activeIndex - 1, 0);
            return;
        }
        if (e.key === 'Enter') {
            const hit = this.results[this.activeIndex];
            if (hit && hit.item) {
                e.preventDefault();
                this.execute(hit.item);
            }
        }
    },
});
