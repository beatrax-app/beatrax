@use('Modules\Core\Public\Support\Lang')
{{--
    Settings "Notifications" section. Two internal groups —
    "What to notify me about" (4 toggles + lead time + cadence) then
    "When and how" (quiet hours + hide details) — plus the read-only
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
            <x-core::setting-row :label="Lang::get('notifications::settings.reminders.label')" :description="Lang::get('notifications::settings.reminders.help')">
                <x-core::switch
                    :on="$remindersEnabled"
                    :label="Lang::get('notifications::settings.reminders.label')"
                    wire:click="$toggle('remindersEnabled')"
                />
            </x-core::setting-row>

            @if ($remindersEnabled)
                {{-- wire:transition belonged to the wrapper this field used
                     to hand-roll, so it stays on a wrapper: putting it on the
                     component would forward it to the input element and animate
                     the control instead of the row appearing. --}}
                <div wire:transition>
                    <x-core::form-field
                        name="reminderLeadDays"
                        type="number"
                        min="1"
                        max="30"
                        :label="Lang::get('notifications::settings.lead_days.label')"
                        :hint="Lang::get('notifications::settings.lead_days.help')"
                        wire:model="reminderLeadDays"
                        style="font-variant-numeric: tabular-nums;"
                    />
                </div>
            @endif

            {{-- Budget nudges --}}
            <x-core::setting-row :label="Lang::get('notifications::settings.budget_nudges.label')" :description="Lang::get('notifications::settings.budget_nudges.help')">
                <x-core::switch
                    :on="$budgetNudgesEnabled"
                    :label="Lang::get('notifications::settings.budget_nudges.label')"
                    wire:click="$toggle('budgetNudgesEnabled')"
                />
            </x-core::setting-row>

            {{-- Weekly position digest cadence --}}
            <x-core::form-field
                type="select"
                name="digestCadence"
                :label="Lang::get('notifications::settings.digest.label')"
                :hint="Lang::get('notifications::settings.digest.help')"
                wire:model="digestCadence"
            >
                <option value="daily">{{ Lang::get('notifications::settings.digest.daily') }}</option>
                <option value="weekly">{{ Lang::get('notifications::settings.digest.weekly') }}</option>
                <option value="off">{{ Lang::get('notifications::settings.digest.off') }}</option>
            </x-core::form-field>

            {{-- Savings-opportunity prompts --}}
            <x-core::setting-row :label="Lang::get('notifications::settings.savings.label')" :description="Lang::get('notifications::settings.savings.help')">
                <x-core::switch
                    :on="$savingsPromptsEnabled"
                    :label="Lang::get('notifications::settings.savings.label')"
                    wire:click="$toggle('savingsPromptsEnabled')"
                />
            </x-core::setting-row>
        </div>

        {{-- ===== When and how ===== --}}
        <div class="space-y-4 border-t border-slate-100 pt-6 dark:border-slate-800">
            <h3 class="text-base font-semibold text-slate-900 dark:text-slate-100">{{ Lang::get('notifications::settings.when_heading') }}</h3>

            {{-- Quiet hours --}}
            <x-core::setting-row :label="Lang::get('notifications::settings.quiet_hours.label')" :description="Lang::get('notifications::settings.quiet_hours.help')">
                <x-core::switch
                    :on="$quietHoursEnabled"
                    :label="Lang::get('notifications::settings.quiet_hours.label')"
                    wire:click="$toggle('quietHoursEnabled')"
                />
            </x-core::setting-row>

            @if ($quietHoursEnabled)
                <div class="grid grid-cols-2 gap-4" wire:transition>
                    <div class="space-y-1">
                        <label for="quietHoursFrom" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('notifications::settings.quiet_hours.from') }}</label>
                        <x-core::time-input
                            field-id="quietHoursFrom"
                            wire:model="quietHoursFrom"
                            :aria-label="Lang::get('notifications::settings.quiet_hours.from')"
                        />
                    </div>
                    <div class="space-y-1">
                        <label for="quietHoursTo" class="block text-sm text-slate-900 dark:text-slate-100">{{ Lang::get('notifications::settings.quiet_hours.to') }}</label>
                        <x-core::time-input
                            field-id="quietHoursTo"
                            wire:model="quietHoursTo"
                            :aria-label="Lang::get('notifications::settings.quiet_hours.to')"
                        />
                    </div>
                </div>
            @endif

            {{-- Hide details in notifications (per-device) --}}
            <x-core::setting-row :label="Lang::get('notifications::settings.hide_details.label')" :description="Lang::get('notifications::settings.hide_details.help')">
                <x-core::switch
                    :on="$hideDetails"
                    :label="Lang::get('notifications::settings.hide_details.label')"
                    wire:click="$toggle('hideDetails')"
                />
            </x-core::setting-row>
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

    {{-- Other devices — visible, not editable. --}}
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
