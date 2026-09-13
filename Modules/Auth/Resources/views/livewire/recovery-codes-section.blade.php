@use('Modules\Core\Public\Support\Lang')
{{--
    Recovery-codes section for the Core settings page, mounted via
    @livewire('auth.recovery-codes-section').

    There is no "show my codes" affordance because there is nothing to show:
    the codes are stored hashed and the plaintext exists only in the session
    during the post-signup ceremony. Regenerating is the honest offer, and the
    copy says plainly that it retires the old set.

    The password box is not a second confirmation. wire:confirm below says what
    is lost; this says who is asking, which a session somebody else is holding
    cannot answer.
--}}
<div>
    <p class="text-sm text-slate-500 dark:text-slate-400">
        {{ Lang::get('auth::recovery_codes.settings.body') }}
    </p>
    <p class="mt-1 text-xs text-[var(--color-text-muted)]">
        {{ Lang::get('auth::recovery_codes.settings.warning') }}
    </p>

    @if ($flashMessage !== '')
        <p class="mt-3 text-sm text-rose-600 dark:text-rose-400" role="alert" data-testid="recovery-codes-failure">
            {{ $flashMessage }}
        </p>
    @endif

    <div class="mt-3">
        <x-core::form-field
            :label="Lang::get('auth::recovery_codes.settings.password_label')"
            name="accountPassword"
            field-id="recovery-codes-account-password"
            type="password"
            class="max-w-xs"
            autocomplete="current-password"
            wire:model="accountPassword"
            data-testid="recovery-codes-password"
        />
    </div>

    <x-core::secondary-button
        size="sm"
        class="mt-3"
        wire:click="regenerate"
        wire:confirm="{{ Lang::get('auth::recovery_codes.settings.regenerate_confirm') }}"
        wire:loading.attr="disabled"
    >{{ Lang::get('auth::recovery_codes.settings.regenerate') }}</x-core::secondary-button>
</div>
