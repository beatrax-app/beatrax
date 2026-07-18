<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Notifications\Public\Dto\NotificationPreferencesDto;
use Modules\Notifications\Public\Services\NotificationPreferenceQuery;

/**
 * Settings "Notifications" section (D-36) — the ~9-control preferences form
 * plus the D-35 read-only "Other devices" panel.
 *
 * Follows the `AnomalySettingsSection` FORM shape (`mount()`/`save()`/inline
 * `$saveError`/`$saved`, bounds-checking before any write) but NOT its
 * storage: this section reads and writes a `(user_id, device_id)` row in
 * the synced `notification_preferences` table via
 * `NotificationPreferenceQuery`, never `users` columns (D-34).
 *
 * NO constructor DI — collaborators arrive as parameters on `mount()`,
 * `save()` and `render()`.
 */
final class NotificationsSettingsSection extends Component
{
    /** Payment reminders toggle. Default ON (D-16). */
    public bool $remindersEnabled = true;

    /** Reminder lead time in days (1..30). Default 3 (D-15). */
    public int $reminderLeadDays = 3;

    /** Budget nudges toggle. Default ON (D-16). */
    public bool $budgetNudgesEnabled = true;

    /** Weekly position digest cadence: daily|weekly|off. Default weekly (D-16). */
    public string $digestCadence = 'weekly';

    /** Savings-opportunity prompts toggle. Default OFF (D-16). */
    public bool $savingsPromptsEnabled = false;

    /** Quiet hours toggle. Default OFF (D-19). */
    public bool $quietHoursEnabled = false;

    /** Quiet hours start, HH:MM. Default 22:00 (D-19). */
    public string $quietHoursFrom = '22:00';

    /** Quiet hours end, HH:MM. Default 08:00 (D-19). */
    public string $quietHoursTo = '08:00';

    /** Hide details in notifications toggle, per-device. Default OFF (D-24). */
    public bool $hideDetails = false;

    /** Inline validation error for the save form. */
    public string $saveError = '';

    /** Success flash after a save. */
    public bool $saved = false;

    public function mount(CurrentUser $currentUser, NotificationPreferenceQuery $prefs): void
    {
        $dto = $prefs->forCurrentDevice($currentUser->user());

        $this->remindersEnabled = $dto->remindersEnabled;
        $this->reminderLeadDays = $dto->reminderLeadDays;
        $this->budgetNudgesEnabled = $dto->budgetNudgesEnabled;
        $this->digestCadence = $dto->digestCadence;
        $this->savingsPromptsEnabled = $dto->savingsPromptsEnabled;
        $this->quietHoursEnabled = $dto->quietHoursEnabled;
        $this->quietHoursFrom = $dto->quietHoursFrom ?? '22:00';
        $this->quietHoursTo = $dto->quietHoursTo ?? '08:00';
        $this->hideDetails = $dto->hideDetails;
    }

    /**
     * Bounds-checks EVERY input before any write (T-18-53), mirroring
     * `AnomalySettingsSection::save()`'s documented discipline that "a
     * tampered Livewire payload cannot persist an out-of-range value".
     * `NotificationPreferenceQuery::saveForCurrentDevice()` (18-03)
     * re-validates and throws as defence in depth, but this method never
     * relies on that — it rejects first.
     */
    public function save(CurrentUser $currentUser, NotificationPreferenceQuery $prefs): void
    {
        $this->saveError = '';
        $this->saved = false;

        $allowedCadences = ['daily', 'weekly', 'off'];
        if (! in_array($this->digestCadence, $allowedCadences, strict: true)) {
            $this->saveError = "Couldn't save your notification settings. Try again.";

            return;
        }

        if ($this->reminderLeadDays < 1 || $this->reminderLeadDays > 30) {
            $this->saveError = "Couldn't save your notification settings. Try again.";

            return;
        }

        foreach ([$this->quietHoursFrom, $this->quietHoursTo] as $time) {
            if (preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
                $this->saveError = "Couldn't save your notification settings. Try again.";

                return;
            }
        }

        $prefs->saveForCurrentDevice($currentUser->user(), new NotificationPreferencesDto(
            remindersEnabled: $this->remindersEnabled,
            budgetNudgesEnabled: $this->budgetNudgesEnabled,
            digestCadence: $this->digestCadence,
            savingsPromptsEnabled: $this->savingsPromptsEnabled,
            reminderLeadDays: $this->reminderLeadDays,
            quietHoursEnabled: $this->quietHoursEnabled,
            quietHoursFrom: $this->quietHoursFrom,
            quietHoursTo: $this->quietHoursTo,
            hideDetails: $this->hideDetails,
        ));

        $this->saved = true;
    }

    public function render(CurrentUser $currentUser, NotificationPreferenceQuery $prefs, ViewFactory $views): View
    {
        $otherDevices = array_map(
            static fn (NotificationPreferencesDto $dto): array => [
                'name' => $dto->deviceName !== '' ? $dto->deviceName : 'Unnamed device',
                'summary' => self::summarize($dto),
            ],
            $prefs->forOtherDevices($currentUser->user()),
        );

        return $views->make('notifications::livewire.notifications-settings-section', [
            'otherDevices' => $otherDevices,
        ]);
    }

    /**
     * Plain-text summary of another device's toggle states for the D-35
     * read-only panel — no inputs, no edit affordances.
     */
    private static function summarize(NotificationPreferencesDto $dto): string
    {
        $onOff = static fn (bool $value): string => $value ? 'on' : 'off';

        return sprintf(
            'reminders %s · nudges %s · digest %s · savings %s',
            $onOff($dto->remindersEnabled),
            $onOff($dto->budgetNudgesEnabled),
            $dto->digestCadence,
            $onOff($dto->savingsPromptsEnabled),
        );
    }
}
