<?php

declare(strict_types=1);

namespace Modules\Notifications\Public\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Notifications\Public\Dto\NotificationPreferencesDto;
use Modules\Notifications\Public\Enums\DigestCadence;
use Modules\Notifications\Public\Events\NotificationPreferenceMutated;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Psr\Log\LoggerInterface;
use stdClass;

final readonly class NotificationPreferenceQuery
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private Dispatcher $events,
        private DeviceRegistryService $devices,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    // This device's preferences, or the locked defaults when the device
    // has no row or is unpaired. Never throws, never returns null.
    public function forCurrentDevice(User $user): NotificationPreferencesDto
    {
        $deviceId = $this->devices->localDeviceId($user->id);

        if ($deviceId === null) {
            return NotificationPreferencesDto::defaults();
        }

        $row = $this->db->connection()->table('notification_preferences')
            ->where('user_id', $user->id)
            ->where('device_id', $deviceId)
            ->first();

        if (! $row instanceof stdClass) {
            return NotificationPreferencesDto::defaults($deviceId);
        }

        return self::hydrate($row, $deviceId, '');
    }

    // Every other device's preferences, read-only. Named via
    // DeviceRegistryService::otherDeviceNames(); a device with a
    // registry row but no preference row is omitted (nothing to show yet).
    /**
     * @return array<int, NotificationPreferencesDto>
     */
    public function forOtherDevices(User $user): array
    {
        $localDeviceId = $this->devices->localDeviceId($user->id);
        $names = $this->devices->otherDeviceNames($user->id);

        $rows = $this->db->connection()->table('notification_preferences')
            ->where('user_id', $user->id)
            ->when($localDeviceId !== null, fn ($query) => $query->where('device_id', '!=', $localDeviceId))
            ->get();

        $result = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $deviceId = self::toString($row->device_id ?? null);
            $result[] = self::hydrate($row, $deviceId, $names[$deviceId] ?? '');
        }

        return $result;
    }

    // Writes THIS device's preferences. Validates server-side -
    // out-of-range input throws, never clamps. Dispatches
    // NotificationPreferenceMutated after the write commits. A no-op
    // when the device is unpaired - logged, never thrown.
    public function saveForCurrentDevice(User $user, NotificationPreferencesDto $prefs): void
    {
        self::validate($prefs);

        $deviceId = $this->devices->localDeviceId($user->id);

        if ($deviceId === null) {
            $this->logger->warning(
                'NotificationPreferenceQuery: refusing to save preferences for an unpaired device.',
                ['user_id' => $user->id],
            );

            return;
        }

        $existing = $this->db->connection()->table('notification_preferences')
            ->where('user_id', $user->id)
            ->where('device_id', $deviceId)
            ->first();

        $now = $this->clock->now()->toDateTimeString();

        /** @var array<string, mixed> $values */
        $values = [
            'reminders_enabled' => $prefs->remindersEnabled,
            'budget_nudges_enabled' => $prefs->budgetNudgesEnabled,
            'digest_cadence' => $prefs->digestCadence->value,
            'savings_prompts_enabled' => $prefs->savingsPromptsEnabled,
            'reminder_lead_days' => $prefs->reminderLeadDays,
            'quiet_hours_enabled' => $prefs->quietHoursEnabled,
            'quiet_hours_from' => $prefs->quietHoursFrom,
            'quiet_hours_to' => $prefs->quietHoursTo,
            'hide_details' => $prefs->hideDetails,
            'updated_at' => $now,
        ];

        if ($existing === null) {
            $values['created_at'] = $now;
        }

        $this->db->connection()->table('notification_preferences')
            ->updateOrInsert(
                ['user_id' => $user->id, 'device_id' => $deviceId],
                $values,
            );

        $preferenceId = self::toInt($this->db->connection()->table('notification_preferences')
            ->where('user_id', $user->id)
            ->where('device_id', $deviceId)
            ->value('id'));

        $this->events->dispatch(new NotificationPreferenceMutated(
            preferenceId: $preferenceId,
            userId: $user->id,
            mutationType: $existing === null ? 'create' : 'edit',
            dirtyFields: $values,
        ));
    }

    private static function validate(NotificationPreferencesDto $prefs): void
    {
        if ($prefs->reminderLeadDays < 1 || $prefs->reminderLeadDays > 30) {
            throw new InvalidArgumentException(
                "Reminder lead days {$prefs->reminderLeadDays} out of range (1..30).",
            );
        }

        foreach ([$prefs->quietHoursFrom, $prefs->quietHoursTo] as $time) {
            if ($time !== null && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time) !== 1) {
                throw new InvalidArgumentException(
                    "Invalid quiet-hours time '{$time}' (expected HH:MM).",
                );
            }
        }
    }

    private static function hydrate(stdClass $row, string $deviceId, string $deviceName): NotificationPreferencesDto
    {
        return new NotificationPreferencesDto(
            remindersEnabled: self::toBool($row->reminders_enabled ?? null),
            budgetNudgesEnabled: self::toBool($row->budget_nudges_enabled ?? null),
            // A row written before the CHECK constraint, or by hand, falls
            // back to the default rather than failing the whole read.
            digestCadence: DigestCadence::tryFrom(self::toString($row->digest_cadence ?? null)) ?? DigestCadence::Weekly,
            savingsPromptsEnabled: self::toBool($row->savings_prompts_enabled ?? null),
            reminderLeadDays: self::toInt($row->reminder_lead_days ?? null),
            quietHoursEnabled: self::toBool($row->quiet_hours_enabled ?? null),
            quietHoursFrom: self::toStringOrNull($row->quiet_hours_from ?? null),
            quietHoursTo: self::toStringOrNull($row->quiet_hours_to ?? null),
            hideDetails: self::toBool($row->hide_details ?? null),
            deviceId: $deviceId,
            deviceName: $deviceName,
        );
    }

    private static function toBool(mixed $value): bool
    {
        return (bool) (is_numeric($value) ? (int) $value : $value);
    }

    private static function toStringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::toString($value);
    }
}
