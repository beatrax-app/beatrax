@use('Modules\Core\Public\Support\Lang')
{{--
    Standing install-hint surface (UI-SPEC §14).

    Two placements: dashboard card (bottom of main content column) and
    Settings → General row ("Install as app"). Use the same component in both.

    Behavior — and there are exactly two arms, which is the whole of it:
    - Takes the beforeinstallprompt offer on phones/Chromium to show a native
      install CTA. The offer is read from the stash app.js keeps, not only from
      a listener bound here: the event fires once per document, so a component
      Alpine initialises on a wire:navigate arrival would otherwise be waiting
      for something that has already happened.
    - Always shown on desktop as a feature-discovery hint ("Also want to see your
      data on your phone?") pointing the user to open Beatrax on their phone.
    - Dismissable but returns (standing hint, not one-time dismissed forever).

    There is NO iOS arm. `shown` is set true only by beforeinstallprompt, which
    is Chromium-only, and by the >=1024px media query, so an iPhone matches
    neither and this card never renders there. This docblock used to promise a
    Share-sheet instruction; no such branch and no such copy has ever existed,
    and the same claim was repeated on the dashboard. Adding the arm is a copy
    job before it is a code job: it needs an iOS headline and an iOS
    instruction in all 26 locales, because the two strings below are written in
    the desktop voice ("… on your phone") and read as nonsense to somebody who
    is already holding the phone.

    Accent CTA uses --color-emerald per UI-SPEC §4 reserved-for list.
    Copy contract per UI-SPEC §14.
--}}
<div
    x-data="{
        shown: false,
        installable: false,
        deferredPrompt: null,
        offer: null,
        init() {
            // Check localStorage persistence before showing.
            // If the user dismissed within the last 30 days, stay hidden.
            try {
                const ts = parseInt(localStorage.getItem('beatrax-install-hint-dismissed') || '0', 10);
                if (ts > 0 && Date.now() - ts < 30 * 24 * 3600 * 1000) {
                    return;
                }
            } catch (e) {}
            // The stash first, because beforeinstallprompt fires once per
            // document: an element Alpine initialises on a wire:navigate
            // arrival is binding for an event that has already gone, and
            // app.js caught it at module scope for exactly this read.
            this.take(window.beatraxInstallPrompt);
            this.offer = (e) => this.take(e);
            window.addEventListener('beforeinstallprompt', this.offer);
            // Always show on desktop as a feature discovery hint
            if (typeof window !== 'undefined' && window.matchMedia && window.matchMedia('(min-width: 1024px)').matches) {
                this.shown = true;
            }
        },
        // window outlives this element, so the listener has to come back off
        // it: a morph or a navigation leaves every one that does not behind,
        // each closed over a scope that is already gone.
        destroy() {
            if (this.offer) {
                window.removeEventListener('beforeinstallprompt', this.offer);
                this.offer = null;
            }
        },
        take(prompt) {
            if (! prompt) return;
            if (typeof prompt.preventDefault === 'function') prompt.preventDefault();
            this.deferredPrompt = prompt;
            this.installable = true;
            this.shown = true;
        },
        dismiss() {
            this.shown = false;
            try { localStorage.setItem('beatrax-install-hint-dismissed', String(Date.now())); } catch (e) {}
        },
        async install() {
            if (!this.deferredPrompt) return;
            this.deferredPrompt.prompt();
            await this.deferredPrompt.userChoice;
            // The stash goes with it. A prompt() may only be answered once, so
            // a copy left on the window would hand the next screen an offer
            // the browser has already spent.
            this.deferredPrompt = null;
            window.beatraxInstallPrompt = null;
            this.shown = false;
        },
    }"
    x-show="shown"
    x-cloak
    {{ $attributes }}
>
    <aside
        class="card"
        style="padding: var(--space-3); display: flex; flex-direction: column; gap: var(--space-2);"
        aria-label="{{ Lang::get('core::components.install.aria') }}"
    >
        <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: var(--space-3);">
            <div>
                <p style="font-size: var(--text-base); font-weight: 500; color: var(--color-text); margin: 0 0 4px;">
                    {{ Lang::get('core::components.install.headline') }}
                </p>
                <p style="font-size: var(--text-sm); color: var(--color-text-muted); margin: 0;">
                    {{ Lang::get('core::components.install.body') }}
                </p>
            </div>
            <x-core::emoji-action
                :label="Lang::get('core::components.install.dismiss_aria')"
                :caption="Lang::get('core::components.install.dismiss_caption')"
                x-on:click="dismiss()"
            >✖️</x-core::emoji-action>
        </div>

        <div style="display: flex; align-items: center; gap: var(--space-2); flex-wrap: wrap;">
            {{-- Install CTA: shown when browser fires beforeinstallprompt (Chromium on phone) --}}
            <button
                type="button"
                x-show="installable"
                x-on:click="install()"
                style="display: inline-flex; align-items: center; padding: 8px 16px; background: var(--color-emerald); color: var(--color-text-inverse); border: 0; border-radius: var(--radius-md); font-size: var(--text-sm); font-weight: 600; cursor: pointer; transition: var(--tx-quick);"
            >
                {{ Lang::get('core::components.install.install_app') }}
            </button>

            {{-- Desktop CTA: open on phone. App-static copy with an inline
                 <strong>⚡</strong> span, so render unescaped. --}}
            <span
                x-show="!installable"
                style="font-size: var(--text-sm); color: var(--color-text-muted);"
            >
                {!! Lang::get('core::components.install.desktop_html') !!}
            </span>
        </div>
    </aside>
</div>
