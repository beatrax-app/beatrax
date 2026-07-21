<?php

declare(strict_types=1);

namespace Modules\Mobile\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Mobile\Internal\Sync\MobileSyncTriggerService;
use Psr\Log\LoggerInterface;
use stdClass;

/**
 * @link ../../../.docs/features/mobile/architecture.md
 */
final class MobilePullCommand extends Command
{
    /** @var string */
    protected $signature = 'sync:mobile-pull';

    /** @var string */
    protected $description = 'Run one bounded background sync burst per user (D-07 background cadence leg, best-effort).';

    public function __construct(
        private readonly MobileSyncTriggerService $trigger,
        private readonly Session $session,
        private readonly DatabaseManager $db,
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->db->connection()->table('users')
            ->select('id')
            ->lazyById(100)
            ->each(function (stdClass $row): void {
                $userId = is_numeric($row->id) ? (int) $row->id : 0;
                if ($userId <= 0) {
                    return;
                }

                $this->runOneBoundedBurstFor($userId);
            });

        return self::SUCCESS;
    }

    // No retry loop of its own - the bounded LAN retry, if any, lives
    // entirely inside syncOnce() itself. Peer LAN host/port discovery is
    // out of scope for a background tick, so this always dials with no
    // LAN target, falling straight to the off-LAN relay leg.
    private function runOneBoundedBurstFor(int $userId): void
    {
        $result = $this->trigger->syncOnce($userId, $this->session);

        if ($result === null) {
            $this->logger?->info('sync:mobile-pull: no usable device identity — tick skipped cleanly.', [
                'user_id' => $userId,
            ]);

            return;
        }

        $this->logger?->info('sync:mobile-pull: bounded background sync burst finished.', [
            'user_id' => $userId,
            'synced' => $result,
        ]);
    }
}
