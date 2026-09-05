/**
 * beatraxLock Alpine store — privacy veil, grace-window timer, idle tracker,
 * and cross-tab BroadcastChannel coordination.
 *
 * The veil drops synchronously in <=80ms on visibilitychange/blur so
 * financial data is hidden before the OS takes a screenshot. The
 * pointer-events-none → pointer-events-auto flip happens in the same
 * synchronous stack frame, blocking interaction instantly. Under
 * prefers-reduced-motion the duration is 0ms (motion-reduce:duration-0 in
 * the CSS class list), so the veil still appears — just without a CSS
 * transition. No additional JS branch needed.
 *
 * BroadcastChannel('beatrax:lock') syncs veil state across tabs.
 *
 * The client idle timer only ever asks the server to lock: it POSTs to
 * /lock/engage (_serverLock) so the server session is locked
 * authoritatively on any app page. The server's last_activity_at is the
 * authoritative source and the client timer is a best-effort convenience.
 *
 * window.beatraxGraceMs is the grace window, emitted beside beatraxIdleMs
 * by the authenticated layout from the same constant the app-lock settings
 * copy discloses. Returning within it lifts the veil without a server
 * round-trip. After grace elapses the next Livewire request hits
 * AppLockMiddleware, which redirects to /lock.
 *
 * Idle-lock no-op: when window.beatraxIdleMs is absent (undefined), the idle
 * tracker is disabled — the lock feature is off for this session.
 */

const IDLE_EVENTS = ['pointerdown', 'pointermove', 'keydown', 'touchstart', 'scroll', 'wheel'];

/**
 * Whether an app lock exists for this session.
 *
 * window.beatraxIdleMs is emitted by the authenticated layout only when
 * lock_enabled is on, so its presence is the single client-side signal that a
 * lock exists to be engaged — and, crucially, unlocked again.
 */
function _lockEnabled() {
    return typeof window.beatraxIdleMs === 'number' && window.beatraxIdleMs > 0;
}

// Minimum interval between activity-heartbeat POSTs. The server no
// longer counts Livewire update traffic (wire:poll etc.) as user activity, so
// genuine interaction on Livewire-heavy pages must be reported explicitly.
const HEARTBEAT_MS = 60000;

/**
 * POST to a lock endpoint with the CSRF header and keepalive.
 *
 * keepalive so a request fired as the app leaves the foreground still lands;
 * the header because VerifyCsrfToken accepts the token only from `_token`,
 * `X-CSRF-TOKEN` or `X-XSRF-TOKEN`, never the cookie alone.
 */
function _lockPost(path) {
    return fetch(path, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': _getCsrfToken(),
        },
        body: JSON.stringify({}),
        keepalive: true,
    });
}

/**
 * Take the lock screen when a Livewire request comes back locked.
 *
 * A lock that engages while a Livewire request is in flight cannot answer with
 * a redirect: Livewire reads the body as JSON, and under NativePHP's Android
 * bridge `response.redirected` is false because the bridge follows the redirect
 * itself. The lock page's HTML therefore reached `JSON.parse`, and the app died
 * — lock screen painted as a narrow inset over the page it should have
 * replaced, then blank, then no request from any tap until a force-stop.
 *
 * So AppLockMiddleware answers such a request with `{"components": [],
 * "beatraxLock": {"redirect": "…"}}` and this takes it from there. `components`
 * being empty is what keeps Livewire's own handling harmless if this listener
 * is ever missing; the navigation is this listener's job alone.
 *
 * Registered unconditionally, and NOT behind `_lockEnabled()`: that flag
 * reflects what the layout knew when this page rendered, and the case being
 * defended against is precisely a lock that engaged afterwards.
 */
document.addEventListener('livewire:init', () => {
    if (! window.Livewire || typeof window.Livewire.interceptRequest !== 'function') {
        return;
    }

    window.Livewire.interceptRequest(({ onParsed }) => {
        onParsed(({ body }) => {
            // Cheap string test before parsing: this runs for every Livewire
            // response on the page, and all but one of them are ordinary.
            if (typeof body !== 'string' || ! body.includes('beatraxLock')) {
                return;
            }

            let payload;

            try {
                payload = JSON.parse(body);
            } catch (e) {
                return;
            }

            const redirect = payload && payload.beatraxLock && payload.beatraxLock.redirect;

            if (typeof redirect === 'string' && redirect !== '') {
                window.location.assign(redirect);
            }
        });
    });
});

document.addEventListener('alpine:init', () => {
    if (!window.Alpine) {
        return;
    }

    window.Alpine.store('beatraxLock', {
        // -----------------------------------------------------------------------
        // Internal state
        // -----------------------------------------------------------------------

        /** Timer ID for the grace window setTimeout. */
        _graceTimer: null,

        /** Timestamp of the last recorded user interaction (ms since epoch). */
        _lastActivity: Date.now(),

        /** Interval ID for the idle-check loop. */
        _idleInterval: null,

        /** Whether the BroadcastChannel is available in this environment. */
        _channelAvailable: typeof BroadcastChannel !== 'undefined',

        /** The BroadcastChannel instance (null when API unavailable). */
        _channel: null,

        // -----------------------------------------------------------------------
        // Veil helpers
        // -----------------------------------------------------------------------

        /** Show the privacy veil synchronously. */
        showVeil() {
            const veil = document.getElementById('beatrax-veil');
            if (!veil) {
                return;
            }
            veil.classList.remove('opacity-0', 'pointer-events-none');
            veil.classList.add('opacity-100', 'pointer-events-auto');
            veil.setAttribute('aria-hidden', 'false');

            // Promote into the browser's TOP LAYER. A z-index — however
            // large — only competes within the stacking context it lives in,
            // so a dialog that portals to <body> and opens its own top-layer
            // entry still painted over the veil. The top layer is the only
            // place nothing can be stacked above.
            this._enterTopLayer(veil);
            veil.setAttribute('role', 'dialog');
            veil.setAttribute('aria-modal', 'true');
            veil.setAttribute('aria-label', veil.dataset.lockedLabel || '');
        },

        /**
         * Raise the veil into the top layer, above every modal.
         *
         * showPopover() is the supported way in; a browser without it falls
         * back to being re-appended last in <body>, which at least beats
         * same-z-index siblings that were inserted earlier.
         */
        _enterTopLayer(veil) {
            if (typeof veil.showPopover === 'function' && veil.hasAttribute('popover')) {
                try {
                    if (!veil.matches(':popover-open')) {
                        veil.showPopover();
                    }

                    return;
                } catch (e) {
                    // Fall through to the append fallback below.
                }
            }

            if (veil.parentElement === document.body && veil !== document.body.lastElementChild) {
                document.body.appendChild(veil);
            }
        },

        /** Drop the veil back out of the top layer. */
        _leaveTopLayer(veil) {
            if (typeof veil.hidePopover === 'function' && veil.hasAttribute('popover')) {
                try {
                    if (veil.matches(':popover-open')) {
                        veil.hidePopover();
                    }
                } catch (e) {
                    // Already closed; nothing to undo.
                }
            }
        },

        /** Hide the privacy veil. */
        hideVeil() {
            const veil = document.getElementById('beatrax-veil');
            if (!veil) {
                return;
            }
            veil.classList.remove('opacity-100', 'pointer-events-auto');
            veil.classList.add('opacity-0', 'pointer-events-none');
            veil.setAttribute('aria-hidden', 'true');
            this._leaveTopLayer(veil);
            veil.removeAttribute('role');
            veil.removeAttribute('aria-modal');
            veil.removeAttribute('aria-label');
        },

        // -----------------------------------------------------------------------
        // Grace-window management
        // -----------------------------------------------------------------------

        /** Start the grace timer after the window is backgrounded. */
        _startGrace() {
            this._clearGrace();

            // The window is the server's, so the settings copy that discloses
            // it and the timer that runs it cannot part. Absent means the
            // layout emitted no lock config at all, and the server-side
            // marker _markBackgrounded() just wrote is the clock that counts.
            if (typeof window.beatraxGraceMs !== 'number') {
                return;
            }

            this._graceTimer = window.setTimeout(() => {
                this._graceTimer = null;
                // Grace elapsed — lock the server session via the engage
                // endpoint. fetch with keepalive:true survives tab
                // closure/switch and does not block the page.
                this._serverLock();
                // Also broadcast locked state to all tabs (UX convenience).
                this._broadcast('locked');
            }, window.beatraxGraceMs);
        },

        /** Cancel a pending grace timer (user returned in time). */
        _clearGrace() {
            if (this._graceTimer !== null) {
                window.clearTimeout(this._graceTimer);
                this._graceTimer = null;
            }
        },

        /**
         * Stamp the moment we left the foreground, server-side.
         *
         * The grace timer above cannot be trusted on mobile: an Android
         * WebView is suspended while backgrounded, so the timeout does not
         * fire, and _clearGrace() on return then cancelled it outright — the
         * app came back unlocked no matter how long it had been away.
         * AppLockMiddleware judges the elapsed time from this marker instead,
         * against a clock that suspension cannot stop.
         */
        _markBackgrounded() {
            return _lockPost('/lock/background').catch(() => {
                // Swallow — the idle check in AppLockMiddleware still applies.
            });
        },

        /**
         * Ask the server whether the grace window closed while we were away.
         *
         * The answer is the body `{"locked": false}`, and anything else means
         * lock. It used to be `response.redirected`, which is unreadable under
         * NativePHP's Android bridge: the bridge follows the middleware's
         * redirect to the lock screen in-process and returns that page as an
         * ordinary response, so `redirected` was always false and the reload
         * never fired. The session was locked server-side while the phone went
         * on showing — and accepting taps on — the previous screen.
         *
         * Reading the body inverts the default: any answer that is not
         * explicitly "unlocked" reloads. A lock screen is HTML and fails to
         * parse, a network error is caught below and left to the next
         * navigation, and only a genuine unlocked reply stays put.
         */
        _checkResume() {
            return _lockPost('/lock/resume')
                .then((response) => {
                    if (! response) {
                        return;
                    }

                    // A reply arrived, so the answer is knowable: reload unless
                    // it is explicitly the unlocked one. Unparseable means the
                    // lock screen came back instead.
                    return response.json().then(
                        (payload) => {
                            if (! payload || payload.locked !== false) {
                                window.location.reload();
                            }
                        },
                        () => window.location.reload(),
                    );
                })
                .catch(() => {
                    // Swallow — the request itself failed, which says nothing
                    // about the lock. Reloading on a dropped connection would
                    // spin. The next navigation is gated the same way.
                });
        },

        // -----------------------------------------------------------------------
        // BroadcastChannel — cross-tab coordination
        // -----------------------------------------------------------------------

        /**
         * POST to /lock/engage to lock the server session.
         *
         * Uses fetch with keepalive:true — like a beacon, a keepalive request
         * survives tab switching/closing, but unlike navigator.sendBeacon it
         * can carry CSRF headers. Laravel's VerifyCsrfToken accepts the token
         * ONLY from a `_token` body field, the `X-CSRF-TOKEN` header, or the
         * `X-XSRF-TOKEN` header (the XSRF-TOKEN cookie alone is never accepted
         * as the supplied token), so sendBeacon — which cannot set headers —
         * would always 419 here. The server returns 204 and no body is read.
         *
         * Called when:
         *   1. The grace window elapses (backgrounded past grace).
         *   2. The idle ticker detects inactivity >= idle_timeout_minutes.
         */
        _serverLock() {
            return _lockPost('/lock/engage').catch(() => {
                // Swallow — if the request fails the server-side idle check
                // in AppLockMiddleware will catch it on the next request.
            });
        },

        /** Broadcast a lock-state message to all tabs. */
        _broadcast(state) {
            if (this._channel) {
                try {
                    this._channel.postMessage(state);
                } catch (e) {
                    // Ignore — channel may have been closed.
                }
            }
        },

        // -----------------------------------------------------------------------
        // Idle tracking
        // -----------------------------------------------------------------------

        /** Timestamp of the last activity-heartbeat POST (ms since epoch). */
        _lastHeartbeat: 0,

        /** Record that the user just interacted. */
        _resetActivity() {
            this._lastActivity = Date.now();
            this._maybeHeartbeat();
        },

        /**
         * POST a throttled activity heartbeat to the server.
         *
         * AppLockMiddleware refreshes last_activity_at on plain (non-Livewire)
         * requests only — wire:poll machine traffic no longer counts as
         * activity. This heartbeat is how genuine interaction keeps the
         * server-side idle timer honest on Livewire-heavy pages.
         * Throttled to once per HEARTBEAT_MS; only runs when the idle watcher
         * is armed (activity listeners are registered in _startIdleWatch).
         */
        _maybeHeartbeat() {
            const now = Date.now();
            if (now - this._lastHeartbeat < HEARTBEAT_MS) {
                return;
            }
            this._lastHeartbeat = now;

            fetch('/lock/activity', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': _getCsrfToken(),
                },
                body: JSON.stringify({}),
            }).catch(() => {
                // Swallow — a missed heartbeat only means the server idle
                // timer is slightly more aggressive than the client's.
            });
        },

        /** Start the idle-check interval. No-ops when beatraxIdleMs is absent. */
        _startIdleWatch() {
            if (typeof window.beatraxIdleMs !== 'number' || window.beatraxIdleMs <= 0) {
                // Lock feature is disabled for this session.
                return;
            }

            // Register activity listeners.
            IDLE_EVENTS.forEach((eventName) => {
                window.addEventListener(eventName, () => this._resetActivity(), { passive: true });
            });

            // Check every 10 s whether the idle threshold has been exceeded.
            this._idleInterval = window.setInterval(() => {
                // Re-read rather than closing over the value: the setting is
                // changed by a Livewire action that never re-renders this
                // layout, so a captured copy kept locking on the old window
                // for the rest of the page's life.
                const idleMs = window.beatraxIdleMs;
                if (typeof idleMs !== 'number' || idleMs <= 0) {
                    return;
                }

                const elapsed = Date.now() - this._lastActivity;
                if (elapsed >= idleMs) {
                    // Idle threshold elapsed — lock the server session via
                    // the engage endpoint, server-authoritatively.
                    // _serverLock() fires on every app page; there is no
                    // Livewire event path (the old 'idle-timeout-elapsed'
                    // dispatch was removed).
                    // No veil here. The app is IN VIEW — there is no
                    // app-switcher snapshot to hide, and the veil landed on
                    // top of the credential prompt, covering the very control
                    // the user had to reach. Going to the lock screen is the
                    // cover: it is a real full-screen page, not an overlay
                    // with the nav still showing around it.
                    this._broadcast('locked');
                    this._lastActivity = Date.now();

                    this._serverLock().finally(() => {
                        // Server-provided: /lock is the desktop screen, and a
                        // phone must land on its own full-screen one instead
                        // of rendering the wrong surface first.
                        window.location.assign(window.beatraxLockUrl || '/lock');
                    });
                }
            }, 10000);
        },

        // -----------------------------------------------------------------------
        // Initialisation
        // -----------------------------------------------------------------------

        /** Called by the Alpine store initialiser. */
        init() {
            // Set up the BroadcastChannel.
            if (this._channelAvailable) {
                this._channel = new BroadcastChannel('beatrax:lock');
                this._channel.onmessage = (event) => {
                    if (event.data === 'locked') {
                        this.showVeil();
                    } else if (event.data === 'unlocked') {
                        this.hideVeil();
                    }
                };
            }

            // Veil and grace are lock machinery, not standalone privacy
            // features: _startGrace() ends in _serverLock(), and a veil whose
            // grace window has elapsed is only ever lifted by a successful
            // unlock. Arming either for a user with no lock strands them
            // behind a PIN pad that no PIN opens — the same invariant the
            // idle tracker already honours below.
            if (!_lockEnabled()) {
                return;
            }

            // Navigating away hides this document too, and the hide is
            // indistinguishable from backgrounding by the time
            // visibilitychange runs. pagehide fires first when a document is
            // being replaced, so the flag is set in time.
            //
            // Without this, every in-app navigation POSTed /lock/background.
            // The server pulls that marker on the next request to clear it —
            // but the marker is written by a keepalive POST that lands ~30ms
            // AFTER the incoming page's own GET, so nothing was there to pull
            // and the marker simply waited. A user reading one page for longer
            // than the grace window was then locked on their next tap,
            // whatever their idle timeout said.
            //
            // `persisted` is NOT the discriminator, though the first version of
            // this read it as one. Chrome reports false for an ordinary
            // navigation, but WebKit bfcaches the outgoing document and reports
            // **true** for the same navigation — measured on an iPhone as
            // `pagehide persisted=true t=30996` then `visibilitychange hidden
            // t=30997`. Keying off it meant the fix worked on Android and left
            // iOS locking ~75s into a five-minute timeout without the app ever
            // leaving the foreground.
            //
            // What actually separates the two cases is whether pagehide fires
            // at all. Backgrounding an app shell does not unload its document,
            // so only a replacement raises it — on either engine. And the flag
            // cannot go stale, because a document being replaced has no later:
            // the one exception is bfcache restore, where this very document
            // lives again, which pageshow tells us about.
            this._unloading = false;

            window.addEventListener('pagehide', () => {
                this._unloading = true;
            });

            window.addEventListener('pageshow', () => {
                this._unloading = false;
            });

            // Visibility change: background → show veil + start grace.
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'hidden') {
                    // The veil is unconditional: it is a privacy screen, and
                    // a torn-down document is about to disappear anyway.
                    this.showVeil();

                    if (this._unloading) {
                        return;
                    }

                    // Both: the timer is the fast path where it genuinely
                    // runs, the marker is what survives a suspended WebView.
                    this._markBackgrounded();
                    this._startGrace();
                } else {
                    // Returned to foreground: ALWAYS lift the veil.
                    //
                    // Keeping it up when the grace had elapsed assumed the
                    // server was now locked and would redirect. When it is
                    // not — no app lock configured, or the engage call never
                    // landed — the veil had nothing to replace it and the app
                    // stayed stuck behind a blank screen with no way out.
                    // The server lock is the security boundary; this veil is
                    // only a privacy screen, so failing open here is right.
                    this._clearGrace();
                    this.hideVeil();
                    // Cancelling the timer above is no longer the whole
                    // decision — the server still holds the marker, and this
                    // is what asks it whether the grace closed while we were
                    // suspended.
                    this._checkResume();
                }
            });

            // Window blur: veil ONLY, never the lock countdown.
            //
            // Blur fires whenever another window takes focus while ours stays
            // visible on screen — there is no app-switcher snapshot to defend
            // against, and starting the grace here meant clicking away for
            // half a minute locked the app regardless of a 30-minute idle
            // setting. Genuine backgrounding still locks: visibilitychange
            // above, plus the desktop shell's WindowHidden/WindowClosed
            // hand-off, which the window's next request claims with no grace at
            // all. That listener used to be described here as locking
            // immediately; it could not, because the shell posts those events
            // from a process holding no session cookie.
            window.addEventListener('blur', () => {
                this.showVeil();
            });

            // Window focus: lift the veil. No grace timer runs for a bare
            // blur any more, so returning is unconditional — a veil that
            // outlived its blur would strand a visible, unlocked window.
            window.addEventListener('focus', () => {
                this._clearGrace();
                this.hideVeil();
            });

            // Start the idle tracker (no-ops when lock is disabled).
            this._startIdleWatch();

            // The auto-lock setting is applied by a Livewire action that does
            // not re-render the layout that emitted beatraxIdleMs, so the
            // server sends the new window here. Without it, choosing 30
            // minutes kept locking on the old one until a full reload.
            window.addEventListener('beatrax-idle-timeout-changed', (event) => {
                const ms = event.detail && event.detail.ms;
                if (typeof ms === 'number' && ms > 0) {
                    window.beatraxIdleMs = ms;
                    this._resetActivity();
                }
            });

            // ---------------------------------------------------------------
            // WebAuthn unlock — beatrax:webauthn-get
            //
            // Fired by LockScreen.biometricPrompt() on button tap only.
            // Never auto-fires on render.
            // Guard: no-op when the browser does not support WebAuthn.
            // ---------------------------------------------------------------
            document.addEventListener('beatrax:webauthn-get', async () => {
                if (!window.PublicKeyCredential) {
                    return;
                }

                try {
                    // Fetch challenge options from the server.
                    const challengeRes = await fetch('/lock/biometric/challenge', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-XSRF-TOKEN': _getCsrfToken(),
                        },
                        body: JSON.stringify({}),
                    });

                    if (!challengeRes.ok) {
                        return;
                    }

                    const options = await challengeRes.json();

                    // Deserialise base64url fields expected by the browser API.
                    options.challenge = _decodeBase64url(options.challenge);
                    if (Array.isArray(options.allowCredentials)) {
                        options.allowCredentials = options.allowCredentials.map((c) => ({
                            ...c,
                            id: _decodeBase64url(c.id),
                        }));
                    }

                    // Invoke the OS biometric prompt.
                    const credential = await navigator.credentials.get({ publicKey: options });
                    if (!credential) {
                        return;
                    }

                    // Serialise the assertion for the server.
                    const assertion = _serializeAssertion(credential);

                    // POST assertion to verify endpoint.
                    const verifyRes = await fetch('/lock/biometric/verify', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-XSRF-TOKEN': _getCsrfToken(),
                        },
                        body: JSON.stringify(assertion),
                    });

                    if (!verifyRes.ok) {
                        return;
                    }

                    const result = await verifyRes.json();

                    if (result.unlocked) {
                        // Broadcast unlocked state to all tabs and navigate.
                        this._broadcast('unlocked');
                        window.location.href = result.redirect;
                    }
                } catch (e) {
                    // Biometric cancelled or failed — no-op; user can retry or use PIN.
                }
            });

            // ---------------------------------------------------------------
            // WebAuthn enrollment — beatrax:webauthn-create
            //
            // Fired by AppLockSettingsSection.startEnroll() on Enroll button tap.
            // Guard: no-op when the browser does not support WebAuthn.
            // ---------------------------------------------------------------
            document.addEventListener('beatrax:webauthn-create', async () => {
                if (!window.PublicKeyCredential) {
                    return;
                }

                try {
                    // Fetch creation options from the server.
                    const challengeRes = await fetch('/lock/biometric/challenge?enroll=1', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-XSRF-TOKEN': _getCsrfToken(),
                        },
                        body: JSON.stringify({}),
                    });

                    if (!challengeRes.ok) {
                        return;
                    }

                    const options = await challengeRes.json();

                    // Deserialise base64url fields.
                    options.challenge = _decodeBase64url(options.challenge);
                    if (options.user && options.user.id) {
                        options.user.id = _decodeBase64url(options.user.id);
                    }
                    if (Array.isArray(options.excludeCredentials)) {
                        options.excludeCredentials = options.excludeCredentials.map((c) => ({
                            ...c,
                            id: _decodeBase64url(c.id),
                        }));
                    }

                    // Invoke the OS biometric enrollment prompt.
                    const credential = await navigator.credentials.create({ publicKey: options });
                    if (!credential) {
                        return;
                    }

                    // Serialise the attestation.
                    const attestation = _serializeAttestation(credential);

                    // POST attestation to enroll endpoint.
                    const enrollRes = await fetch('/lock/biometric/enroll', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-XSRF-TOKEN': _getCsrfToken(),
                        },
                        body: JSON.stringify(attestation),
                    });

                    if (enrollRes.ok) {
                        const result = await enrollRes.json();
                        if (result.enrolled && window.Livewire) {
                            window.Livewire.dispatch('biometric-enrolled');
                        }
                    }
                } catch (e) {
                    // Enrollment cancelled or failed — no-op.
                }
            });
        },
    });
});

// ---------------------------------------------------------------------------
// WebAuthn serialisation helpers
// ---------------------------------------------------------------------------

/** Read the CSRF token from the XSRF-TOKEN cookie (set by Laravel). */
function _getCsrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

/**
 * Decode a base64url string to an ArrayBuffer.
 * The WebAuthn browser API expects ArrayBuffers for binary fields.
 */
function _decodeBase64url(base64url) {
    if (!base64url) return new Uint8Array(0).buffer;
    // Normalise base64url → base64.
    const base64 = base64url.replace(/-/g, '+').replace(/_/g, '/');
    const binaryStr = atob(base64);
    const bytes = new Uint8Array(binaryStr.length);
    for (let i = 0; i < binaryStr.length; i++) {
        bytes[i] = binaryStr.charCodeAt(i);
    }
    return bytes.buffer;
}

/**
 * Encode an ArrayBuffer to a base64url string for sending to the server.
 * The server's PHP base64_decode() accepts standard base64; we send base64url.
 */
function _encodeBase64url(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.byteLength; i++) {
        binary += String.fromCharCode(bytes[i]);
    }
    return btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=/g, '');
}

/**
 * Serialise a PublicKeyCredential (assertion) for the server.
 * The server expects base64-encoded fields (not base64url).
 */
function _serializeAssertion(credential) {
    const response = credential.response;
    return {
        id: credential.id,
        rawId: _encodeBase64url(credential.rawId),
        type: credential.type,
        response: {
            authenticatorData: _encodeBase64url(response.authenticatorData),
            clientDataJSON: _encodeBase64url(response.clientDataJSON),
            signature: _encodeBase64url(response.signature),
            userHandle: response.userHandle ? _encodeBase64url(response.userHandle) : null,
        },
    };
}

/**
 * Serialise a PublicKeyCredential (attestation) for the server.
 */
function _serializeAttestation(credential) {
    const response = credential.response;
    return {
        id: credential.id,
        rawId: _encodeBase64url(credential.rawId),
        type: credential.type,
        response: {
            attestationObject: _encodeBase64url(response.attestationObject),
            clientDataJSON: _encodeBase64url(response.clientDataJSON),
        },
    };
}
