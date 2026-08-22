
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
import { tabStrip } from './tab-strip.js';
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

/*
 * ApexCharts ships English month names and prints raw numbers unless told
 * otherwise, so a Dutch page drew an axis reading "Oct 2026" and "3233.89"
 * beside money the rest of the page had rendered as "€ 3.233,89".
 *
 * The month names come from the browser's own Intl for <html lang>, not from
 * a table baked in here, so a new interface language needs no change.
 */
window.beatraxLocaliseChart = function (options) {
    const tag = document.documentElement.lang || 'en';
    const months = [];
    const shortMonths = [];
    const days = [];
    const shortDays = [];

    for (let m = 0; m < 12; m++) {
        const d = new Date(Date.UTC(2021, m, 1));
        months.push(d.toLocaleDateString(tag, { month: 'long', timeZone: 'UTC' }));
        shortMonths.push(d.toLocaleDateString(tag, { month: 'short', timeZone: 'UTC' }));
    }

    // 2021-08-01 was a Sunday, which is where ApexCharts starts its week.
    for (let i = 0; i < 7; i++) {
        const d = new Date(Date.UTC(2021, 7, 1 + i));
        days.push(d.toLocaleDateString(tag, { weekday: 'long', timeZone: 'UTC' }));
        shortDays.push(d.toLocaleDateString(tag, { weekday: 'short', timeZone: 'UTC' }));
    }

    options.chart = Object.assign({}, options.chart || {}, {
        defaultLocale: tag,
        locales: [{
            name: tag,
            options: { months, shortMonths, days, shortDays, toolbar: {} },
        }],
    });

    // Axis numbers are money on every chart this app draws. The currency is
    // what is being drawn; `tag` — the same locale Money::format() reads —
    // decides the separators and which side the symbol falls on, so the axis
    // and the tile beside it cannot end up in two notations.
    const currency = document.documentElement.dataset.baseCurrency || 'EUR';
    const money = new Intl.NumberFormat(tag, {
        style: 'currency',
        currency,
        maximumFractionDigits: 0,
    });

    const withFormatter = (axis) => {
        if (!axis || Array.isArray(axis)) return axis;
        const labels = axis.labels || {};
        if (labels.formatter) return axis;

        return Object.assign({}, axis, {
            labels: Object.assign({}, labels, {
                formatter: (value) => (typeof value === 'number' ? money.format(value) : value),
            }),
        });
    };

    options.yaxis = withFormatter(options.yaxis);

    beatraxNameChart(options);

    return options;
};

/*
 * ApexCharts writes its own accessible name onto the <svg> it renders —
 * "donut chart with 14 data series" — in English, whatever the page
 * language is. There is no option for it, so the name is replaced on mount
 * and after every update from the localised table on <html>.
 *
 * A partial that knows something better than the chart type (a report
 * title, say) sets `beatraxAriaLabel` on its options and wins.
 */
function beatraxNameChart(options) {
    let labels = {};
    try {
        labels = JSON.parse(document.documentElement.dataset.chartLabels || '{}');
    } catch {
        labels = {};
    }

    const name = options.beatraxAriaLabel || labels[options.chart.type];
    if (!name) {
        return;
    }

    const apply = (chartContext) => {
        const el = chartContext?.el || chartContext?.w?.globals?.dom?.baseEl;
        el?.querySelector('svg')?.setAttribute('aria-label', name);
    };

    // Wrapped, not replaced: the drilldown charts already listen for
    // mounted/updated and dropping their handler would take the click
    // targets with it.
    const events = Object.assign({}, options.chart.events || {});
    const wrap = (existing) => function (chartContext, ...rest) {
        apply(chartContext);
        if (typeof existing === 'function') {
            existing.call(this, chartContext, ...rest);
        }
    };
    events.mounted = wrap(events.mounted);
    events.updated = wrap(events.updated);
    options.chart.events = events;
}

// Adjust ApexCharts options for the active theme so chart lines,
// grid lines, and axis labels stay legible when `<html class="dark">`
// flips the page into dark mode. Server-rendered options bake in
// slate-900 line colors that vanish against slate-950 backgrounds —
// this helper swaps them at hydration time. Mutates and returns the
// same object for chained use.
window.beatraxApplyChartTheme = function (options) {
    if (!options || typeof options !== 'object') {
        return options;
    }

    window.beatraxLocaliseChart(options);

    const isDark = document.documentElement.classList.contains('dark');
    if (!isDark) {
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

    // Follow wherever the server sent us.
    //
    // Deliberately NOT gated on `response.redirected`. That flag is false
    // under NativePHP's mobile bridge — the bridge follows the redirect
    // itself and hands back an ordinary response — which is the same trap
    // that made the app lock never reload. Here it meant sign-out fell
    // through to reload(): the login page rendered correctly, at the URL of
    // the page the user had just left, so `location.href` said `/settings`
    // while a login form was on screen.
    //
    // `response.url` is the useful half and survives on both paths, so
    // compare it instead and only reload when it genuinely has not moved.
    const landed = typeof response.url === 'string' ? response.url : '';
    const submitted = new URL(form.getAttribute('action'), window.location.href).href;

    if (landed !== '' && landed !== submitted && landed !== window.location.href) {
        window.location.assign(landed);

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

/**
 * Copy text to the clipboard, reporting whether it actually worked.
 *
 * navigator.clipboard is gated to secure contexts, and the packaged app's
 * webview is not one on either phone. Every call site used to guard with a
 * bare `if (! navigator.clipboard) return`, so on a phone the button did
 * nothing and said nothing — worst of all on the recovery codes, which are
 * never shown again.
 *
 * @param {string} text
 * @returns {Promise<boolean>} whether the text reached the clipboard
 */
window.beatraxCopy = async function beatraxCopy(text) {
    try {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            await navigator.clipboard.writeText(text);

            return true;
        }

        // A temporary textarea and the legacy command, which webviews still
        // honour where the async API is withheld.
        const area = document.createElement('textarea');
        area.value = text;
        area.setAttribute('readonly', 'readonly');
        area.style.position = 'fixed';
        area.style.top = '0';
        area.style.opacity = '0';
        document.body.appendChild(area);
        area.focus();
        area.select();
        area.setSelectionRange(0, area.value.length);

        try {
            return document.execCommand('copy') === true;
        } finally {
            document.body.removeChild(area);
        }
    } catch (e) {
        return false;
    }
};

/**
 * The live requirement checklist under a new-password pair.
 *
 * It reads the SAME `$wire` bindings the server validates rather than a
 * private mirror of them: a mirror fed only by input events went on showing
 * two green ticks after a re-render had emptied both boxes, under an error
 * saying the password was too short.
 *
 * The minimum and the two property names are arguments because the desktop
 * signup form and the mobile import form are separate Livewire components,
 * and the checklist must not hold either one's names.
 */
function passwordStrength(minLength, passwordProperty, confirmationProperty) {
    return {
        get typedPassword() {
            return this.$wire[passwordProperty] ?? '';
        },
        get typedConfirmation() {
            return this.$wire[confirmationProperty] ?? '';
        },
        get lengthOk() {
            return this.typedPassword.length >= minLength;
        },
        get matchOk() {
            return this.typedConfirmation.length > 0 && this.typedPassword === this.typedConfirmation;
        },
    };
}

/**
 * Copy-button state for a screen whose text is shown exactly once.
 *
 * Guarding on navigator.clipboard and returning left the button dead on the
 * very device it exists for: the webview withholds the async API outside a
 * secure context. beatraxCopy() falls back and, crucially, reports failure
 * rather than swallowing it, which is what `failed` renders.
 *
 * Spread into a larger x-data where a screen also saves the same text, so
 * `failed` stays one flag across both affordances.
 */
function copyToClipboard(text) {
    return {
        copied: false,
        failed: false,

        async copy() {
            if (await window.beatraxCopy(text)) {
                this.failed = false;
                this.copied = true;
                setTimeout(() => { this.copied = false; }, 2500);

                return;
            }

            this.failed = true;
        },
    };
}

/**
 * Apply a theme change to the root element straight away.
 *
 * The `dark` / `light` class is rendered by the layout, which a Livewire
 * update never touches, so choosing a theme in settings persisted the
 * preference and left the page exactly as it was.
 */
document.addEventListener('theme-changed', (event) => {
    const root = document.documentElement;
    const chosen = event?.detail?.theme ?? (Array.isArray(event?.detail) ? event.detail[0]?.theme : null);

    const prefersDark = window.matchMedia
        && window.matchMedia('(prefers-color-scheme: dark)').matches;

    const dark = chosen === 'dark' || (chosen !== 'light' && prefersDark);

    root.classList.toggle('dark', dark);
    root.classList.toggle('light', ! dark);
});

/**
 * Put a multi-step screen back at the top when it changes step.
 *
 * The steps of a wizard share one page, so advancing is a re-render and never
 * a navigation: the browser keeps the offset the previous step was left at and
 * the new step opens below its own heading. Measured on device, the wizard
 * handed step 3 a scrollY of 424 and the mobile import bootstrap handed its
 * recovery codes 107 — far enough under `viewport-fit=cover` to put a heading
 * behind the status-bar clock.
 *
 * One listener here rather than one per screen: it is registered before any
 * component mounts and lives outside the DOM Livewire morphs, so it cannot be
 * lost, duplicated or re-bound by a morph. Components announce through
 * AnnouncesStepChanges, which is the only place the name is written.
 */
document.addEventListener('step-changed', () => {
    window.scrollTo({ top: 0 });
});

document.addEventListener('alpine:init', () => {
    if (window.Alpine) {
        window.Alpine.data('palette', palette);
        window.Alpine.data('beatraxInlineScanner', beatraxInlineScanner);
        window.Alpine.data('beatraxDatePicker', datePicker);
        window.Alpine.data('beatraxTimePicker', timePicker);
        window.Alpine.data('tabStrip', tabStrip);
        window.Alpine.data('passwordStrength', passwordStrength);
        window.Alpine.data('copyToClipboard', copyToClipboard);

        // Mobile navigation drawer state
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

        // Platform detection for ⌘K vs Ctrl+K labels.
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

