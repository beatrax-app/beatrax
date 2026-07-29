{{--
    Standing install-hint surface (D-22, UI-SPEC §14).

    Two placements: dashboard card (bottom of main content column) and
    Settings → General row ("Install as app"). Use the same component in both.

    Behavior:
    - Captures beforeinstallprompt on phones/Chromium to show a native install CTA.
    - Always shown on desktop as a feature-discovery hint ("Also want to see your
      data on your phone?") pointing the user to open beatrax on their phone.
    - Dismissable but returns (standing hint, not one-time dismissed forever).
    - iOS Safari: shows instructions ("Tap Share, then Add to Home Screen") since
      beforeinstallprompt is not supported.

    Accent CTA uses --color-emerald per UI-SPEC §4 reserved-for list.
    Copy contract per UI-SPEC §14.
--}}
<div
    x-data="{
        shown: false,
        installable: false,
        deferredPrompt: null,
        init() {
            // Check localStorage persistence before showing.
            // If the user dismissed within the last 30 days, stay hidden.
            try {
                const ts = parseInt(localStorage.getItem('beatrax-install-hint-dismissed') || '0', 10);
                if (ts > 0 && Date.now() - ts < 30 * 24 * 3600 * 1000) {
                    return;
                }
            } catch (e) {}
            window.addEventListener('beforeinstallprompt', (e) => {
                e.preventDefault();
                this.deferredPrompt = e;
                this.installable = true;
                this.shown = true;
            });
            // Always show on desktop as a feature discovery hint
            if (typeof window !== 'undefined' && window.matchMedia && window.matchMedia('(min-width: 1024px)').matches) {
                this.shown = true;
            }
        },
        dismiss() {
            this.shown = false;
            try { localStorage.setItem('beatrax-install-hint-dismissed', String(Date.now())); } catch (e) {}
        },
        async install() {
            if (!this.deferredPrompt) return;
            this.deferredPrompt.prompt();
            await this.deferredPrompt.userChoice;
            this.deferredPrompt = null;
            this.shown = false;
        },
    }"
    x-show="shown"
    x-cloak
>
    <aside
        class="card"
        style="padding: var(--space-3); display: flex; flex-direction: column; gap: var(--space-2);"
        aria-label="Install beatrax"
    >
        <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: var(--space-3);">
            <div>
                <p style="font-size: var(--text-base); font-weight: 500; color: var(--color-text); margin: 0 0 4px;">
                    Also want to see your data on your phone?
                </p>
                <p style="font-size: var(--text-sm); color: var(--color-text-muted); margin: 0;">
                    Install beatrax on your phone for quick access to your finances.
                </p>
            </div>
            <button
                type="button"
                x-on:click="dismiss()"
                style="display: inline-flex; align-items: center; justify-content: center; min-width: 28px; min-height: 28px; border: 0; background: transparent; cursor: pointer; color: var(--color-text-faint); border-radius: var(--radius-sm); flex-shrink: 0;"
                aria-label="Dismiss install hint"
            >
                <span aria-hidden="true" style="font-size: 18px; line-height: 1;">×</span>
            </button>
        </div>

        <div style="display: flex; align-items: center; gap: var(--space-2); flex-wrap: wrap;">
            {{-- Install CTA: shown when browser fires beforeinstallprompt (Chromium on phone) --}}
            <button
                type="button"
                x-show="installable"
                x-on:click="install()"
                style="display: inline-flex; align-items: center; padding: 8px 16px; background: var(--color-emerald); color: var(--color-text-inverse); border: 0; border-radius: var(--radius-md); font-size: var(--text-sm); font-weight: 600; cursor: pointer; transition: var(--tx-quick);"
            >
                Install app
            </button>

            {{-- Desktop CTA: open on phone --}}
            <span
                x-show="!installable"
                style="font-size: var(--text-sm); color: var(--color-text-muted);"
            >
                Open beatrax in your phone's browser and tap "Add to Home Screen" — or tap the
                <strong style="color: var(--color-text);">⚡</strong> icon in Safari's share sheet.
            </span>
        </div>
    </aside>
</div>
