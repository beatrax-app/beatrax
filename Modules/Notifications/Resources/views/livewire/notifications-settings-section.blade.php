@use('Modules\Core\Public\Support\Lang')
{{--
    Settings "Notifications" section (D-36). Two internal groups —
    "What to notify me about" (4 toggles + lead time + cadence) then
    "When and how" (quiet hours + hide details) — plus the D-35 read-only
    "Other devices" panel.

    Variables in scope:
      - $remindersEnabled : bool
      - $reminderLeadDays : int
      - $budgetNudgesEnabled : bool
      - $digestCadence : string (daily|weekly|off)
      - $savingsPromptsEnabled : bool
      - $quietHoursEnabled : bool
      - $quietHoursFrom : string (HH:MM)
      - $quietHoursTo : string (HH:MM)
      - $hideDetails : bool
      - $saveError : string
      - $saved : bool
      - $otherDevices : list<array{name: string, summary: string}>

    Blade default `{{ }}` escaping for every interpolation.
--}}

<div class="space-y-6" data-testid="notifications-settings-section">
    <form wire:submit="save" class="space-y-6">
        {{-- ===== What to notify me about ===== --}}
        <div class="space-y-4">
            <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('notifications::settings.what_heading') }}</h3>

            {{-- Payment reminders --}}
            <div class="flex items-start justify-between gap-3">
                <div class="flex-1">
                    <p class="text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('notifications::settings.reminders.label') }}</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('notifications::settings.reminders.help') }}</p>
                </div>
                <button type="button"
                        class="switch{{ $remindersEnabled ? ' switch--on' : '' }}"
                        wire:click="$toggle('remindersEnabled')"
                        aria-pressed="{{ $remindersEnabled ? 'true' : 'false' }}"
                        aria-label="{{ Lang::get('notifications::settings.reminders.label') }}">
                    <span class="switch__thumb"></span>
                </button>
            </div>

            @if ($remindersEnabled)
                <div class="space-y-1" wire:transition>
                    <label for="reminderLeadDays" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('notifications::settings.lead_days.label') }}</label>
                    <input
                        type="number"
                        min="1"
                        max="30"
                        id="reminderLeadDays"
                        name="reminderLeadDays"
                        wire:model="reminderLeadDays"
                        style="font-variant-numeric: tabular-nums;"
                        class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:focus-visible:ring-slate-100"
                    />
                    <p class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('notifications::settings.lead_days.help') }}</p>
                </div>
            @endif

            {{-- Budget nudges --}}
            <div class="flex items-start justify-between gap-3">
                <div class="flex-1">
                    <p class="text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('notifications::settings.budget_nudges.label') }}</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('notifications::settings.budget_nudges.help') }}</p>
                </div>
                <button type="button"
                        class="switch{{ $budgetNudgesEnabled ? ' switch--on' : '' }}"
                        wire:click="$toggle('budgetNudgesEnabled')"
                        aria-pressed="{{ $budgetNudgesEnabled ? 'true' : 'false' }}"
                        aria-label="{{ Lang::get('notifications::settings.budget_nudges.label') }}">
                    <span class="switch__thumb"></span>
                </button>
            </div>

            {{-- Weekly position digest cadence --}}
            <div class="space-y-1">
                <label for="digestCadence" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('notifications::settings.digest.label') }}</label>
                <select
                    id="digestCadence"
                    name="digestCadence"
                    wire:model="digestCadence"
                    class="block w-full rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-slate-900 focus-visible:ring-offset-2 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100 dark:focus-visible:ring-slate-100"
                >
                    <option value="daily">{{ Lang::get('notifications::settings.digest.daily') }}</option>
                    <option value="weekly">{{ Lang::get('notifications::settings.digest.weekly') }}</option>
                    <option value="off">{{ Lang::get('notifications::settings.digest.off') }}</option>
                </select>
                <p class="text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('notifications::settings.digest.help') }}</p>
            </div>

            {{-- Savings-opportunity prompts --}}
            <div class="flex items-start justify-between gap-3">
                <div class="flex-1">
                    <p class="text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('notifications::settings.savings.label') }}</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('notifications::settings.savings.help') }}</p>
                </div>
                <button type="button"
                        class="switch{{ $savingsPromptsEnabled ? ' switch--on' : '' }}"
                        wire:click="$toggle('savingsPromptsEnabled')"
                        aria-pressed="{{ $savingsPromptsEnabled ? 'true' : 'false' }}"
                        aria-label="{{ Lang::get('notifications::settings.savings.label') }}">
                    <span class="switch__thumb"></span>
                </button>
            </div>
        </div>

        {{-- ===== When and how ===== --}}
        <div class="space-y-4 border-t border-slate-100 pt-6 dark:border-slate-800">
            <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('notifications::settings.when_heading') }}</h3>

            {{-- Quiet hours --}}
            <div class="flex items-start justify-between gap-3">
                <div class="flex-1">
                    <p class="text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('notifications::settings.quiet_hours.label') }}</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('notifications::settings.quiet_hours.help') }}</p>
                </div>
                <button type="button"
                        class="switch{{ $quietHoursEnabled ? ' switch--on' : '' }}"
                        wire:click="$toggle('quietHoursEnabled')"
                        aria-pressed="{{ $quietHoursEnabled ? 'true' : 'false' }}"
                        aria-label="{{ Lang::get('notifications::settings.quiet_hours.label') }}">
                    <span class="switch__thumb"></span>
                </button>
            </div>

            @if ($quietHoursEnabled)
                <div class="grid grid-cols-2 gap-4" wire:transition>
                    <div class="space-y-1">
                        <label for="quietHoursFrom" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('notifications::settings.quiet_hours.from') }}</label>
                        <x-core::time-input
                            field-id="quietHoursFrom"
                            wire:model="quietHoursFrom"
                        />
                    </div>
                    <div class="space-y-1">
                        <label for="quietHoursTo" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('notifications::settings.quiet_hours.to') }}</label>
                        <x-core::time-input
                            field-id="quietHoursTo"
                            wire:model="quietHoursTo"
                        />
                    </div>
                </div>
            @endif

            {{-- Hide details in notifications (per-device) --}}
            <div class="flex items-start justify-between gap-3">
                <div class="flex-1">
                    <p class="text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('notifications::settings.hide_details.label') }}</p>
                    <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">{{ Lang::get('notifications::settings.hide_details.help') }}</p>
                </div>
                <button type="button"
                        class="switch{{ $hideDetails ? ' switch--on' : '' }}"
                        wire:click="$toggle('hideDetails')"
                        aria-pressed="{{ $hideDetails ? 'true' : 'false' }}"
                        aria-label="{{ Lang::get('notifications::settings.hide_details.label') }}">
                    <span class="switch__thumb"></span>
                </button>
            </div>
        </div>

        @if ($saveError !== '')
            <p class="text-sm text-rose-600 dark:text-rose-500" data-testid="notifications-save-error">{{ $saveError }}</p>
        @endif

        <button
            type="submit"
            class="inline-flex items-center rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2 dark:bg-emerald-500 dark:hover:bg-emerald-400"
        >{{ Lang::get('notifications::settings.save') }}</button>

        @if ($saved)
            <p wire:transition.duration.4000ms class="text-sm text-emerald-700 dark:text-emerald-400" data-testid="notifications-saved">{{ Lang::get('notifications::settings.saved') }}</p>
        @endif
    </form>

    {{-- Other devices (D-35) — visible, not editable. --}}
    <details class="mt-2" data-testid="other-device-preferences">
        <summary class="cursor-pointer text-sm font-medium text-slate-900 dark:text-slate-100">{{ Lang::get('notifications::settings.other_devices.summary') }}</summary>
        @if (count($otherDevices) === 0)
            <p class="mt-2 text-xs text-slate-500 dark:text-slate-400" data-testid="other-devices-empty">
                {{ Lang::get('notifications::settings.other_devices.empty') }}
            </p>
        @else
            <ul class="mt-2 divide-y divide-slate-100 dark:divide-slate-800" role="list">
                @foreach ($otherDevices as $device)
                    <li class="py-2 text-sm text-slate-700 dark:text-slate-300">
                        <span class="font-medium">{{ $device['name'] }}</span>
                        <span class="text-slate-500 dark:text-slate-400"> — {{ $device['summary'] }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </details>
</div>
