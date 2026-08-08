import ApexCharts from 'apexcharts';
import { palette } from './palette.js';
import './lock.js';

// Stamp ApexCharts on the global so Alpine `x-init` handlers in
// chart-rendering Blade components can call `new window.ApexCharts(...)`
// without re-importing the module on every component instance.
window.ApexCharts = ApexCharts;

// Adjust ApexCharts options for the active theme so chart lines,
// grid lines, and axis labels stay legible when `<html class="dark">`
// flips the page into dark mode. Server-rendered options bake in
// slate-900 line colors that vanish against slate-950 backgrounds —
// this helper swaps them at hydration time. Mutates and returns the
// same object for chained use.
window.beatraxApplyChartTheme = function (options) {
    const isDark = document.documentElement.classList.contains('dark');
    if (!isDark || !options || typeof options !== 'object') {
        return options;
    }
    const slate300 = '#cbd5e1';
    const slate700 = '#334155';
    const emerald400 = '#34d399';
    const rose400 = '#fb7185';
    const lineColorMap = {
        '#0f172a': emerald400,
        '#047857': emerald400,
        '#be123c': rose400,
    };
    if (Array.isArray(options.colors)) {
        options.colors = options.colors.map((c) => lineColorMap[(c || '').toLowerCase()] || c);
    }
    options.grid = Object.assign({}, options.grid || {}, { borderColor: slate700 });
    const tintLabels = (axis) => {
        if (!axis) return axis;
        const labels = axis.labels || {};
        return Object.assign({}, axis, {
            labels: Object.assign({}, labels, {
                style: Object.assign({}, labels.style || {}, { colors: slate300 }),
            }),
        });
    };
    options.xaxis = tintLabels(options.xaxis);
    options.yaxis = tintLabels(options.yaxis);
    options.tooltip = Object.assign({}, options.tooltip || {}, { theme: 'dark' });
    return options;
};

// Register the command palette Alpine factory. The
// x-data="palette(registry, recent)" directive inside the
// CommandPaletteModal Blade view resolves through this
// registration. Wraps Fuse.js with the configured weights +
// threshold + ignoreLocation.
//
// Phase 4 additions inside the same alpine:init handler:
//   - mobileNav store: drawer open/close/toggle for the mobile shell (D-01)
//   - platform store: detects macOS for ⌘K vs Ctrl+K kbd labels (D-04)
/**
 * In-page QR scanner for the mobile pairing screen.
 *
 * The scanner plugin's own surface is a separate full-screen activity, which
 * over the pairing page reads as the app navigating away. This keeps the
 * preview inside the viewfinder frame instead: the WebView's own camera via
 * getUserMedia, decoded by the platform BarcodeDetector — a browser API, not
 * a bundled decode library.
 *
 * Neither is guaranteed. When the WebView will not hand over a camera, or the
 * runtime has no BarcodeDetector, probe() reports unsupported and toggle()
 * defers to the plugin's full-screen scanner through $wire.startScan(), so
 * the affordance never promises a preview it cannot deliver.
 */
/*
 * Submits a plain POST form through fetch() instead of a native form submit.
 *
 * The mobile shell intercepts WebView requests and replays them into the
 * embedded PHP runtime. Its form path has two defects: it builds the body with
 * `new FormData(form)`, which omits the submitter button's own name/value, and
 * the replayed request loses the POST method — Laravel answers 405 with
 * `Allow: POST`, redirects, and the app loops on the error page. Sign-out and
 * the pre-auth language switch are both plain POST forms, so both were dead on
 * device with no error surfaced anywhere.
 *
 * fetch() is intercepted correctly — it is the path every Livewire round-trip
 * already takes — so routing form submits through it fixes both defects
 * without moving any endpoint. Desktop is unaffected either way.
 */
window.beatraxSubmitPostForm = async function (form, submitter) {
    const body = new URLSearchParams(new FormData(form));

    // FormData omits the submitter; re-add it, which is the whole payload for
    // a form whose value lives on the button (the locale switcher).
    if (submitter && submitter.name) {
        body.set(submitter.name, submitter.value);
    }

    const response = await fetch(form.getAttribute('action'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body,
        redirect: 'follow',
    });

    // Follow wherever the server sent us. A redirected response exposes its
    // final URL; anything else means "re-render where you are".
    if (response.redirected && response.url) {
        window.location.assign(response.url);

        return;
    }

    window.location.reload();
};

function beatraxInlineScanner($wire) {
    return {
        live: false,
        supported: false,
        stream: null,
        detector: null,
        frame: null,

        // Feature-detect only — deliberately does NOT open the camera. A
        // preview that starts on page load is a privacy surprise, and on
        // this screen the user has not asked to scan anything yet.
        probe() {
            this.supported = typeof window.BarcodeDetector === 'function'
                && !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);
        },

        toggle() {
            if (this.live) {
                this.stop();
                return;
            }
            if (!this.supported) {
                // No in-page camera here — hand off to the native scanner.
                $wire.startScan();
                return;
            }
            this.start();
        },

        async start() {
            try {
                this.detector = new window.BarcodeDetector({ formats: ['qr_code'] });
                this.stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: 'environment' },
                });
                this.$refs.preview.srcObject = this.stream;
                await this.$refs.preview.play();
                this.live = true;
                this.tick();
            } catch (e) {
                // Permission refused, no camera, or the WebView never
                // answered: fall back rather than strand the user staring at
                // an empty frame.
                this.stop();
                this.supported = false;
                $wire.startScan();
            }
        },

        // requestAnimationFrame rather than setInterval so decoding stops
        // when the page is backgrounded — a camera running behind a
        // backgrounded finance app is exactly what the privacy veil exists
        // to prevent.
        tick() {
            this.frame = requestAnimationFrame(async () => {
                if (!this.live) return;
                try {
                    const codes = await this.detector.detect(this.$refs.preview);
                    const hit = codes.find((c) => (c.rawValue || '').startsWith('beatrax://'));
                    if (hit) {
                        this.stop();
                        $wire.submitCode(hit.rawValue);
                        return;
                    }
                } catch (e) {
                    // A single failed decode is normal (blurred frame);
                    // keep looping rather than tearing the preview down.
                }
                this.tick();
            });
        },

        stop() {
            this.live = false;
            if (this.frame) {
                cancelAnimationFrame(this.frame);
                this.frame = null;
            }
            if (this.stream) {
                this.stream.getTracks().forEach((t) => t.stop());
                this.stream = null;
            }
        },

        destroy() {
            this.stop();
        },
    };
}

document.addEventListener('alpine:init', () => {
    if (window.Alpine) {
        window.Alpine.data('palette', palette);
        window.Alpine.data('beatraxInlineScanner', beatraxInlineScanner);

        // Mobile navigation drawer state (Phase 4, D-01)
        window.Alpine.store('mobileNav', {
            drawerOpen: false,
            open() { this.drawerOpen = true; },
            close() { this.drawerOpen = false; },
            toggle() { this.drawerOpen = !this.drawerOpen; },
        });

        // Alpine stores survive a wire:navigate page swap, so a drawer opened
        // to tap a nav link would still be open on the page it navigated to,
        // covering it. Close on arrival — the destination is what the user
        // asked for, not the menu they used to get there.
        document.addEventListener('livewire:navigated', () => {
            window.Alpine.store('mobileNav').close();
        });

        // Platform detection for ⌘K vs Ctrl+K labels (Phase 4, D-04).
        // Uses the modern userAgentData API first (Chromium 90+), falls back
        // to the legacy navigator.platform string for Safari + Firefox.
        window.Alpine.store('platform', {
            isMac: Boolean(
                (typeof navigator !== 'undefined') && (
                    (navigator.userAgentData?.platform === 'macOS')
                    || (navigator.platform || '').startsWith('Mac')
                )
            ),
        });
    }
});

