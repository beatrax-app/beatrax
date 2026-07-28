<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Notifications\Public\Dto\NotificationPreferencesDto;
use Modules\Notifications\Public\Enums\DigestCadence;
use Modules\Notifications\Public\Services\NotificationPreferenceQuery;

/**
 * @link ../../../../../.docs/features/notifications/architecture.md
 */
final class NotificationsSettingsSection extends Component
{
    public bool $remindersEnabled = true;

    public int $reminderLeadDays = 3;

    public bool $budgetNudgesEnabled = true;

    public string $digestCadence = 'weekly';

    public bool $savingsPromptsEnabled = false;

    public bool $quietHoursEnabled = false;

    public string $quietHoursFrom = '22:00';

    public string $quietHoursTo = '08:00';

    public bool $hideDetails = false;

    public string $saveError = '';

    public bool $saved = false;

    public function mount(CurrentUser $currentUser, NotificationPreferenceQuery $prefs): void
    {
        $dto = $prefs->forCurrentDevice($currentUser->user());

        $this->remindersEnabled = $dto->remindersEnabled;
        $this->reminderLeadDays = $dto->reminderLeadDays;
        $this->budgetNudgesEnabled = $dto->budgetNudgesEnabled;
        $this->digestCadence = $dto->digestCadence->value;
        $this->savingsPromptsEnabled = $dto->savingsPromptsEnabled;
        $this->quietHoursEnabled = $dto->quietHoursEnabled;
        $this->quietHoursFrom = $dto->quietHoursFrom ?? '22:00';
        $this->quietHoursTo = $dto->quietHoursTo ?? '08:00';
        $this->hideDetails = $dto->hideDetails;
    }

    // Bounds-checks every input before any write - a tampered Livewire
    // payload cannot persist an out-of-range value. The query layer
    // re-validates and throws as defence in depth, but this method never
    // relies on that; it rejects first.
    public function save(CurrentUser $currentUser, NotificationPreferenceQuery $prefs): void
    {
        $this->saveError = '';
        $this->saved = false;

        // The select posts a string, so the enum is where it stops being
        // one. A value no case can represent means a tampered payload.
        $cadence = DigestCadence::tryFrom($this->digestCadence);
        if ($cadence === null) {
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
            digestCadence: $cadence,
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

    // Plain-text summary of another device's toggle states for the
    // read-only panel - no inputs, no edit affordances.
    private static function summarize(NotificationPreferencesDto $dto): string
    {
        $onOff = static fn (bool $value): string => $value ? 'on' : 'off';

        return sprintf(
            'reminders %s · nudges %s · digest %s · savings %s',
            $onOff($dto->remindersEnabled),
            $onOff($dto->budgetNudgesEnabled),
            $dto->digestCadence->value,
            $onOff($dto->savingsPromptsEnabled),
        );
    }
}
