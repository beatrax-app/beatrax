<?php

declare(strict_types=1);

namespace Modules\Notifications\Public\Dto;

use Modules\Core\Public\Enums\DigestCadence;
use Spatie\LaravelData\Data;

final class NotificationPreferencesDto extends Data
{
    public function __construct(
        public readonly bool $remindersEnabled,
        public readonly bool $budgetNudgesEnabled,
        public readonly DigestCadence $digestCadence,
        public readonly bool $savingsPromptsEnabled,
        public readonly int $reminderLeadDays,
        public readonly bool $quietHoursEnabled,
        public readonly ?string $quietHoursFrom,
        public readonly ?string $quietHoursTo,
        public readonly bool $hideDetails,
        public readonly ?string $deviceId = null,
        public readonly string $deviceName = '',
    ) {}

    // The locked defaults - the single source of truth consumed by
    // NotificationPreferenceQuery::forCurrentDevice() for an
    // unpaired/preference-less device and by the settings UI when
    // rendering the out-of-box form state.
    public static function defaults(?string $deviceId = null): self
    {
        return new self(
            remindersEnabled: true,
            budgetNudgesEnabled: true,
            digestCadence: DigestCadence::Weekly,
            savingsPromptsEnabled: false,
            reminderLeadDays: 3,
            quietHoursEnabled: false,
            quietHoursFrom: '22:00',
            quietHoursTo: '08:00',
            hideDetails: false,
            deviceId: $deviceId,
            deviceName: '',
        );
    }
}
