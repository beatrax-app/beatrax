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
            <div class="space-y-1">
                <label for="username" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('auth::signup.username') }}</label>
                <input
                    type="text"
                    id="username"
                    wire:model="username"
                    autocomplete="username"
                    autofocus
                    aria-describedby="username-hint"
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700"
                />
                <p id="username-hint" class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('auth::signup.username_hint') }}</p>
            </div>

            <div class="space-y-1">
                <label for="password" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('auth::signup.password') }}</label>
                <input
                    type="password"
                    id="password"
                    wire:model="password"
                    x-on:input="pw = $event.target.value"
                    autocomplete="new-password"
                    aria-describedby="password-requirements"
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700"
                />
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('auth::signup.password_hint') }}</p>
            </div>

            <div class="space-y-1">
                <label for="password-confirmation" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('auth::signup.confirm_password') }}</label>
                <input
                    type="password"
                    id="password-confirmation"
                    wire:model="passwordConfirmation"
                    x-on:input="pwc = $event.target.value"
                    autocomplete="new-password"
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 placeholder:text-slate-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:bg-slate-900 dark:text-slate-100 dark:border-slate-700"
                />
            </div>

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
