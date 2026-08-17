/*
 * In-app time picker, sibling to the date picker and for the same reason.
 *
 * `<input type="time">` renders the ENGINE's picker in the SYSTEM locale. A
 * phone set to US English put a 12-hour AM/PM picker inside a Dutch UI that
 * writes 21:30 everywhere else, and no attribute or stylesheet reaches it.
 *
 * The value stays ISO `HH:MM` — exactly what the native control submits — so
 * wire:model, validation and every server-side rule are unchanged. Only the
 * presentation is ours, and it comes from Carbon's per-locale `LT` pattern:
 * `HH:mm` for every European language the product ships, `h:mm A` for English.
 * That difference IS the bug, so the component switches on it rather than
 * assuming either one.
 */
export function timePicker(config) {
    const twelveHour = Boolean(config.twelveHour);
    const meridiem = config.meridiem || ['AM', 'PM'];
    const minuteStep = Number(config.minuteStep || 5);

    return {
        open: false,
        // Entangled with the caller's Livewire property by `x-modelable` in
        // the view, the same way the date picker binds. There is no second
        // copy of the value to fall out of step with.
        value: '',

        parts() {
            const m = /^(\d{1,2}):(\d{2})$/.exec(this.value || '');

            if (! m) {
                return null;
            }

            const h = Number(m[1]);
            const min = Number(m[2]);

            return (h >= 0 && h <= 23 && min >= 0 && min <= 59) ? [h, min] : null;
        },

        /** The value as this locale writes it: 21:30, or 9:30 PM. */
        get display() {
            const p = this.parts();

            if (! p) {
                return '';
            }

            const [h, m] = p;
            const mm = String(m).padStart(2, '0');

            if (! twelveHour) {
                return String(h).padStart(2, '0') + ':' + mm;
            }

            // 12 rather than 0, and 12 rather than 24 — midnight and noon are
            // the two the naive modulo gets wrong.
            const display = h % 12 === 0 ? 12 : h % 12;

            return display + ':' + mm + ' ' + (h < 12 ? meridiem[0] : meridiem[1]);
        },

        /** Hours offered by the picker: 1–12 where the locale reads 12-hour. */
        get hours() {
            if (! twelveHour) {
                return Array.from({ length: 24 }, (_, i) => ({ label: String(i).padStart(2, '0'), hour24: i }));
            }

            return Array.from({ length: 12 }, (_, i) => {
                const shown = i === 0 ? 12 : i;

                return { label: String(shown), hour24: shown };
            });
        },

        get minutes() {
            const out = [];

            for (let m = 0; m < 60; m += minuteStep) {
                out.push(String(m).padStart(2, '0'));
            }

            return out;
        },

        get isPm() {
            const p = this.parts();

            return p ? p[0] >= 12 : false;
        },

        isHour(hour24) {
            const p = this.parts();

            if (! p) {
                return false;
            }

            return twelveHour ? (p[0] % 12 === hour24 % 12) : p[0] === hour24;
        },

        isMinute(mm) {
            const p = this.parts();

            return p ? String(p[1]).padStart(2, '0') === mm : false;
        },

        commit(h, m) {
            this.value = String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
        },

        chooseHour(hour24) {
            const p = this.parts() || [0, 0];
            let h = hour24;

            if (twelveHour) {
                // Keep whichever half of the day is already selected.
                h = (hour24 % 12) + (this.isPm ? 12 : 0);
            }

            this.commit(h, p[1]);
        },

        chooseMinute(mm) {
            const p = this.parts() || [0, 0];

            this.commit(p[0], Number(mm));
        },

        setMeridiem(pm) {
            const p = this.parts() || [0, 0];
            const base = p[0] % 12;

            this.commit(base + (pm ? 12 : 0), p[1]);
        },

        clear() {
            this.value = '';
            this.open = false;
        },
    };
}
