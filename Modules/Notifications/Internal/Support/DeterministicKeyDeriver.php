<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Support;

/**
 * @link ../../../../.docs/features/notifications/architecture.md
 */
final class DeterministicKeyDeriver
{
    public const TRIGGER_IMPORT_FINISHED = 'import_finished';

    public const TRIGGER_RECEIPTS_FOUND = 'receipts_found';

    public const TRIGGER_DRIFT_CHANGED = 'drift_changed';

    public const TRIGGER_FORECAST_SHORTFALL = 'forecast_shortfall';

    public const TRIGGER_PAYMENT_REMINDER = 'payment_reminder';

    public const TRIGGER_POSITION_DIGEST = 'position_digest';

    public const TRIGGER_BUDGET_NUDGE = 'budget_nudge';

    public const TRIGGER_SAVINGS_PROMPT = 'savings_prompt';

    public const TRIGGER_ICS_STATEMENT_READY = 'ics_statement_ready';

    public function derive(int $userId, string $triggerType, string $subjectKey, string $occurrence): string
    {
        $payload = json_encode(
            [
                'user_id' => $userId,
                'trigger_type' => $triggerType,
                'subject_key' => $subjectKey,
                'occurrence' => $occurrence,
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
        );

        return hash('sha256', $payload);
    }
}
