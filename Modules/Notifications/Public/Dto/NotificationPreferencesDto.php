<?php

declare(strict_types=1);

namespace Modules\Notifications\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * One device's full notification-preference set (D-34). Returned by
 * `NotificationPreferenceQuery::forCurrentDevice()` /
 * `::forOtherDevices()` — a device with no stored row gets `defaults()`
 * verbatim, never null and never an error (Req 9).
 *
 * `deviceId` is null only for the synthetic "no row, no paired device"
 * default. `deviceName` is empty for `forCurrentDevice()` (the settings UI
 * already knows it's rendering the local device) and populated from
 * `DeviceRegistryService::otherDeviceNames()` for `forOtherDevices()`
 * (D-35's read-only "Other devices" panel).
 */
final class NotificationPreferencesDto extends Data
{
    public function __construct(
        public readonly bool $remindersEnabled,
        public readonly bool $budgetNudgesEnabled,
        public readonly string $digestCadence,
        public readonly bool $savingsPromptsEnabled,
        public readonly int $reminderLeadDays,
        public readonly bool $quietHoursEnabled,
        public readonly ?string $quietHoursFrom,
        public readonly ?string $quietHoursTo,
        public readonly bool $hideDetails,
        public readonly ?string $deviceId = null,
        public readonly string $deviceName = '',
    ) {}

    /**
     * The D-16/D-19/D-15/D-24 locked defaults — the single source of truth
     * consumed by `NotificationPreferenceQuery::forCurrentDevice()` for an
     * unpaired/preference-less device AND by the settings UI (18-13) when
     * rendering the out-of-box form state.
     */
    public static function defaults(?string $deviceId = null): self
    {
        return new self(
            remindersEnabled: true,
            budgetNudgesEnabled: true,
            digestCadence: 'weekly',
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
