import { createPaletteMatcher } from './palette-match.js';

/**
 * Alpine factory for the command palette modal.
 *
 * Mounted by `Modules/DevMode/Resources/views/livewire/command-palette-modal.blade.php`
 * via `<div x-data="palette(registry, recent)">`. The two arguments
 * are the server-emitted JSON registry (already filtered by
 * `is_developer` at the controller layer) and the seeded Recent
 * list.
 *
 * Filtering the registry — the tiers, the plural and accent folding, and the
 * locked Fuse.js weights — lives in ./palette-match.js.
 *
 * Server-backed search:
 *   - When query.length >= 2, a debounced (200ms) server fetch fires via
 *     $wire.search(q) on the mounted search.palette-search-endpoint component.
 *   - Server hits are merged into the results pane ABOVE Fuse results.
 *   - Previous server hits shown while a new fetch is in-flight (no blank flash).
 *   - A loading spinner appears in the input trailing slot during fetch.
 *
 * Token autocomplete:
 *   - When the query ends with a recognized token prefix (account:, amount:,
 *     after:, before:, category:), a suggestion overlay appears.
 *   - Esc dismisses the overlay without closing the palette.
 *
 * Selecting a row:
 *   - source `dev` (or `dev.cmd.*` id)    → dispatch `spawn-command`
 *     with the resolved-args payload; the artisan runner page is the
 *     SOLE listener. The palette is a
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

/** Every activatable row in the palette carries this hook. */
const ROW_SELECTOR = '[data-palette-row]';

const FOCUSABLE_SELECTOR = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]';

export const palette = (registry, recent) => ({
    visible: false,
    query: '',
    activeIndex: 0,
    recent: Array.isArray(recent) ? recent : [],
    registry: Array.isArray(registry) ? registry : [],
    match: null,

    // Server-backed search state (08-05)
    serverTransactionHits: [],
    serverEntityHits: [],
    serverTotalCount: 0,
    serverLoading: false,
    _debounceTimer: null,
    // wire:id (string) of the PaletteSearchEndpoint component. We store the id,
    // never the component object — see _resolveSearchEndpoint() for why.
    _searchEndpointId: null,

    // Where focus was when the palette opened, so Esc can hand it back.
    // A DOM node is one of the types Alpine's reactivity leaves alone, so
    // this reads back as the element the browser knows.
    _returnFocusTo: null,

    // Token autocomplete state
    tokenSuggestions: [],
    tokenSuggestVisible: false,
    tokenActiveIndex: 0,

    init() {
        this.match = createPaletteMatcher(this.registry);

        // Resolve the search.palette-search-endpoint Livewire component
        // so we can call $wire.search() on it. Use a lazy resolver so
        // the component is guaranteed to be mounted by the time the user
        // starts typing.
        this._resolveSearchEndpoint();
    },

    _resolveSearchEndpoint() {
        // Resolve the mounted PaletteSearchEndpoint component by its
        // data-testid attribute. The component renders a hidden root
        // element with data-testid="palette-search-endpoint".
        //
        // We store ONLY the wire:id string — never the component object.
        // This Alpine component's `this` is a reactive proxy; assigning a
        // Livewire component instance onto it wraps the instance in Alpine's
        // reactivity, and a subsequent `.call()` leaks the proxy's raw-target
        // key (`__v_raw`) as the method name → 500 MethodNotFoundException.
        // Keeping the id (a plain string) reactive is safe; the live
        // component is fetched fresh and unwrapped at call time in `_endpoint()`.
        const findEndpoint = () => {
            try {
                const el = document.querySelector('[data-testid="palette-search-endpoint"]');
                if (el && window.Livewire) {
                    this._searchEndpointId = el.getAttribute('wire:id');
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

    // Resolve the live Livewire `$wire` proxy fresh at call time.
    //
    // Two hazards, both avoided here:
    //  1. NEVER store the result on `this` — this Alpine component is reactive,
    //     so an assigned $wire proxy gets double-wrapped and breaks (see
    //     _resolveSearchEndpoint). Resolving into a local each call keeps it
    //     outside Alpine's reactivity.
    //  2. NEVER run it through Alpine.raw()/toRaw(). Livewire 4's find() returns
    //     the $wire PROXY, which turns any unknown property read into a
    //     server-method call. toRaw() probes `.__v_raw`, so the proxy hands back
    //     a caller for a (non-existent) `__v_raw` method → 500
    //     MethodNotFoundException. Use the proxy directly.
    _endpoint() {
        if (!this._searchEndpointId || !window.Livewire) {
            return null;
        }
        return window.Livewire.find(this._searchEndpointId) || null;
    },

    open() {
        if (!this.visible) {
            this._returnFocusTo = document.activeElement instanceof HTMLElement ? document.activeElement : null;
        }
        this.query = '';
        this.activeIndex = 0;
        this.serverTransactionHits = [];
        this.serverEntityHits = [];
        this.serverTotalCount = 0;
        this.serverLoading = false;
        this.tokenSuggestVisible = false;
        this.visible = true;
        window.Alpine?.store('overlay')?.add('palette');
        this.$nextTick(() => {
            try {
                this.$refs.input?.focus();
            } catch (e) {
                // best-effort focus — non-fatal if the ref is not
                // mounted yet (the template guard ensures the input
                // exists when `visible` flips true).
            }
            // Resolve endpoint lazily if not yet found
            if (!this._searchEndpointId) {
                this._resolveSearchEndpoint();
            }
        });
    },

    close() {
        this.tokenSuggestVisible = false;
        this.visible = false;
        window.Alpine?.store('overlay')?.remove('palette');

        const origin = this._returnFocusTo;
        this._returnFocusTo = null;
        if (origin && document.contains(origin)) {
            this.$nextTick(() => {
                try {
                    origin.focus();
                } catch (_) {
                    // The opener may have gone with a navigation.
                }
            });
        }
    },

    _rows() {
        const panel = this.$refs.panel;

        return panel ? Array.from(panel.querySelectorAll(ROW_SELECTOR)) : [];
    },

    /** Walk DOM focus along the rows, carrying the highlight with it. */
    _moveRowFocus(delta) {
        const rows = this._rows();
        if (rows.length === 0) {
            return;
        }

        const current = rows.indexOf(document.activeElement);
        const next = rows[Math.min(Math.max(current + delta, 0), rows.length - 1)];
        if (!next) {
            return;
        }

        const index = next.getAttribute('data-palette-index');
        if (index !== null) {
            this.activeIndex = Number(index);
        }
        next.focus();
    },

    /**
     * Keep Tab inside the open dialog. Without this the next Tab from the
     * last row lands on the page behind the scrim, which is inert to the
     * eye and still reachable by keyboard.
     */
    _trapTab(e) {
        const panel = this.$refs.panel;
        if (!panel) {
            return;
        }

        const stops = Array.from(panel.querySelectorAll(FOCUSABLE_SELECTOR)).filter((el) => el.tabIndex >= 0);
        if (stops.length === 0) {
            return;
        }

        const first = stops[0];
        const last = stops[stops.length - 1];

        if (!panel.contains(document.activeElement)) {
            e.preventDefault();
            first.focus();
            return;
        }
        if (e.shiftKey && document.activeElement === first) {
            e.preventDefault();
            last.focus();
            return;
        }
        if (!e.shiftKey && document.activeElement === last) {
            e.preventDefault();
            first.focus();
        }
    },

    get results() {
        if (!this.match) {
            return [];
        }
        return this.match(this.query);
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
 * Keeps previous hits visible while the new fetch is in-flight.
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
        if (!this._searchEndpointId) {
            this._resolveSearchEndpoint();
        }

        const endpoint = this._endpoint();
        if (!endpoint) {
            return;
        }

        this.serverLoading = true;
        try {
            await endpoint.call('search', q);
            // Read fresh state from the Livewire component after the call
            this.serverTransactionHits = endpoint.get('transactionHits') || [];
            this.serverEntityHits = endpoint.get('entityHits') || [];
            this.serverTotalCount = endpoint.get('totalCount') || 0;
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
 * persist the search query as a recent entry.
     */
    executeTransactionHit(hit) {
        // Persist as a recent transaction-search entry. The entry URL is
        // /transactions?q={query} so re-running navigates to the
        // full-results page.
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
 * entry (the "See all results" row).
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
            // Esc dismisses token autocomplete overlay first,
            // then closes the palette if no overlay was open.
            if (this.tokenSuggestVisible) {
                this.tokenSuggestVisible = false;
                return;
            }
            this.close();
            return;
        }

        // A row is a real button, so the browser already turns Enter on a
        // focused row into a click on it. Acting on the same key here would
        // run that row a second time.
        const onRow = e.target instanceof Element && e.target.closest(ROW_SELECTOR) !== null;

        // Token autocomplete keyboard nav
        if (!onRow && this.tokenSuggestVisible && this.tokenSuggestions.length > 0) {
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

        if (e.key === 'Tab') {
            this._trapTab(e);
            return;
        }

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (onRow) {
                this._moveRowFocus(1);
                return;
            }
            this.activeIndex = Math.min(this.activeIndex + 1, this.results.length - 1);
            return;
        }
        if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (onRow) {
                this._moveRowFocus(-1);
                return;
            }
            this.activeIndex = Math.max(this.activeIndex - 1, 0);
            return;
        }
        if (e.key === 'Enter') {
            if (onRow) {
                return;
            }
            const hit = this.results[this.activeIndex];
            if (hit && hit.item) {
                e.preventDefault();
                this.execute(hit.item);
            }
        }
    },
});
