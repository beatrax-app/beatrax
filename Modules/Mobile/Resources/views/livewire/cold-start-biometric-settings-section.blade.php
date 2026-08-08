{{--
    Cold-start biometric unlock settings (mobile).
    Mounted via @livewire('mobile.cold-start-biometric-settings-section').

    Copy:
      - Heading: "Biometric unlock"
      - Toggle label: "Unlock with Face ID / Touch ID"
      - Description: "Unlock the app after a restart with biometrics instead of
        your PIN. Your PIN is still required periodically and when biometrics
        change."
      - Empty state (no vault): "Biometric unlock is only available in the
        Beatrax mobile app."
--}}
@use('Modules\Core\Public\Support\Lang')

<div class="space-y-6">
    <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('mobile::biometric.heading') }}</h2>

    @if ($flashMessage !== '')
        <p class="text-sm text-rose-600 dark:text-rose-400" role="alert">{{ $flashMessage }}</p>
    @endif

    @unless ($available)
        <p class="text-sm text-slate-500 dark:text-slate-400">
            {{ Lang::get('mobile::biometric.unavailable') }}
        </p>
    @else
        <div class="flex items-start justify-between gap-4">
            <div class="flex-1 min-w-0">
                <p class="text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('mobile::biometric.toggle_label') }}</p>
                <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
                    {{ Lang::get('mobile::biometric.toggle_help') }}
                </p>
            </div>
            <button
                type="button"
                @if ($enrolled) wire:click="disable" @endif
                class="switch{{ $enrolled ? ' switch--on' : '' }}"
                aria-pressed="{{ $enrolled ? 'true' : 'false' }}"
                aria-label="{{ Lang::get('mobile::biometric.toggle_aria') }}"
            >
                <span class="switch__thumb"></span>
            </button>
        </div>

        @unless ($enrolled)
            <div class="space-y-4 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-900">
                <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('mobile::biometric.confirm_pin_heading') }}</h3>

                <div class="space-y-1">
                    <label for="cold-start-pin" class="block text-sm text-slate-700 dark:text-slate-300">{{ Lang::get('mobile::biometric.current_pin') }}</label>
                    <input
                        id="cold-start-pin"
                        type="password"
                        inputmode="numeric"
                        autocomplete="off"
                        wire:model="pin"
                        class="w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 tabular-nums focus:border-blue-500 focus:outline-none focus:ring-1 focus:ring-blue-500 dark:border-slate-600 dark:bg-slate-950 dark:text-slate-100"
                    />
                </div>

                <button
                    type="button"
                    wire:click="enable"
                    class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-800 dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-white"
                >
                    {{ Lang::get('mobile::biometric.enable') }}
                </button>
            </div>
        @endunless
    @endunless
</div>
