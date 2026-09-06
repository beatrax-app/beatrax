<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Notifications;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Instant;
use Modules\Notifications\Public\Enums\SystemNotificationGrant;

// The device-local record of what this install's OS answered. Every write
// here is a plain query with no capture: the row describes the operating
// system this copy of the app is running on, and a peer taking it would read
// another device's permissions as its own.
final readonly class NotificationGrantRecord
{
    private const string TABLE = 'mobile_notification_grant';

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
    ) {}

    public function state(int $userId): SystemNotificationGrant
    {
        $row = $this->db->connection()->table(self::TABLE)
            ->where('user_id', $userId)
            ->first();

        if ($row === null) {
            return SystemNotificationGrant::NeverAsked;
        }

        if ($row->granted === null) {
            return SystemNotificationGrant::Awaiting;
        }

        return $row->granted ? SystemNotificationGrant::Granted : SystemNotificationGrant::Refused;
    }

    // Stamped BEFORE the prompt is raised, so a reader who backgrounds the app
    // mid-dialog is not asked again on every boot. The answer arrives
    // separately, or not at all.
    public function markAsked(int $userId): void
    {
        $now = Instant::zulu($this->clock->now());

        $this->db->connection()->table(self::TABLE)->updateOrInsert(
            ['user_id' => $userId],
            ['requested_at' => $now],
        );
    }

    public function recordAnswer(int $userId, bool $granted): void
    {
        $now = Instant::zulu($this->clock->now());

        $this->db->connection()->table(self::TABLE)->updateOrInsert(
            ['user_id' => $userId],
            ['granted' => $granted, 'answered_at' => $now, 'requested_at' => $now],
        );
    }
}
