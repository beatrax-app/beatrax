<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Http\Livewire\Steps;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Wizard step 4 (optional) — connect Gmail or Microsoft 365 so
 * order-confirmation and subscription-receipt emails auto-attach to
 * matching transactions. The OAuth dance + secrets-on-disk persistence
 * live in `Modules\EmailScan\Internal\Http\Livewire\OAuthClientWizardModal`;
 * this step does NOT duplicate that surface — the modal is mounted
 * globally by the wizard layout and opened via the Livewire event the
 * modal already listens for (`oauth-client-wizard:open`).
 *
 * Flow:
 *  1. User clicks "Authorize with Gmail" or "Authorize with Outlook";
 *     `authorizeProvider($provider)` validates the provider against the closed
 *     allow-list and dispatches `oauth-client-wizard:open` with the
 *     provider name. The OAuthClientWizardModal's `#[On(...)]` handler
 *     opens the modal in the provider-specific layout and runs the
 *     full OAuth dance.
 *  2. On successful credential save the modal dispatches
 *     `oauth-client-wizard:saved`. The `onAuthSaved()` listener here
 *     dispatches `wizard.step.completed` so the SetupWizard parent
 *     marks the email row `done` and advances.
 *  3. Skipping marks the row `skipped` via `wizard.step.skipped`. Email
 *     is the canonical "optional" wizard step — skip is the most
 *     common exit path.
 *
 * No file upload, no secrets state on this component; this step is a
 * thin event router between the wizard parent and the existing OAuth
 * modal.
 */
final class ConnectEmailStep extends Component
{
    /**
     * Tracks which provider the user clicked authorize for. Cleared
     * after the modal dispatches `oauth-client-wizard:saved` or the
     * user cancels. Used by the blade to dim the non-clicked button
     * while the modal is open.
     */
    public ?string $authStartedFor = null;

    /**
     * Validates the provider against the closed allow-list and opens
     * the OAuthClientWizardModal. The modal's open() handler accepts
     * an optional `?int $inboxId = null` second argument — for the
     * wizard flow we omit it (fresh-connect, not a re-consent).
     */
    public function authorizeProvider(string $provider): void
    {
        if (! in_array($provider, ['gmail', 'microsoft'], strict: true)) {
            return;
        }

        $this->authStartedFor = $provider;
        $this->dispatch('oauth-client-wizard:open', provider: $provider);
    }

    /**
     * Fires when the OAuthClientWizardModal persists credentials to
     * disk. The modal already redirects the user to the per-provider
     * consent route; from the wizard's perspective the "email is
     * connected" milestone is hit here.
     */
    #[On('oauth-client-wizard:saved')]
    public function onAuthSaved(): void
    {
        $this->authStartedFor = null;
        $this->dispatch('wizard.step.completed');
    }

    /**
     * Marks this step `skipped` at the parent. The user can come back
     * to Settings → Email scan to connect a provider later; the
     * wizard never blocks finish on email.
     */
    public function skip(): void
    {
        $this->dispatch('wizard.step.skipped');
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('onboarding::livewire.steps.connect-email-step');
    }
}
