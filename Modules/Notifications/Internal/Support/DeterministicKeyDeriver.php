<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Support;

use Modules\Notifications\Public\Enums\NotificationTrigger;

final class DeterministicKeyDeriver
{
    // The derived id is the dedupe key, so whatever tells two notifications
    // apart has to be inside the subject. Two accounts dipping on one day share
    // (user, trigger, occurrence): under a bare 'forecast' they collapsed onto
    // one row and the second account's shortfall was never announced.
    public static function forecastShortfallSubject(int $accountId): string
    {
        return 'forecast:account:'.$accountId;
    }

    public function derive(int $userId, NotificationTrigger $trigger, string $subjectKey, string $occurrence): string
    {
        $payload = json_encode(
            [
                'user_id' => $userId,
                'trigger_type' => $trigger->value,
                'subject_key' => $subjectKey,
                'occurrence' => $occurrence,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );

        return hash('sha256', $payload);
    }
}
