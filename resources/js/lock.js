/**
 * beatraxLock Alpine store — privacy veil, grace-window timer, idle tracker,
 * and cross-tab BroadcastChannel coordination.
 *
 * Threat mitigations:
 *   T-05-11: veil drops synchronously in <=80ms on visibilitychange/blur so
 *             financial data is hidden before OS takes a screenshot. The
 *             pointer-events-none → pointer-events-auto flip happens in the
 *             same synchronous stack frame, blocking interaction instantly.
 *             prefers-reduced-motion: duration is 0ms (motion-reduce:duration-0
 *             in the CSS class list), so the veil still appears — just without
 *             a CSS transition. No additional JS branch needed.
 *   T-05-13: idle dispatch only asks the server to lock (Livewire event).
 *             The server last_activity_at is the authoritative source (D-17).
 *             The client timer is a best-effort convenience only.
 *
 * Design decisions:
 *   D-05: BroadcastChannel('beatrax:lock') syncs veil state across tabs.
 *   D-07: Veil drops on visibilitychange:hidden + window blur.
 *   D-17: Client idle timer dispatches 'idle-timeout-elapsed' to the Livewire
 *         component, which asks the server to lock and redirects.
 *   D-18: GRACE_MS = 30 000ms. Return within the grace window lifts the veil
 *         without a server round-trip. After grace elapses the next Livewire
 *         request will hit AppLockMiddleware which redirects to /lock.
 *
 * Idle-lock no-op: when window.beatraxIdleMs is absent (undefined), the idle
 * tracker is disabled — the lock feature is off for this session.
 */

const GRACE_MS = 30000; // D-18: 30 s grace window

const IDLE_EVENTS = ['pointerdown', 'pointermove', 'keydown', 'touchstart', 'scroll', 'wheel'];

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
            veil.setAttribute('role', 'dialog');
            veil.setAttribute('aria-modal', 'true');
            veil.setAttribute('aria-label', 'App locked');
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
            veil.removeAttribute('role');
            veil.removeAttribute('aria-modal');
            veil.removeAttribute('aria-label');
        },

        // -----------------------------------------------------------------------
        // Grace-window management
        // -----------------------------------------------------------------------

        /** Start the grace timer after the window is backgrounded / blurred. */
        _startGrace() {
            this._clearGrace();
            this._graceTimer = window.setTimeout(() => {
                this._graceTimer = null;
                // Grace elapsed — broadcast locked state to all tabs.
                // The next Livewire request to the server will hit
                // AppLockMiddleware and redirect to /lock (D-17/D-18).
                this._broadcast('locked');
            }, GRACE_MS);
        },

        /** Cancel a pending grace timer (user returned in time). */
        _clearGrace() {
            if (this._graceTimer !== null) {
                window.clearTimeout(this._graceTimer);
                this._graceTimer = null;
            }
        },

        // -----------------------------------------------------------------------
        // BroadcastChannel — D-05 cross-tab coordination
        // -----------------------------------------------------------------------

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
        // Idle tracking — D-17
        // -----------------------------------------------------------------------

        /** Record that the user just interacted. */
        _resetActivity() {
            this._lastActivity = Date.now();
        },

        /** Start the idle-check interval. No-ops when beatraxIdleMs is absent. */
        _startIdleWatch() {
            const idleMs = window.beatraxIdleMs;
            if (typeof idleMs !== 'number' || idleMs <= 0) {
                // Lock feature is disabled for this session.
                return;
            }

            // Register activity listeners.
            IDLE_EVENTS.forEach((eventName) => {
                window.addEventListener(eventName, () => this._resetActivity(), { passive: true });
            });

            // Check every 10 s whether the idle threshold has been exceeded.
            this._idleInterval = window.setInterval(() => {
                const elapsed = Date.now() - this._lastActivity;
                if (elapsed >= idleMs) {
                    // Ask the server to lock via the Livewire component
                    // listening on the current page (LockScreen ignores this
                    // event; AppLockMiddleware handles it if the session
                    // is on a protected page).
                    if (window.Livewire) {
                        window.Livewire.dispatch('idle-timeout-elapsed');
                    }
                    // Reset the timer so we don't spam the dispatch.
                    this._lastActivity = Date.now();
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

            // Visibility change: background → show veil + start grace.
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'hidden') {
                    this.showVeil();
                    this._startGrace();
                } else {
                    // Returned to foreground.
                    if (this._graceTimer !== null) {
                        // Within grace window — lift the veil without a
                        // server round-trip.
                        this._clearGrace();
                        this.hideVeil();
                    }
                    // If grace elapsed the veil stays and the next request
                    // will be redirected to /lock by AppLockMiddleware.
                }
            });

            // Window blur: show veil + start grace.
            window.addEventListener('blur', () => {
                this.showVeil();
                this._startGrace();
            });

            // Window focus: return from blur within grace window — lift veil.
            window.addEventListener('focus', () => {
                if (this._graceTimer !== null) {
                    this._clearGrace();
                    this.hideVeil();
                }
            });

            // Start the idle tracker (no-ops when lock is disabled).
            this._startIdleWatch();
        },
    });
});
