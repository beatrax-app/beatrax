{{--
    Connect-email step — wizard step 4 (optional). Renders the UI-SPEC
    §"Wizard connector step — optional email" copy verbatim. Replaces
    the drop-zone primitive with two provider CTAs ("Authorize with
    Gmail" / "Authorize with Outlook"); both wire to the parent
    `Modules\EmailScan\Internal\Http\Livewire\OAuthClientWizardModal`
    via Livewire events. The modal is mounted inline so the wizard
    does not duplicate the OAuth dance — this step is purely the
    routing layer.

    `wizard.step.completed` is emitted from `onAuthSaved()` in the
    Livewire component (a `#[On('oauth-client-wizard:saved')]`
    listener) once the modal saves credentials successfully.
--}}
<section class="wiz-step wiz-step-connect-email" aria-labelledby="wiz-connect-email-h1">
    <p class="wiz-eyebrow">✉️ Step 4 — Receipts from email (optional)</p>
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

    {{-- Mount the existing OAuth modal inline so the wizard never
         duplicates the OAuth dance. The modal listens for
         `oauth-client-wizard:open` and emits `oauth-client-wizard:saved`
         which this step's `onAuthSaved()` handler converts into
         `wizard.step.completed`. --}}
    <livewire:email-scan.oauth-client-wizard-modal />

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
