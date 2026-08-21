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
        {{-- Empty wire:click when off on purpose: enabling needs the PIN fields
             and the account password, so the setup panel below does it. --}}
        <x-core::switch
            :on="$lockEnabled"
            :label="Lang::get('auth::app_lock.toggle_label')"
            wire:click="{{ $lockEnabled ? 'confirmDisable' : '' }}"
        />
    </x-core::setting-row>

    {{-- ===== 3b: PIN setup (shown when lock is not yet enabled) ===== --}}
    @if (! $lockEnabled)
        <div class="space-y-4 rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-900">
            <h3 class="text-sm font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('auth::app_lock.setup_heading') }}</h3>

            <div class="space-y-3">
                <x-core::form-field
                    :label="Lang::get('auth::app_lock.new_pin_label')"
                    name="newPin"
                    field-id="new-pin-input"
                    type="password"
                    size="base"
                    inputmode="numeric"
                    autocomplete="new-password"
                    wire:model="newPin"
                    placeholder="········"
                />

                <x-core::form-field
                    :label="Lang::get('auth::app_lock.confirm_pin_label')"
                    name="confirmPin"
                    field-id="confirm-pin-input"
                    type="password"
                    size="base"
                    inputmode="numeric"
                    autocomplete="new-password"
                    wire:model="confirmPin"
                    placeholder="········"
                />

                {{-- The "we never store it" note is set inside the label, not
                     under the field, so it is read out as part of the label
                     rather than as a separate hint. That needs markup, so the
                     label goes as a slot. --}}
                <x-core::form-field
                    name="accountPassword"
                    field-id="account-password-input"
                    type="password"
                    size="base"
                    autocomplete="current-password"
                    wire:model="accountPassword"
                    placeholder="{{ Lang::get('auth::app_lock.account_password_placeholder') }}"
                >
                    <x-slot:label>
                        {{ Lang::get('auth::app_lock.account_password_label') }}
                        <span class="ml-1 text-xs text-slate-400">{{ Lang::get('auth::app_lock.account_password_note') }}</span>
                    </x-slot:label>
                </x-core::form-field>

                <x-core::neutral-button
                    block="full"
                    class="min-h-[44px]"
                    wire:click="setPin"
                >
                    {{ Lang::get('auth::app_lock.set_pin') }}
                </x-core::neutral-button>
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
            <x-core::secondary-button
                size="sm"
                class="min-h-[44px]"
                wire:click="confirmChangePin"
            >
                {{ Lang::get('auth::app_lock.change_pin') }}
            </x-core::secondary-button>
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
                        <x-core::secondary-button
                            size="sm"
                            class="min-h-[44px]"
                            wire:click="startEnroll"
                        >
                            {{ Lang::get('auth::app_lock.enroll') }}
                        </x-core::secondary-button>
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
                    <x-core::form-field
                        :label="Lang::get('auth::app_lock.current_pin_label')"
                        name="deenrollPin"
                        field-id="deenroll-pin-input"
                        type="password"
                        size="base"
                        inputmode="numeric"
                        autocomplete="off"
                        wire:model="deenrollPin"
                        placeholder="········"
                    />
                    <div class="flex gap-3">
                        <button
                            type="button"
                            wire:click="deenroll"
                            class="flex-1 min-h-[44px] rounded-md bg-rose-600 px-4 py-2 text-sm font-semibold text-white
                                   hover:bg-rose-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-rose-600 focus-visible:ring-offset-2"
                        >
                            {{ Lang::get('auth::app_lock.remove_biometric') }}
                        </button>
                        <x-core::secondary-button
                            block="flex"
                            class="min-h-[44px]"
                            wire:click="$set('confirmingDeenroll', false)"
                        >
                            {{ Lang::get('auth::app_lock.keep_biometric') }}
                        </x-core::secondary-button>
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
                <x-core::form-field
                    :label="Lang::get('auth::app_lock.current_pin_label')"
                    name="currentPin"
                    field-id="disable-pin-input"
                    type="password"
                    size="base"
                    inputmode="numeric"
                    autocomplete="off"
                    wire:model="currentPin"
                    placeholder="········"
                />
                <div class="flex gap-3">
                    <x-core::neutral-button
                        block="flex"
                        class="min-h-[44px]"
                        wire:click="disable"
                    >
                        {{ Lang::get('auth::app_lock.disable_lock') }}
                    </x-core::neutral-button>
                    <x-core::secondary-button
                        block="flex"
                        class="min-h-[44px]"
                        wire:click="$set('confirmingDisable', false)"
                    >
                        {{ Lang::get('auth::app_lock.keep_lock') }}
                    </x-core::secondary-button>
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
                    <x-core::form-field
                        :label="Lang::get('auth::app_lock.account_password_label')"
                        name="accountPassword"
                        field-id="forgot-account-password-input"
                        type="password"
                        size="base"
                        autocomplete="current-password"
                        wire:model="accountPassword"
                        placeholder="{{ Lang::get('auth::app_lock.account_password_placeholder') }}"
                    />
                    <x-core::form-field
                        :label="Lang::get('auth::app_lock.new_pin_label')"
                        name="newPin"
                        field-id="forgot-new-pin-input"
                        type="password"
                        size="base"
                        inputmode="numeric"
                        autocomplete="new-password"
                        wire:model="newPin"
                        placeholder="········"
                    />
                    <x-core::form-field
                        :label="Lang::get('auth::app_lock.confirm_new_pin_label')"
                        name="confirmPin"
                        field-id="forgot-confirm-pin-input"
                        type="password"
                        size="base"
                        inputmode="numeric"
                        autocomplete="new-password"
                        wire:model="confirmPin"
                        placeholder="········"
                    />
                </div>
                <div class="flex gap-3">
                    <x-core::neutral-button
                        block="flex"
                        class="min-h-[44px]"
                        wire:click="resetForgottenPin"
                    >
                        {{ Lang::get('auth::app_lock.reset_pin') }}
                    </x-core::neutral-button>
                    <x-core::secondary-button
                        block="flex"
                        class="min-h-[44px]"
                        wire:click="$set('confirmingForgotPin', false)"
                    >
                        {{ Lang::get('auth::app_lock.cancel') }}
                    </x-core::secondary-button>
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
                    <x-core::form-field
                        :label="Lang::get('auth::app_lock.current_pin_label')"
                        name="currentPin"
                        field-id="change-current-pin-input"
                        type="password"
                        size="base"
                        inputmode="numeric"
                        autocomplete="off"
                        wire:model="currentPin"
                        placeholder="········"
                    />
                    <x-core::form-field
                        :label="Lang::get('auth::app_lock.new_pin_label')"
                        name="newPin"
                        field-id="change-new-pin-input"
                        type="password"
                        size="base"
                        inputmode="numeric"
                        autocomplete="new-password"
                        wire:model="newPin"
                        placeholder="········"
                    />
                    <x-core::form-field
                        :label="Lang::get('auth::app_lock.confirm_new_pin_label')"
                        name="confirmPin"
                        field-id="change-confirm-pin-input"
                        type="password"
                        size="base"
                        inputmode="numeric"
                        autocomplete="new-password"
                        wire:model="confirmPin"
                        placeholder="········"
                    />
                </div>
                <div class="flex gap-3">
                    <x-core::neutral-button
                        block="flex"
                        class="min-h-[44px]"
                        wire:click="changePin"
                    >
                        {{ Lang::get('auth::app_lock.change_pin') }}
                    </x-core::neutral-button>
                    <x-core::secondary-button
                        block="flex"
                        class="min-h-[44px]"
                        wire:click="$set('confirmingChangePin', false)"
                    >
                        {{ Lang::get('auth::app_lock.keep_pin') }}
                    </x-core::secondary-button>
                </div>
            </div>
        </flux:modal>
    @endif
</div>
