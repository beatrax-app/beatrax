@use('Modules\Core\Public\Support\Lang')
{{-- The system bars are drawn over this screen, not beside it: without the
     inset the "Create the first account" button sits under the Android
     navigation bar and the back link under the status bar. --}}
<div class="min-h-screen flex items-center justify-center bg-white pb-[var(--safe-bottom)] pl-[var(--safe-left)] pr-[var(--safe-right)] pt-[var(--safe-top)] dark:bg-slate-950">
    <div
        class="w-full max-w-md mx-auto px-6 space-y-6"
        x-data="{
            get typedPassword() { return $wire.password ?? ''; },
            get typedConfirmation() { return $wire.passwordConfirmation ?? ''; },
            get lengthOk() { return this.typedPassword.length >= 12; },
            get matchOk() { return this.typedConfirmation.length > 0 && this.typedPassword === this.typedConfirmation; },
        }"
    >
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
                    <option value="">{{ Lang::get('core::settings.country.choose') }}</option>
                    @foreach ($countryOptions as $countryCode => $countryName)
                        <option value="{{ $countryCode }}">{{ $countryName }}</option>
                    @endforeach
                </x-core::form-field>
            </div>
        </div>

        <form wire:submit="submit" class="space-y-4">
            <x-core::form-field
                name="username"
                :label="Lang::get('auth::signup.username')"
                :hint="Lang::get('auth::signup.username_hint')"
                wire:model="username"
                autocomplete="username"
                autofocus
            />

            {{-- aria-describedby names the live checklist below; the component
                 appends its own hint id, so the field points at both. --}}
            <x-core::form-field
                name="password"
                type="password"
                :label="Lang::get('auth::signup.password')"
                :hint="Lang::get('auth::signup.password_hint')"
                wire:model="password"
                autocomplete="new-password"
                aria-describedby="password-requirements"
            />

            <x-core::form-field
                field-id="password-confirmation"
                name="passwordConfirmation"
                type="password"
                :label="Lang::get('auth::signup.confirm_password')"
                wire:model="passwordConfirmation"
                autocomplete="new-password"
            />

            {{-- Live requirement checklist — each row ticks as the field is typed
                 (client-side, no roundtrip). It reads the SAME binding the server
                 validates rather than a private mirror of it: a mirror fed only by
                 input events went on showing two green ticks after a re-render
                 emptied both boxes, under an error saying the password was too
                 short. --}}
            <ul id="password-requirements" class="space-y-1.5" aria-live="polite" aria-label="{{ Lang::get('auth::signup.requirements_aria') }}">
                <template x-for="req in [
                    { label: '{{ Lang::get('auth::signup.req_length') }}', ok: lengthOk },
                    { label: '{{ Lang::get('auth::signup.req_match') }}', ok: matchOk },
                ]" :key="req.label">
                    <li class="flex items-center gap-2 text-xs transition-colors"
                        :class="req.ok ? 'text-emerald-600 dark:text-emerald-400' : 'text-slate-400 dark:text-slate-500'">
                        <span
                            class="inline-flex h-4 w-4 shrink-0 items-center justify-center rounded-full border transition-colors"
                            :class="req.ok
                                ? 'border-emerald-600 bg-emerald-600 text-white dark:border-emerald-400 dark:bg-emerald-400 dark:text-slate-950'
                                : 'border-slate-300 text-transparent dark:border-slate-600'"
                            aria-hidden="true"
                        >
                            <svg viewBox="0 0 12 12" class="h-2.5 w-2.5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M2.5 6.5 4.8 8.8 9.5 3.5" />
                            </svg>
                        </span>
                        <span x-text="req.label"></span>
                        <span class="sr-only" x-text="req.ok ? '{{ Lang::get('auth::signup.req_met') }}' : '{{ Lang::get('auth::signup.req_unmet') }}'"></span>
                    </li>
                </template>
            </ul>

            @if ($flashMessage !== '')
                <p class="text-sm text-rose-600 dark:text-rose-500">{{ $flashMessage }}</p>
            @endif

            <x-core::primary-button>
                {{ Lang::get('auth::signup.submit') }}
            </x-core::primary-button>
        </form>
    </div>
</div>
