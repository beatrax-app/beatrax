@use('Modules\Core\Public\Support\Lang')
{{-- .safe-screen: nothing links to this page — it is where the forced-change
     guard sends a partner on their first sign-in — so it is a first-run
     ceremony and draws no menubar above itself. The stylesheet zeroes the top
     inset for a document that does carry a .top-bar, so the class stays
     correct if the page is ever reached from inside the app. --}}
<div class="safe-screen min-h-screen flex items-center justify-center bg-white dark:bg-slate-950">
    <div class="w-full max-w-sm px-6 space-y-6">
        <x-core::page-header
            :title="Lang::get('auth::change_password.title')"
            :subtitle="Lang::get('auth::change_password.subtitle')"
        />

        <form wire:submit="submit" class="space-y-4">
            <x-core::form-field
                field-id="current-password"
                name="currentPassword"
                type="password"
                :label="Lang::get('auth::change_password.current_password')"
                wire:model="currentPassword"
                autocomplete="current-password"
                autofocus
            />

            <x-core::form-field
                field-id="new-password"
                name="newPassword"
                type="password"
                :label="Lang::get('auth::change_password.new_password')"
                wire:model="newPassword"
                autocomplete="new-password"
            />

            <x-core::form-field
                field-id="new-password-confirmation"
                name="newPasswordConfirmation"
                type="password"
                :label="Lang::get('auth::change_password.confirm_new_password')"
                wire:model="newPasswordConfirmation"
                autocomplete="new-password"
            />

            @if ($flashMessage !== '')
                <p class="text-sm text-rose-600 dark:text-rose-400" role="alert">{{ $flashMessage }}</p>
            @endif

            <x-core::primary-button>
                {{ Lang::get('auth::change_password.submit') }}
            </x-core::primary-button>
        </form>

        {{-- The sidebar's sign-out used to be the only one on this screen, and
             the forced-change guard exempts sign-out precisely so a partner who
             will not set a password here can still leave. Withholding the
             menubar took the exemption's only control with it, so the page
             carries its own — the same form the lock screen uses. --}}
        <form method="POST" action="{{ route('logout') }}" data-beatrax-post x-data x-on:submit.prevent="beatraxSubmitPostForm($el, $event.submitter)">
            @csrf
            <button
                type="submit"
                class="w-full text-center text-sm text-rose-600 dark:text-rose-400
                       hover:text-rose-700 dark:hover:text-rose-300
                       focus:outline-none focus-visible:underline
                       py-2"
            >
                {{ Lang::get('auth::change_password.sign_out') }}
            </button>
        </form>
    </div>
</div>
