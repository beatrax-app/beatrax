@use('Modules\Core\Public\Support\Lang')
<div class="min-h-screen flex items-center justify-center bg-white dark:bg-slate-950">
    <div
        class="w-full max-w-md mx-auto px-6 space-y-6"
        x-data="{
            pw: '',
            pwc: '',
            get lengthOk() { return this.pw.length >= 12; },
            get matchOk() { return this.pwc.length > 0 && this.pw === this.pwc; },
        }"
    >
        <x-core::page-header
            :title="Lang::get('auth::signup.title')"
            :subtitle="Lang::get('auth::signup.subtitle')"
        />

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
                x-on:input="pw = $event.target.value"
                autocomplete="new-password"
                aria-describedby="password-requirements"
            />

            <x-core::form-field
                field-id="password-confirmation"
                name="passwordConfirmation"
                type="password"
                :label="Lang::get('auth::signup.confirm_password')"
                wire:model="passwordConfirmation"
                x-on:input="pwc = $event.target.value"
                autocomplete="new-password"
            />

            {{-- Live requirement checklist — each row ticks as the field is typed (client-side, no roundtrip). --}}
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

        <x-core::locale-switcher />
    </div>
</div>
