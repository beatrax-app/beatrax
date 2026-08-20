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
@use('Illuminate\View\ComponentAttributeBag')
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
        <x-core::setting-row
            :label="Lang::get('mobile::biometric.toggle_label')"
            :description="Lang::get('mobile::biometric.toggle_help')"
        >
            {{-- Turning biometrics ON is the PIN panel's job below, so only the
                 enrolled toggle carries a handler. Blade does not parse an @if
                 between the attributes of an <x-…> tag, hence the bag. --}}
            @php
                $biometricToggleAction = new ComponentAttributeBag($enrolled ? ['wire:click' => 'disable'] : []);
            @endphp
            <x-core::switch
                :on="$enrolled"
                :label="Lang::get('mobile::biometric.toggle_aria')"
                :attributes="$biometricToggleAction"
            />
        </x-core::setting-row>

        @unless ($enrolled)
            {{-- One step darker than the usual sub-panel fill: the PIN field
                 inside is bg-slate-50 / dark:bg-slate-900, the exact slate this
                 panel used to be, so a 1px border was all that separated the
                 control from its container. --}}
            <div class="space-y-4 rounded-xl border border-slate-200 bg-slate-100 p-4 dark:border-slate-700 dark:bg-slate-800">
                <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('mobile::biometric.confirm_pin_heading') }}</h3>

                {{-- tabular-nums ADDS to the component's control classes rather
                     than contradicting one, which is the only kind of class this
                     field may pass: digits typed into a PIN should not reflow. --}}
                <x-core::form-field
                    :label="Lang::get('mobile::biometric.current_pin')"
                    name="pin"
                    field-id="cold-start-pin"
                    type="password"
                    class="tabular-nums"
                    inputmode="numeric"
                    wire:model="pin"
                    autocomplete="off"
                />

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
