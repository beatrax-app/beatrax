@use('Modules\Core\Public\Support\Lang')
{{--
    Connect-email step — wizard step 4 (optional). Renders two provider
    CTAs ("Authorize with Gmail" / "Authorize with Outlook") that
    dispatch `oauth-client-wizard:open` to the
    `Modules\EmailScan\Public\Http\Livewire\OAuthClientWizardModal`.
    The modal itself is mounted globally by the wizard layout, so this
    step is purely the routing layer and never duplicates the OAuth
    dance in its own markup.

    `wizard.step.completed` is emitted from `onAuthSaved()` in the
    Livewire component (a `#[On('oauth-client-wizard:saved')]`
    listener) once the modal saves credentials successfully.
--}}
<section class="wiz-step wiz-step-connect-email" aria-labelledby="wiz-connect-email-h1">
    <x-onboarding::wiz-eyebrow step="connect-email" glyph="✉️">{{ Lang::get('onboarding::connect_email.eyebrow') }}</x-onboarding::wiz-eyebrow>
    <h1 id="wiz-connect-email-h1" class="wiz-h1">
        {{ Lang::get('onboarding::connect_email.h1') }}
    </h1>
    <p class="wiz-lede">
        {{ Lang::get('onboarding::connect_email.lede') }}
    </p>

    <div class="mini-steps">
        <x-onboarding::mini-step glyph="🔐" :label="Lang::get('onboarding::connect_email.mini.signin_label')" :sub="Lang::get('onboarding::connect_email.mini.signin_sub')" state="done" />
        <x-onboarding::mini-step glyph="📑" :label="Lang::get('onboarding::connect_email.mini.scope_label')" :sub="Lang::get('onboarding::connect_email.mini.scope_sub')" state="now" />
        <x-onboarding::mini-step glyph="✉️" :label="Lang::get('onboarding::connect_email.mini.reads_label')" :sub="Lang::get('onboarding::connect_email.mini.reads_sub')" state="upcoming" />
        <x-onboarding::mini-step glyph="🔒" :label="Lang::get('onboarding::connect_email.mini.token_label')" :sub="Lang::get('onboarding::connect_email.mini.token_sub')" state="upcoming" />
    </div>

    <div class="wiz-email-auth">
        <button
            type="button"
            class="pill-btn-primary"
            wire:click="authorizeProvider('gmail')"
        >
            {{ Lang::get('onboarding::connect_email.authorize_gmail') }}
        </button>
        <button
            type="button"
            class="pill-btn-primary"
            wire:click="authorizeProvider('microsoft')"
        >
            {{ Lang::get('onboarding::connect_email.authorize_outlook') }}
        </button>
    </div>

    <x-onboarding::wiz-actions>
        <button
            type="button"
            class="pill-btn-ghost"
            wire:click="skip"
        >
            {{ Lang::get('onboarding::connect_email.skip') }}
        </button>
    </x-onboarding::wiz-actions>
</section>
