{{--
    Connect-email step — wizard step 4 (optional). Renders two provider
    CTAs ("Authorize with Gmail" / "Authorize with Outlook") that
    dispatch `oauth-client-wizard:open` to the
    `Modules\EmailScan\Internal\Http\Livewire\OAuthClientWizardModal`.
    The modal itself is mounted globally by the wizard layout, so this
    step is purely the routing layer and never duplicates the OAuth
    dance in its own markup.

    `wizard.step.completed` is emitted from `onAuthSaved()` in the
    Livewire component (a `#[On('oauth-client-wizard:saved')]`
    listener) once the modal saves credentials successfully.
--}}
<section class="wiz-step wiz-step-connect-email" aria-labelledby="wiz-connect-email-h1">
    <x-onboarding::wiz-eyebrow step="connect-email" glyph="✉️">Receipts from email (optional)</x-onboarding::wiz-eyebrow>
    <h1 id="wiz-connect-email-h1" class="wiz-h1">
        Let beatrax watch for purchase emails
    </h1>
    <p class="wiz-lede">
        Connect Gmail or Outlook so order confirmations and subscription receipts
        auto-attach to your transactions. You can skip this and add it later.
    </p>

    <div class="mini-steps">
        <x-onboarding::mini-step glyph="🔐" label="Sign in" sub="Google or Microsoft" state="done" />
        <x-onboarding::mini-step glyph="📑" label="Approve scope" sub="Read-only access" state="now" />
        <x-onboarding::mini-step glyph="✉️" label="beatrax reads" sub="Receipts only" state="upcoming" />
        <x-onboarding::mini-step glyph="🔒" label="Token stays local" sub="Encrypted file" state="upcoming" />
    </div>

    <div class="wiz-email-auth">
        <button
            type="button"
            class="pill-btn-primary"
            wire:click="authorizeProvider('gmail')"
        >
            Authorize with Gmail
        </button>
        <button
            type="button"
            class="pill-btn-primary"
            wire:click="authorizeProvider('microsoft')"
        >
            Authorize with Outlook
        </button>
    </div>

    <x-onboarding::wiz-actions>
        <button
            type="button"
            class="pill-btn-ghost"
            wire:click="skip"
        >
            Skip — set up later in Settings
        </button>
    </x-onboarding::wiz-actions>
</section>
