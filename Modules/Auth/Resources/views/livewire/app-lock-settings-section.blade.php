@use('Modules\Core\Public\Support\Lang')
{{--
    App Lock settings section — UI-SPEC §3.
    Mounted on the Data & Devices screen, not the settings page: enabling sync
    requires a lock ("Set an app lock first to enable sync"), so the two belong
    on one screen.

    Security decisions enforced here:
      - Enable flow collects the account password (D-21 recovery wrap requirement).
      - Disable and change-PIN use Flux modals for PIN-confirmation (D-23).
      - Idle timeout persists instantly without a modal (D-23 exemption).
      - Biometric row is a slot placeholder only (05-05 wires enrollment).

    Copywriting contract (UI-SPEC Copywriting section):
      - Section heading: "App lock"
      - Toggle label: "Lock app with PIN"
      - Toggle description: "Replaces daily sign-in with a PIN. Sessions stay active for 30 days."
      - Idle label: "Auto-lock after"
      - Biometric empty-state: "Biometric unlock is not available on this device."
      - Disable modal CTA: "Disable lock" / "Keep app lock"
      - Change PIN modal CTA: "Change PIN" / "Keep PIN"
--}}

<div class="space-y-6">
    <h2 class="text-lg font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('auth::app_lock.heading') }}</h2>

    @if ($flashMessage !== '')
        <p class="text-sm text-rose-600 dark:text-rose-400" role="alert">{{ $flashMessage }}</p>
    @endif

    {{-- ===== 3a: Enable / disable toggle ===== --}}
    <x-core::setting-row
        :label="Lang::get('auth::app_lock.toggle_label')"
        :description="Lang::get('auth::app_lock.toggle_description')"
    >
        <button
            type="button"
            wire:click="{{ $lockEnabled ? 'confirmDisable' : '' }}"
            class="switch{{ $lockEnabled ? ' switch--on' : '' }}"
            aria-pressed="{{ $lockEnabled ? 'true' : 'false' }}"
            aria-label="{{ Lang::get('auth::app_lock.toggle_label') }}"
        >
            <span class="switch__thumb"></span>
        </button>
    </x-core::setting-row>

    {{-- ===== 3b: PIN setup (shown when lock is not yet enabled) ===== --}}
    @if (! $lockEnabled)
        <div class="space-y-4 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-900">
            <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('auth::app_lock.setup_heading') }}</h3>

            <div class="space-y-3">
                <div class="space-y-1">
                    <label for="new-pin-input" class="block text-sm text-slate-700 dark:text-slate-300">{{ Lang::get('auth::app_lock.new_pin_label') }}</label>
                    <input
                        id="new-pin-input"
                        type="password"
                        inputmode="numeric"
                        autocomplete="new-password"
                        wire:model="newPin"
                        placeholder="········"
                        class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-base text-slate-900
                               focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                               dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus-visible:ring-slate-100"
                    />
                    @error('newPin')
                        <p class="text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
                    @enderror
                </div>

                <div class="space-y-1">
                    <label for="confirm-pin-input" class="block text-sm text-slate-700 dark:text-slate-300">{{ Lang::get('auth::app_lock.confirm_pin_label') }}</label>
                    <input
                        id="confirm-pin-input"
                        type="password"
                        inputmode="numeric"
                        autocomplete="new-password"
                        wire:model="confirmPin"
                        placeholder="········"
                        class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-base text-slate-900
                               focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                               dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus-visible:ring-slate-100"
                    />
                    @error('confirmPin')
                        <p class="text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
                    @enderror
                </div>

                <div class="space-y-1">
                    <label for="account-password-input" class="block text-sm text-slate-700 dark:text-slate-300">
                        {{ Lang::get('auth::app_lock.account_password_label') }}
                        <span class="ml-1 text-xs text-slate-400">{{ Lang::get('auth::app_lock.account_password_note') }}</span>
                    </label>
                    <input
                        id="account-password-input"
                        type="password"
                        autocomplete="current-password"
                        wire:model="accountPassword"
                        placeholder="{{ Lang::get('auth::app_lock.account_password_placeholder') }}"
                        class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-base text-slate-900
                               focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                               dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus-visible:ring-slate-100"
                    />
                    @error('accountPassword')
                        <p class="text-sm text-rose-600 dark:text-rose-400">{{ $message }}</p>
                    @enderror
                </div>

                <button
                    type="button"
                    wire:click="setPin"
                    class="w-full min-h-[44px] rounded-md bg-slate-900 px-4 py-2.5 text-sm font-semibold text-white
                           hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                           dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200 dark:focus-visible:ring-slate-100"
                >
                    {{ Lang::get('auth::app_lock.set_pin') }}
                </button>
            </div>
        </div>
    @endif

    {{-- ===== Lock-enabled sub-sections (3b change, 3c biometric, 3d idle) ===== --}}
    @if ($lockEnabled)

        {{-- 3b: Change PIN button --}}
        <div class="flex items-center justify-between gap-4 py-1">
            <div>
                <p class="text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('auth::app_lock.pin_row_label') }}</p>
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('auth::app_lock.pin_row_description') }}</p>
            </div>
            <button
                type="button"
                wire:click="confirmChangePin"
                class="min-h-[44px] rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-900
                       hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                       dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700 dark:focus-visible:ring-slate-100"
            >
                {{ Lang::get('auth::app_lock.change_pin') }}
            </button>
        </div>

        {{-- Phase 14 D-10: re-secured-encryption note appended to the Change-PIN
             success flash — shown only when an encrypted keyring exists for
             this user (changePin() gates the message itself). --}}
        @if ($changePinSuccessMessage !== '')
            <p class="text-xs text-slate-500 dark:text-slate-400" aria-live="polite" aria-atomic="true" data-testid="change-pin-success">
                {{ $changePinSuccessMessage }}
            </p>
        @endif

        {{-- 3b': Forgot PIN recovery (D-11/D-21) — account password sets a new PIN --}}
        <div class="py-1">
            <button
                type="button"
                wire:click="confirmForgotPin"
                class="text-sm text-slate-500 underline-offset-2 hover:underline
                       focus:outline-none focus-visible:underline
                       dark:text-slate-400"
            >
                {{ Lang::get('auth::app_lock.forgot_pin_link') }}
            </button>
        </div>

        {{-- 3c: Biometric enrollment row (05-05)

             Detects browser WebAuthn capability and tells the server (D-13).

             The comment lives HERE, not inside x-init: Alpine compiles an
             attribute as an expression, so a leading `//` pushes the real code
             onto the next line and `if` — a statement — raises "Unexpected
             token 'if'". That threw on every render of this row, and the
             capability was never reported, so the biometric option stayed
             hidden on hardware that supports it. --}}
        <div
            class="py-1"
            x-data="{}"
            x-init="if (window.PublicKeyCredential) { $wire.set('biometricCapable', true) }"
        >
            @if ($biometricCapable || $biometricEnrolled)
                {{-- Capable platform: show enroll/de-enroll controls --}}
                <x-core::setting-row :label="$biometricLabel">
                    <x-slot:description>
                        @if ($biometricEnrolled)
                            {{ Lang::get('auth::app_lock.biometric_enrolled_description') }}
                        @else
                            {{ Lang::get('auth::app_lock.biometric_enroll_description') }}
                        @endif
                    </x-slot:description>
                    @if ($biometricEnrolled)
                        <button
                            type="button"
                            wire:click="confirmDeenroll"
                            class="min-h-[44px] rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-rose-600
                                   hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2
                                   dark:border-slate-600 dark:bg-slate-800 dark:text-rose-400 dark:hover:bg-slate-700 dark:focus-visible:ring-rose-400"
                        >
                            {{ Lang::get('auth::app_lock.remove') }}
                        </button>
                    @else
                        <button
                            type="button"
                            wire:click="startEnroll"
                            class="min-h-[44px] rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-900
                                   hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                                   dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700 dark:focus-visible:ring-slate-100"
                        >
                            {{ Lang::get('auth::app_lock.enroll') }}
                        </button>
                    @endif
                </x-core::setting-row>
            @else
                {{-- Empty state: platform does not support WebAuthn --}}
                <p class="text-sm text-slate-400 dark:text-slate-500">
                    {{ Lang::get('auth::app_lock.biometric_unavailable') }}
                </p>
            @endif
        </div>

        {{-- De-enroll confirmation modal (D-23) --}}
        @if ($confirmingDeenroll)
            <flux:modal wire:model="confirmingDeenroll" class="md:max-w-sm">
                <div class="space-y-4 p-6">
                    <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">
                        {{ Lang::get('auth::app_lock.deenroll_modal_heading') }}
                    </h3>
                    <div class="space-y-1">
                        <label for="deenroll-pin-input" class="block text-sm text-slate-700 dark:text-slate-300">{{ Lang::get('auth::app_lock.current_pin_label') }}</label>
                        <input
                            id="deenroll-pin-input"
                            type="password"
                            inputmode="numeric"
                            autocomplete="off"
                            wire:model="deenrollPin"
                            placeholder="········"
                            class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-base text-slate-900
                                   focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                                   dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus-visible:ring-slate-100"
                        />
                    </div>
                    <div class="flex gap-3">
                        <button
                            type="button"
                            wire:click="deenroll"
                            class="flex-1 min-h-[44px] rounded-md bg-rose-600 px-4 py-2 text-sm font-semibold text-white
                                   hover:bg-rose-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2"
                        >
                            {{ Lang::get('auth::app_lock.remove_biometric') }}
                        </button>
                        <button
                            type="button"
                            wire:click="$set('confirmingDeenroll', false)"
                            class="flex-1 min-h-[44px] rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-900
                                   hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                                   dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700 dark:focus-visible:ring-slate-100"
                        >
                            {{ Lang::get('auth::app_lock.keep_biometric') }}
                        </button>
                    </div>
                </div>
            </flux:modal>
        @endif

    @endif

    {{-- ===== 3d: Idle timeout — shown always ===== --}}
    <div class="flex items-center justify-between gap-4">
        <label for="idle-timeout-select" class="text-sm text-slate-900 dark:text-slate-100">
            {{ Lang::get('auth::app_lock.auto_lock') }}
        </label>
        <select
            id="idle-timeout-select"
            wire:model="idleTimeoutMinutes"
            wire:change="setIdleTimeout"
            class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900
                   focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                   dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:focus-visible:ring-slate-100"
        >
            <option value="1">{{ Lang::get('auth::app_lock.idle_1') }}</option>
            <option value="5">{{ Lang::get('auth::app_lock.idle_5') }}</option>
            <option value="15">{{ Lang::get('auth::app_lock.idle_15') }}</option>
            <option value="30">{{ Lang::get('auth::app_lock.idle_30') }}</option>
        </select>
    </div>

    {{-- ===== 3e: Disable lock modal (D-23 confirmation) ===== --}}
    @if ($confirmingDisable)
        <flux:modal wire:model="confirmingDisable" class="md:max-w-sm">
            <div class="space-y-4 p-6">
                <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">
                    {{ Lang::get('auth::app_lock.disable_modal_heading') }}
                </h3>
                <div class="space-y-1">
                    <label for="disable-pin-input" class="block text-sm text-slate-700 dark:text-slate-300">{{ Lang::get('auth::app_lock.current_pin_label') }}</label>
                    <input
                        id="disable-pin-input"
                        type="password"
                        inputmode="numeric"
                        autocomplete="off"
                        wire:model="currentPin"
                        placeholder="········"
                        class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-base text-slate-900
                               focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                               dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus-visible:ring-slate-100"
                    />
                </div>
                <div class="flex gap-3">
                    <button
                        type="button"
                        wire:click="disable"
                        class="flex-1 min-h-[44px] rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white
                               hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                               dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200 dark:focus-visible:ring-slate-100"
                    >
                        {{ Lang::get('auth::app_lock.disable_lock') }}
                    </button>
                    <button
                        type="button"
                        wire:click="$set('confirmingDisable', false)"
                        class="flex-1 min-h-[44px] rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-900
                               hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                               dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700 dark:focus-visible:ring-slate-100"
                    >
                        {{ Lang::get('auth::app_lock.keep_lock') }}
                    </button>
                </div>
            </div>
        </flux:modal>
    @endif

    {{-- ===== 3e': Forgot PIN recovery modal (D-11/D-21) ===== --}}
    @if ($confirmingForgotPin)
        <flux:modal wire:model="confirmingForgotPin" class="md:max-w-sm">
            <div class="space-y-4 p-6">
                <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">
                    {{ Lang::get('auth::app_lock.forgot_modal_heading') }}
                </h3>
                <p class="text-xs text-slate-500 dark:text-slate-400">
                    {{ Lang::get('auth::app_lock.forgot_modal_body') }}
                </p>
                <div class="space-y-3">
                    <div class="space-y-1">
                        <label for="forgot-account-password-input" class="block text-sm text-slate-700 dark:text-slate-300">{{ Lang::get('auth::app_lock.account_password_label') }}</label>
                        <input
                            id="forgot-account-password-input"
                            type="password"
                            autocomplete="current-password"
                            wire:model="accountPassword"
                            placeholder="{{ Lang::get('auth::app_lock.account_password_placeholder') }}"
                            class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-base text-slate-900
                                   focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                                   dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus-visible:ring-slate-100"
                        />
                    </div>
                    <div class="space-y-1">
                        <label for="forgot-new-pin-input" class="block text-sm text-slate-700 dark:text-slate-300">{{ Lang::get('auth::app_lock.new_pin_label') }}</label>
                        <input
                            id="forgot-new-pin-input"
                            type="password"
                            inputmode="numeric"
                            autocomplete="new-password"
                            wire:model="newPin"
                            placeholder="········"
                            class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-base text-slate-900
                                   focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                                   dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus-visible:ring-slate-100"
                        />
                    </div>
                    <div class="space-y-1">
                        <label for="forgot-confirm-pin-input" class="block text-sm text-slate-700 dark:text-slate-300">{{ Lang::get('auth::app_lock.confirm_new_pin_label') }}</label>
                        <input
                            id="forgot-confirm-pin-input"
                            type="password"
                            inputmode="numeric"
                            autocomplete="new-password"
                            wire:model="confirmPin"
                            placeholder="········"
                            class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-base text-slate-900
                                   focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                                   dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus-visible:ring-slate-100"
                        />
                    </div>
                </div>
                <div class="flex gap-3">
                    <button
                        type="button"
                        wire:click="resetForgottenPin"
                        class="flex-1 min-h-[44px] rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white
                               hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                               dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200 dark:focus-visible:ring-slate-100"
                    >
                        {{ Lang::get('auth::app_lock.reset_pin') }}
                    </button>
                    <button
                        type="button"
                        wire:click="$set('confirmingForgotPin', false)"
                        class="flex-1 min-h-[44px] rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-900
                               hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                               dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700 dark:focus-visible:ring-slate-100"
                    >
                        {{ Lang::get('auth::app_lock.cancel') }}
                    </button>
                </div>
            </div>
        </flux:modal>
    @endif

    {{-- ===== 3e: Change PIN modal (D-23 confirmation) ===== --}}
    @if ($confirmingChangePin)
        <flux:modal wire:model="confirmingChangePin" class="md:max-w-sm">
            <div class="space-y-4 p-6">
                <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">
                    {{ Lang::get('auth::app_lock.change_modal_heading') }}
                </h3>
                <div class="space-y-3">
                    <div class="space-y-1">
                        <label for="change-current-pin-input" class="block text-sm text-slate-700 dark:text-slate-300">{{ Lang::get('auth::app_lock.current_pin_label') }}</label>
                        <input
                            id="change-current-pin-input"
                            type="password"
                            inputmode="numeric"
                            autocomplete="off"
                            wire:model="currentPin"
                            placeholder="········"
                            class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-base text-slate-900
                                   focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                                   dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus-visible:ring-slate-100"
                        />
                    </div>
                    <div class="space-y-1">
                        <label for="change-new-pin-input" class="block text-sm text-slate-700 dark:text-slate-300">{{ Lang::get('auth::app_lock.new_pin_label') }}</label>
                        <input
                            id="change-new-pin-input"
                            type="password"
                            inputmode="numeric"
                            autocomplete="new-password"
                            wire:model="newPin"
                            placeholder="········"
                            class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-base text-slate-900
                                   focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                                   dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus-visible:ring-slate-100"
                        />
                    </div>
                    <div class="space-y-1">
                        <label for="change-confirm-pin-input" class="block text-sm text-slate-700 dark:text-slate-300">{{ Lang::get('auth::app_lock.confirm_new_pin_label') }}</label>
                        <input
                            id="change-confirm-pin-input"
                            type="password"
                            inputmode="numeric"
                            autocomplete="new-password"
                            wire:model="confirmPin"
                            placeholder="········"
                            class="block w-full rounded-md border border-slate-200 bg-white px-3 py-2 text-base text-slate-900
                                   focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                                   dark:border-slate-700 dark:bg-slate-800 dark:text-slate-100 dark:focus-visible:ring-slate-100"
                        />
                    </div>
                </div>
                <div class="flex gap-3">
                    <button
                        type="button"
                        wire:click="changePin"
                        class="flex-1 min-h-[44px] rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white
                               hover:bg-slate-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                               dark:bg-slate-100 dark:text-slate-900 dark:hover:bg-slate-200 dark:focus-visible:ring-slate-100"
                    >
                        {{ Lang::get('auth::app_lock.change_pin') }}
                    </button>
                    <button
                        type="button"
                        wire:click="$set('confirmingChangePin', false)"
                        class="flex-1 min-h-[44px] rounded-md border border-slate-300 bg-white px-4 py-2 text-sm font-medium text-slate-900
                               hover:bg-slate-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2
                               dark:border-slate-600 dark:bg-slate-800 dark:text-slate-100 dark:hover:bg-slate-700 dark:focus-visible:ring-slate-100"
                    >
                        {{ Lang::get('auth::app_lock.keep_pin') }}
                    </button>
                </div>
            </div>
        </flux:modal>
    @endif
</div>
