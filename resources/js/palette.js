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
            // Per <interfaces> I-3 fix: palette dispatches the spawn
            // intent only. The arg form lives on /dev/artisan; this
            // call carries any pre-resolved args + the tier so the
            // runner page can either route through the triple-gate
            // (DESTRUCTIVE) or the SAFE spawn pipeline directly.
            // DESTRUCTIVE-tier commands never reach this branch (the
            // server-side JSON-emit filter excludes them per D-41).
            if (window.Livewire) {
                window.Livewire.dispatch('spawn-command', {
                    name: item.name,
                    args: item.resolvedArgs || {},
                    tier: item.tier || 'safe',
                });
            }
            // If the palette was opened outside /dev/artisan, the
            // runner page is not mounted to receive the dispatch.
            // Navigate there as a fallback so the spawn intent is
            // never silently dropped.
            if (item.url || (typeof window !== 'undefined' && !window.location.pathname.startsWith('/dev/artisan'))) {
                window.location.href = '/dev/artisan';
            }
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
