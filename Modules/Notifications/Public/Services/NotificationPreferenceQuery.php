<?php

declare(strict_types=1);

namespace Modules\Notifications\Public\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\DigestCadence;
use Modules\Notifications\Public\Dto\NotificationPreferencesDto;
use Modules\Notifications\Public\Events\NotificationPreferenceMutated;
use Modules\Sync\Public\Services\DeviceRegistryService;
use stdClass;

final readonly class NotificationPreferenceQuery
{
    use CoercesScalars;

    // The device_id an install with no sync identity writes under, which is
    // the default state and a single-device install's whole life. Not a UUID
    // on purpose: a real device_id always is, so this cannot collide with a
    // peer's.
    public const string UNPAIRED_DEVICE_ID = 'this-device';

    public function __construct(
        private DatabaseManager $db,
        private Dispatcher $events,
        private DeviceRegistryService $devices,
        private Clock $clock,
    ) {}

    // Falls back to the locked defaults when this device has no row yet;
    // never throws, never returns null.
    public function forCurrentDevice(User $user): NotificationPreferencesDto
    {
        $deviceId = $this->deviceKey($user->id);
        $row = $this->rowFor($user->id, $deviceId);

        if (! $row instanceof stdClass) {
            return NotificationPreferencesDto::defaults($deviceId);
        }

        return self::hydrate($row, $deviceId, '');
    }

    // What this device writes its own row under. Pairing gives it a sync
    // identity and this starts answering with that instead, which is why
    // saveForCurrentDevice() carries the older row over.
    private function deviceKey(int $userId): string
    {
        return $this->devices->localDeviceId($userId) ?? self::UNPAIRED_DEVICE_ID;
    }

    // A row written before this device had a sync identity is still this
    // device's row, and reading past it would silently reset a paired
    // install's settings to the defaults.
    private function rowFor(int $userId, string $deviceId): ?stdClass
    {
        $row = $this->db->connection()->table('notification_preferences')
            ->where('user_id', $userId)
            ->whereIn('device_id', array_unique([$deviceId, self::UNPAIRED_DEVICE_ID]))
            ->orderByRaw('device_id = ? desc', [$deviceId])
            ->first();

        return $row instanceof stdClass ? $row : null;
    }

    // A device with a registry row but no preference row is omitted —
    // there is nothing to show for it yet.
    /**
     * @return array<int, NotificationPreferencesDto>
     */
    public function forOtherDevices(User $user): array
    {
        $names = $this->devices->otherDeviceNames($user->id);

        $rows = $this->db->connection()->table('notification_preferences')
            ->where('user_id', $user->id)
            ->whereNotIn('device_id', array_unique([$this->deviceKey($user->id), self::UNPAIRED_DEVICE_ID]))
            ->get();

        $result = [];
        foreach ($rows as $row) {
            /** @var stdClass $row */
            $deviceId = self::toString($row->device_id ?? null);
            $result[] = self::hydrate($row, $deviceId, $names[$deviceId] ?? '');
        }

        return $result;
    }

    public function saveForCurrentDevice(User $user, NotificationPreferencesDto $prefs): void
    {
        self::validate($prefs);

        $deviceId = $this->deviceKey($user->id);
        $this->carryOverUnpairedRow($user->id, $deviceId);

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

    // Renames the pre-pairing row onto this device's sync identity, so the
    // settings a reader chose before they paired survive pairing. Skipped when
    // a row already exists under the new key: the unique index would refuse it,
    // and the newer row is the live one.
    private function carryOverUnpairedRow(int $userId, string $deviceId): void
    {
        if ($deviceId === self::UNPAIRED_DEVICE_ID) {
            return;
        }

        $rows = $this->db->connection()->table('notification_preferences')
            ->where('user_id', $userId);

        if ((clone $rows)->where('device_id', $deviceId)->exists()) {
            return;
        }

        $rows->where('device_id', self::UNPAIRED_DEVICE_ID)->update(['device_id' => $deviceId]);
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
}
