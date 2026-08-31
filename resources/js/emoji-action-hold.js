/**
 * Press-and-hold reveals an icon-only action's verb on a touch screen.
 *
 * `title` is a hover tooltip and a finger never hovers, so every
 * x-core::emoji-action was an unlabelled picture on a phone. Holding is what
 * Android's own toolbars answer with, so the gesture is the platform's rather
 * than one invented here.
 *
 * Mounted on the wrapper, not the button. The click a long press ends with has
 * to be swallowed before wire:click sees it, and a capture listener on the
 * ancestor is the only placement the DOM orders ahead of the target's own
 * listeners — at the target itself, capture and bubble run in registration
 * order and Livewire registers first.
 */
export const emojiActionHold = () => {
    // Below the 500ms both platforms open their own long-press menu at, so the
    // tip is what appears rather than a race with the callout.
    const HOLD_MS = 450;

    // A press that travels this far was a scroll starting on the button.
    const DRIFT_PX = 10;

    const GAP_PX = 8;

    // Clearance above the mark. A thumb's pad reaches well past its own
    // contact point, so the 8px edge inset this used to reuse put the tip
    // under the finger that summoned it.
    const LIFT_PX = 22;

    // The tip is only fully readable once the finger is off it, so it
    // outlives the release rather than vanishing with it.
    const LINGER_MS = 1200;

    return {
        shown: false,
        timer: null,
        fade: null,
        swallow: false,
        origin: null,

        press(event) {
            // A mouse already has `title`, and hijacking its click would break
            // a desktop that works.
            if (event.pointerType === 'mouse') {
                return;
            }

            this.clearFade();
            this.swallow = false;
            this.origin = { x: event.clientX, y: event.clientY };
            this.timer = window.setTimeout(() => {
                this.timer = null;
                this.shown = true;
                this.$nextTick(() => this.place());
            }, HOLD_MS);
        },

        drift(event) {
            if (this.origin === null) {
                return;
            }

            if (Math.abs(event.clientX - this.origin.x) > DRIFT_PX
                || Math.abs(event.clientY - this.origin.y) > DRIFT_PX) {
                this.reset();
            }
        },

        release() {
            // Only a press that got as far as showing the tip swallows its
            // click; a short tap has to reach the action untouched.
            if (this.shown) {
                this.swallow = true;
                this.fade = window.setTimeout(() => {
                    this.fade = null;
                    this.shown = false;
                }, LINGER_MS);
            }

            this.disarm();
        },

        // Android raises contextmenu ~130ms after the tip appears, mid-hold.
        // Preventing it suppresses the OS callout; doing nothing else is the
        // point, because the reset that used to run here blanked the tip the
        // hold had just produced and disarmed the guard on its release.
        callout(event) {
            if (this.origin === null) {
                return;
            }

            event.preventDefault();
        },

        // Only for a pointer that will raise no click. Measured order on a
        // touch screen is pointerdown, pointerup, pointerout, pointerleave,
        // click — so a reset wired to pointerleave disarms the guard in the
        // gap before the click it was armed for, and the hold archives the row.
        reset() {
            this.swallow = false;
            this.stop();
        },

        disarm() {
            if (this.timer !== null) {
                window.clearTimeout(this.timer);
                this.timer = null;
            }

            this.origin = null;
        },

        clearFade() {
            if (this.fade !== null) {
                window.clearTimeout(this.fade);
                this.fade = null;
            }
        },

        stop() {
            this.disarm();
            this.clearFade();
            this.shown = false;
        },

        guard(event) {
            if (! this.swallow) {
                return;
            }

            this.swallow = false;
            event.stopImmediatePropagation();
            event.preventDefault();
        },

        tipElement() {
            const template = this.$el.querySelector('template');

            return this.$refs.tip ?? template?._x_teleport ?? null;
        },

        place() {
            const button = this.$el.querySelector('.emoji-action');
            const tip = this.tipElement();
            if (button === null || tip === null) {
                return;
            }

            const box = button.getBoundingClientRect();
            const width = tip.offsetWidth;
            const height = tip.offsetHeight;

            // The teleported tip flushes its own x-show in a later cycle, so
            // the first measurement can land on a display:none box. Clamping
            // against a width of 0 put the Archive tip 9.6px off a 375px
            // screen, measured.
            if (width === 0 || height === 0) {
                window.requestAnimationFrame(() => this.place());

                return;
            }

            const x = Math.max(GAP_PX, Math.min(
                box.left + (box.width - width) / 2,
                window.innerWidth - width - GAP_PX,
            ));

            // Above the mark, where the finger is not; below it only when the
            // mark is against the top of the screen.
            const above = box.top - height - LIFT_PX;
            const y = above >= GAP_PX ? above : box.bottom + LIFT_PX;

            tip.style.transform = `translate(${Math.round(x)}px, ${Math.round(y)}px)`;
        },
    };
};
