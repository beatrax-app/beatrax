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
export const palette = (registry, recent) => ({
    visible: false,
    query: '',
    activeIndex: 0,
    recent: Array.isArray(recent) ? recent : [],
    registry: Array.isArray(registry) ? registry : [],
    fuse: null,
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
    },
    open() {
        this.query = '';
        this.activeIndex = 0;
        this.visible = true;
        this.$nextTick(() => {
            try {
                this.$refs.input?.focus();
            } catch (e) {
                // best-effort focus — non-fatal if the ref is not
                // mounted yet (the template guard ensures the input
                // exists when `visible` flips true).
            }
        });
    },
    close() {
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
    dispatchEntry(item) {
        if (item.source === 'dev' && item.name) {
            // The palette dispatches the spawn intent only — the arg
            // form lives on /dev/artisan. DESTRUCTIVE-tier commands
            // never reach this branch (the server-side JSON-emit
            // filter excludes them; the runner page owns destructive
            // execution through the triple-gate).
            //
            // When the operator is ON /dev/artisan, the runner page
            // is mounted and listens for the spawn-command event.
            // When they are anywhere else, dispatching first and then
            // navigating loses the dispatch (Livewire unmounts before
            // the runner page hydrates). Instead, navigate to
            // /dev/artisan?spawn=<name> and let the runner page's
            // mount() consume the query param.
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
            this.close();
            return;
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
