/**
 * Arrow-key navigation for a `role="tablist"`.
 *
 * Mounted as `x-data="tabStrip()" x-on:keydown="onKey($event)"` on the strip
 * element itself. The strip renders `tabindex="-1"` on every tab but the
 * selected one, so Tab reaches the strip and the arrow keys walk it — the
 * roving pair the ARIA tabs pattern expects.
 *
 * Focus only. Activation stays with Enter, Space or the pointer, so arrowing
 * past a tab never fires the wire:click behind it.
 */
export const tabStrip = () => ({
    onKey(e) {
        const step = { ArrowRight: 1, ArrowLeft: -1, Home: 'first', End: 'last' }[e.key];
        if (step === undefined) {
            return;
        }

        const tabs = Array.from(this.$el.querySelectorAll('[role="tab"]'));
        if (tabs.length === 0) {
            return;
        }

        e.preventDefault();

        if (step === 'first') {
            tabs[0].focus();

            return;
        }
        if (step === 'last') {
            tabs[tabs.length - 1].focus();

            return;
        }

        const current = tabs.indexOf(document.activeElement);
        tabs[current === -1 ? 0 : (current + step + tabs.length) % tabs.length].focus();
    },
});
