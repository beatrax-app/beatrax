@use('Modules\Core\Public\Support\Lang')
{{-- .safe-screen: layouts.app draws no bar for a signed-out reader, so this
     screen is the only thing between its own content and the system bars. --}}
<div class="safe-screen min-h-screen flex items-center justify-center bg-white dark:bg-slate-950">
    <div class="w-full max-w-sm px-6 space-y-6">
        <x-core::page-header
            :title="Lang::get('auth::login.title')"
            :subtitle="Lang::get('auth::login.subtitle')"
        />

        @if ($restored !== '')
            <x-core::alert tone="positive">
                <p>{{ $restored }}</p>
                @if ($snapshotPath !== '')
                    <p class="mt-1 text-xs opacity-80">{{ Lang::get('core::backup.restore.snapshot_saved_prefix') }} <code class="font-mono">{{ $snapshotPath }}</code>.</p>
                @endif
            </x-core::alert>
        @endif

        <form wire:submit="submit" class="space-y-4">
            <x-core::form-field
                name="username"
                :label="Lang::get('auth::login.username')"
                wire:model="username"
                autocomplete="username"
            />

            <x-core::form-field
                name="password"
                type="password"
                :label="Lang::get('auth::login.password')"
                wire:model="password"
                autocomplete="current-password"
            />

            {{-- Shown by the meter, which is consulted before any account is
                 looked up, so a username nobody has offers this escape exactly
                 as one somebody has does. --}}
            @if ($recoveryOffered)
                <div class="space-y-2">
                    <p class="text-sm text-slate-600 dark:text-slate-400">
                        {{ Lang::get('auth::login.throttled_recovery') }}
                    </p>

                    <x-core::form-field
                        field-id="recovery-code"
                        name="recoveryCode"
                        :label="Lang::get('auth::reset_password.recovery_code')"
                        :hint="Lang::get('auth::reset_password.recovery_code_hint')"
                        wire:model="recoveryCode"
                        autocomplete="off"
                        placeholder="A2BJ-XK9M-PQ7N-RX4F-V8HD"
                        class="font-mono"
                    />
                </div>
            @endif

            <x-core::checkbox-field
                field-id="remember-me"
                :label="Lang::get('auth::login.remember')"
                wire:model="rememberMe"
            />

            @if ($flashMessage !== '')
                <p class="text-sm text-rose-600 dark:text-rose-400" role="alert">{{ $flashMessage }}</p>
            @endif

            <x-core::primary-button>
                {{ Lang::get('auth::login.submit') }}
            </x-core::primary-button>
        </form>

        <p class="text-sm">
            <a
                href="/reset-password"
                class="tap-link text-slate-500 underline underline-offset-2 hover:text-slate-900 dark:hover:text-slate-100 dark:text-slate-400"
            >
                {{ Lang::get('auth::login.lost_password') }}
            </a>
        </p>

        <x-core::locale-switcher />
    </div>
</div>
