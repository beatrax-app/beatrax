<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Support\SafeExceptionContext;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @link ../../../../.docs/features/desktop/auto-update.md#the-off-switch
 */
final readonly class UpdateCheckPreference
{
    public const string COLUMN = 'auto_update_check_enabled';

    public function __construct(
        private DatabaseManager $db,
        private LoggerInterface $logger,
    ) {}

    // Device-wide, not per reader: the alert the check produces is written at
    // `user_id => null` so every account sees the one banner, and the call it
    // makes is one call from one machine. A reader who switched it off switched
    // off the only outbound call this bundle would otherwise make.
    public function enabled(): bool
    {
        try {
            return ! $this->db->connection()
                ->table('users')
                ->where(self::COLUMN, false)
                ->exists();
        } catch (Throwable $e) {
            // First launch reaches this before the table exists, which is a
            // real state and not a refusal: the shipped posture is on, so an
            // unreadable answer must not become a silent opt-out.
            $this->logger->warning(
                'UpdateCheckPreference: could not read the stored answer, assuming the shipped default.',
                SafeExceptionContext::describe($e),
            );

            return true;
        }
    }
}
