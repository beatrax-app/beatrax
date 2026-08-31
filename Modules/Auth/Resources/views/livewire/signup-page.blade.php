@use('Modules\Core\Public\Support\Lang')
{{-- The system bars are drawn over this screen, not beside it: without the
     inset the "Create the first account" button sits under the Android
     navigation bar and the back link under the status bar. --}}
<div class="safe-screen min-h-screen flex items-center justify-center bg-white dark:bg-slate-950">
    <div class="w-full max-w-md mx-auto px-4 sm:px-6 space-y-6">
        @if ($backUrl !== null)
            <p class="text-sm">
                <a
                    href="{{ $backUrl }}"
                    class="tap-link inline-flex items-center gap-1 text-slate-500 underline-offset-2 hover:underline dark:text-slate-400"
                    wire:navigate
                >
                    <span aria-hidden="true">←</span>
                    {{ Lang::get('core::components.topbar.back') }}
                </a>
            </p>
        @endif

        <x-core::page-header
            :title="Lang::get('auth::signup.title')"
            :subtitle="Lang::get('auth::signup.subtitle')"
        />

        {{-- Above the account form, not after it. Both controls list places,
             and "Nederlands" and "Nederland" differ by two letters, so each
             gets its own card and each help line says what the OTHER one does
             not do. Below the submit button they came last in the tab order,
             after the control that leaves the screen, which is not what "asked
             at signup" means. Autofocus still lands in the username box.

             Outside the <form> on purpose: the country select is a deferred
             wire:model rather than a form field, so it ships from here just as
             well, and the language control is a sibling of it rather than of
             the boxes it must not empty. --}}
        <div class="space-y-4">
            <div class="rounded-lg border border-slate-200 p-4 dark:border-slate-800">
                {{-- A Livewire action, not the shared POST: that one navigates,
                     and it took this half-filled form with it — three empty
                     boxes and a country back at its placeholder. --}}
                <x-core::locale-switcher labelled model="locale" />
            </div>

            <div class="rounded-lg border border-slate-200 p-4 dark:border-slate-800">
                <x-core::form-field
                    field-id="signup-country"
                    name="country"
                    type="select"
                    :label="Lang::get('core::settings.country.label')"
                    :hint="Lang::get('core::settings.country.help')"
                    wire:model="country"
                >
                    {{-- The placeholder stays choosable: skipping the country
                         is a real answer here, and a reader who picked one by
                         mistake has to be able to put it back. --}}
                    <x-core::country-options :options="$countryOptions" :selected="$country" />
                </x-core::form-field>
            </div>
        </div>

        <form wire:submit="submit" class="space-y-4">
            <x-core::form-field
                name="username"
                :label="Lang::get('auth::signup.username')"
                :hint="Lang::get('auth::signup.username_hint')"
                wire:model.live.blur="username"
                autocomplete="username"
                autofocus
            />

            {{-- The live checklist below describes this field better than the
                 hint line does, and a named descriptor replaces the hint id
                 rather than joining it. The error id is appended either way. --}}
            <x-core::form-field
                name="password"
                type="password"
                :label="Lang::get('auth::signup.password')"
                :hint="Lang::get('auth::signup.password_hint')"
                wire:model.live.blur="password"
                autocomplete="new-password"
                aria-describedby="password-requirements"
            />

            <x-core::form-field
                field-id="password-confirmation"
                name="passwordConfirmation"
                type="password"
                :label="Lang::get('auth::signup.confirm_password')"
                wire:model.live.blur="passwordConfirmation"
                autocomplete="new-password"
            />

            <x-core::password-requirements
                :aria-label="Lang::get('auth::signup.requirements_aria')"
                :length-label="Lang::get('auth::signup.req_length')"
                :match-label="Lang::get('auth::signup.req_match')"
                :met="Lang::get('auth::signup.req_met')"
                :unmet="Lang::get('auth::signup.req_unmet')"
            />

            @if ($flashMessage !== '')
                <p class="text-sm text-rose-600 dark:text-rose-400" role="alert">{{ $flashMessage }}</p>
            @endif

            <x-core::primary-button>
                {{ Lang::get('auth::signup.submit') }}
            </x-core::primary-button>
        </form>
    </div>
</div>
