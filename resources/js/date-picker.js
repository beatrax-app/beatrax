/*
 * In-app date picker.
 *
 * `<input type="date">` renders the ENGINE's picker in the SYSTEM locale, and
 * ignores the document's lang entirely: on a Dutch phone set to English the
 * calendar opened in English, started its week on Sunday, and then rendered
 * the chosen date as 08/31/2026 inside a UI that writes € 1.234,56 everywhere
 * else. No attribute or stylesheet reaches any of that.
 *
 * So the calendar is drawn here instead. Every piece of language and format
 * data is computed server-side by Carbon for the ACTIVE app locale and handed
 * in — month names, weekday names, the first day of the week, and the short
 * date pattern. Carbon carries its own translations, which matters because the
 * mobile build ships ICU with English-only data; anything routed through ICU
 * would be English again, or would throw.
 *
 * `value` is always ISO `YYYY-MM-DD`, and the view's `x-modelable` entangles it
 * with the caller's `wire:model`, so Livewire sees exactly what the native
 * control used to give it. Only the presentation changed.
 */
export function datePicker(config) {
    const pattern = config.pattern || 'YYYY-MM-DD';
    const months = config.months || [];
    const weekdays = config.weekdays || [];
    // 0 = Sunday … 6 = Saturday, as JS Date reports it.
    const firstDow = Number(config.firstDow || 1);

    return {
        open: false,
        // Entangled with the caller's Livewire property by `x-modelable` in
        // the view. Assigning it here is what makes the server agree; there is
        // no second copy of the value anywhere to fall out of step with.
        value: '',
        viewYear: 1970,
        viewMonth: 0,

        /**
         * Open/close, and when opening make sure the calendar is actually on
         * screen. The field is often the last row of a phone bottom sheet, so
         * a calendar that simply drops below it lands past the fold inside the
         * sheet's own scroll area — visible only to someone who thinks to
         * scroll a panel that has just sprouted content they cannot see.
         * `block: 'nearest'` scrolls every scrollable ancestor by the least
         * amount that reveals it, and does nothing when it already fits.
         */
        toggle() {
            this.open = ! this.open;

            if (! this.open) {
                return;
            }

            this.$nextTick(() => {
                const popover = this.$el.querySelector('[role="dialog"]');

                popover && popover.scrollIntoView({ block: 'nearest', inline: 'nearest' });
            });
        },

        init() {
            this.syncViewToValue();

            // The bound property can change from the server (a reset, a
            // filter cleared elsewhere, the entanglement handing over the
            // initial value a microtask after init). Follow it rather than
            // holding a stale copy the user cannot see is stale.
            this.$watch('value', () => this.syncViewToValue());
        },

        syncViewToValue() {
            const parts = this.splitValue();
            const base = parts ? new Date(parts[0], parts[1] - 1, parts[2]) : new Date();

            this.viewYear = base.getFullYear();
            this.viewMonth = base.getMonth();
        },

        splitValue() {
            const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(this.value || '');

            return match ? [Number(match[1]), Number(match[2]), Number(match[3])] : null;
        },

        /** The value as the reader's locale writes it, e.g. 31-08-2026. */
        get display() {
            const parts = this.splitValue();

            if (! parts) {
                return '';
            }

            const [year, month, day] = parts;

            // YYYY first: it cannot contain MM or DD, so the later two
            // replacements cannot chew into a year they already produced.
            return pattern
                .replace('YYYY', String(year).padStart(4, '0'))
                .replace('MM', String(month).padStart(2, '0'))
                .replace('DD', String(day).padStart(2, '0'));
        },

        get monthLabel() {
            return (months[this.viewMonth] || '') + ' ' + this.viewYear;
        },

        get weekdayLabels() {
            return weekdays;
        },

        /**
         * Six rows of seven, so the grid never changes height between months
         * and the control does not jump under the pointer.
         */
        get days() {
            const first = new Date(this.viewYear, this.viewMonth, 1);
            const lead = (first.getDay() - firstDow + 7) % 7;
            const start = new Date(this.viewYear, this.viewMonth, 1 - lead);
            const cells = [];

            for (let i = 0; i < 42; i++) {
                const date = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);

                cells.push({
                    iso: this.toIso(date),
                    day: date.getDate(),
                    outside: date.getMonth() !== this.viewMonth,
                });
            }

            return cells;
        },

        toIso(date) {
            return String(date.getFullYear()).padStart(4, '0')
                + '-' + String(date.getMonth() + 1).padStart(2, '0')
                + '-' + String(date.getDate()).padStart(2, '0');
        },

        isSelected(iso) {
            return this.value === iso;
        },

        isToday(iso) {
            return this.toIso(new Date()) === iso;
        },

        shiftMonth(delta) {
            const shifted = new Date(this.viewYear, this.viewMonth + delta, 1);

            this.viewYear = shifted.getFullYear();
            this.viewMonth = shifted.getMonth();
        },

        choose(iso) {
            this.value = iso;
            this.open = false;
        },

        chooseToday() {
            this.choose(this.toIso(new Date()));
        },

        clear() {
            this.value = '';
            this.open = false;
        },
    };
}
