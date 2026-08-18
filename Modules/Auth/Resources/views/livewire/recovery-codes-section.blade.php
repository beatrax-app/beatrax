@use('Modules\Core\Public\Support\Lang')
{{--
    Recovery-codes section for the Core settings page, mounted via
    @livewire('auth.recovery-codes-section').

    There is no "show my codes" affordance because there is nothing to show:
    the codes are stored hashed and the plaintext exists only in the session
    during the post-signup ceremony. Regenerating is the honest offer, and the
    copy says plainly that it retires the old set.
--}}
<div>
    <p class="text-sm text-slate-500 dark:text-slate-400">
        {{ Lang::get('auth::recovery_codes.settings.body') }}
    </p>
    <p class="mt-1 text-xs text-[var(--color-text-muted)]">
        {{ Lang::get('auth::recovery_codes.settings.warning') }}
    </p>
    <button
        type="button"
        wire:click="regenerate"
        wire:loading.attr="disabled"
        class="mt-3 inline-flex items-center rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-900 hover:bg-slate-50 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:hover:bg-slate-800"
    >{{ Lang::get('auth::recovery_codes.settings.regenerate') }}</button>
</div>
