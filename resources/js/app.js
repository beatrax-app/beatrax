
/**
 * Report the client's colour scheme to the server.
 *
 * A `system` theme is decided by prefers-color-scheme, which the server
 * cannot read. Without this it renders one answer and the client computes
 * another a moment later, and the page visibly changes theme between them.
 * Reporting it as a cookie means the NEXT render already agrees.
 */
(function reportColorScheme() {
    const write = (scheme) => {
        // Lax so it rides ordinary navigations, including the one the idle
        // lock performs; a year so it survives app restarts.
        document.cookie = 'beatrax_scheme=' + scheme + '; path=/; max-age=31536000; SameSite=Lax';
    };

    try {
        const query = window.matchMedia('(prefers-color-scheme: dark)');
        write(query.matches ? 'dark' : 'light');

        // Follow the OS while the app is open, so a scheme change mid-session
        // is already reported by the time the next page renders.
        const onChange = (event) => write(event.matches ? 'dark' : 'light');

        if (typeof query.addEventListener === 'function') {
            query.addEventListener('change', onChange);
        }
    } catch (e) {
        // No matchMedia: the pre-paint script is unavailable too, and the
        // server keeps its own fallback.
    }
})();
import ApexCharts from 'apexcharts';
import { palette } from './palette.js';
import { datePicker } from './date-picker.js';
import { timePicker } from './time-picker.js';
import './lock.js';
import './mobile-upload.js';

// Stamp ApexCharts on the global so Alpine `x-init` handlers in
// chart-rendering Blade components can call `new window.ApexCharts(...)`
// without re-importing the module on every component instance.
window.ApexCharts = ApexCharts;

/*
 * Replace Livewire's page-expired prompt with an in-page reload.
 *
 * On a 419 Livewire calls window.confirm("This page has expired. Would you
 * like to refresh the page?"). A native dialog inside the Android WebView
 * blocks the whole runtime — it froze the app hard enough that the remote
 * debugger stopped answering — and the OK path reloads, which on the
 * recovery-codes screen destroys codes that are never shown again.
 *
 * Reloading without asking is the honest behaviour: the session is gone
 * either way, and the question offered no outcome the user could act on.
 */
document.addEventListener('livewire:init', () => {
    if (!window.Livewire || typeof window.Livewire.hook !== 'function') {
        return;
    }

    window.Livewire.hook('request', ({ fail }) => {
        fail(({ status, preventDefault }) => {
            // A stale endpoint answers 404 for every update the page will
            // ever send: Livewire derives its update URI from APP_KEY, so a
            // page rendered under a previous key can never talk to this one.
            // Reloading re-renders against the live URI; retrying cannot.
            if (status === 419 || status === 404) {
                preventDefault();
                window.location.reload();

                return;
            }

            // Livewire reports anything else through a <dialog> it opens with
            // showModal(). That THROWS if a dialog is already open non-modally
            // — a Flux modal, say — and the throw kills the error path itself,
            // after which no update is ever processed and the app is frozen.
            closeOpenDialogs();
        });
    });
});

// Closes dialogs that would make Livewire's error modal throw. Left open, one
// stray <dialog> turns a single failed request into a permanently dead UI.
function closeOpenDialogs() {
    try {
        document.querySelectorAll('dialog[open]').forEach((dialog) => {
            try {
                dialog.close();
            } catch (e) {
                // Already closing, or not closeable — either way it is no
                // longer our problem to solve here.
            }
        });
    } catch (e) {
        // No querySelectorAll on dialogs in this engine: nothing to close,
        // and the error modal will behave as it always did.
    }
}

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
 *
 * The body MUST be JSON. The mobile shell forwards only JSON request bodies:
 * `application/x-www-form-urlencoded` and `multipart/form-data` both arrive
 * with an empty input bag, so the route runs, reads nothing and redirects
 * back — the control looks dead while every response is a 200. Livewire works
 * on device for exactly this reason: it has always posted JSON. Verified over
 * CDP against a physical device; the query string survives too, but JSON keeps
 * one shape for every form. Laravel merges a JSON body into the input bag, so
 * `$request->string('code')` reads it unchanged on desktop.
 */
window.beatraxSubmitPostForm = async function (form, submitter) {
    const payload = Object.fromEntries(new FormData(form));

    // FormData omits the submitter; re-add it, which is the whole payload for
    // a form whose value lives on the button (the locale switcher).
    if (submitter && submitter.name) {
        payload[submitter.name] = submitter.value;
    }

    const response = await fetch(form.getAttribute('action'), {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'Accept': 'text/html',
            // The token rides in the header as well as the body: a JSON body
            // is only read after CSRF has already run on some Laravel paths.
            'X-CSRF-TOKEN': payload._token ?? '',
        },
        body: JSON.stringify(payload),
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
        starting: false,
        permissionPending: false,
        _retriedCamera: false,
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
            // A start already in flight owns the camera. Without this every
            // extra tap queued another getUserMedia, and they all opened one
            // after another once permission was finally granted.
            if (this.starting) {
                return;
            }

            if (this.live) {
                this.stop();
                return;
            }
            if (!this.supported) {
                // No in-page camera here — hand off to the native scanner.
                console.warn('[beatrax:scanner] probe reported unsupported; using the native scanner');
                $wire.startScan();
                return;
            }
            this.start();
        },

        async start() {
            // Logged to the console so a device failure is readable from
            // logcat afterwards: the camera can only be opened while the app
            // is foregrounded, so catching it live over the debugger means
            // interrupting whatever the user is doing.
            console.log('[beatrax:scanner] start requested');

            this.starting = true;
            this.permissionPending = false;

            try {
                this.detector = new window.BarcodeDetector({ formats: ['qr_code'] });
                this.stream = await navigator.mediaDevices.getUserMedia({
                    video: { facingMode: 'environment' },
                });
                this.$refs.preview.srcObject = this.stream;
                await this.$refs.preview.play();
                this.live = true;
                this.starting = false;
                this._retriedCamera = false;
                console.log('[beatrax:scanner] preview live');
                this.tick();
            } catch (e) {
                // Retry once before giving up. The camera is briefly busy
                // after a previous stream closes — returning to this screen,
                // or an app-lock interrupting it — and treating that as
                // "unsupported" permanently downgraded the page to the
                // full-screen scanner for the rest of its life.
                console.error('[beatrax:scanner] start failed: ' + (e && e.name) + ' / ' + (e && e.message));
                this.stop();
                this.starting = false;

                // A denial while the OS prompt is up is not "no camera on this
                // device". Falling through to the typed-code screen here threw
                // the user off the camera the moment they granted permission.
                if (e && e.name === 'NotAllowedError') {
                    this.permissionPending = true;

                    return;
                }

                if (!this._retriedCamera) {
                    this._retriedCamera = true;
                    setTimeout(() => this.start(), 400);

                    return;
                }

                // Genuinely unavailable: permission refused, no camera, or the
                // WebView never answered. Fall back rather than strand the
                // user staring at an empty frame.
                console.error('[beatrax:scanner] falling back to the native scanner');
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
        window.Alpine.data('beatraxDatePicker', datePicker);
        window.Alpine.data('beatraxTimePicker', timePicker);

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

