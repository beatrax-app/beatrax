<?php

declare(strict_types=1);

namespace Modules\Mobile\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Core\Public\Support\CountedUsers;
use Modules\Mobile\Internal\Sync\MobileSyncTriggerService;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;

final class MobilePullCommand extends Command
{
    /** @var string */
    protected $signature = 'sync:mobile-pull';

    /** @var string */
    protected $description = 'Run one bounded background sync burst per user (best-effort).';

    public function __construct(
        // Resolved on demand for the reason the next parameter states: this one
        // reaches DeviceIdentityLoader, RelayClient and RelayConfig, none of
        // which exist yet at the moment Artisan builds the command to list it.
        private readonly Container $container,
        // A factory, not the session itself: resolving a session builds the
        // encrypter, and this class is reachable from a console command that
        // Artisan constructs merely to list it.
        private readonly SessionFactory $session,
        private readonly DatabaseManager $db,
        private readonly ?LoggerInterface $logger = null,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $attempted = 0;
        $skipped = 0;

        $this->db->connection()->table('users')
            ->select('id')
            ->lazyById(100)
            ->each(function (stdClass $row) use (&$attempted, &$skipped): void {
                $userId = is_numeric($row->id) ? (int) $row->id : 0;
                if ($userId <= 0) {
                    return;
                }

                $this->runOneBoundedBurstFor($userId) ? $attempted++ : $skipped++;
            });

        $this->info($this->report($attempted, $skipped));

        return self::SUCCESS;
    }

    // An OS-scheduled tick holds no app-lock key, so the device identity does
    // not open and every user is skipped — which is this command's normal
    // outcome on device, not its rare one. Returning SUCCESS in silence made a
    // tick that reached nothing read exactly like one with nothing to do.
    private function report(int $attempted, int $skipped): string
    {
        if ($skipped === 0) {
            return sprintf('Background sync: attempted for %s.', CountedUsers::of($attempted));
        }

        return sprintf(
            'Background sync: attempted for %s, skipped %s — no usable device identity, which a process holding no app-lock key never has.',
            CountedUsers::of($attempted),
            CountedUsers::of($skipped),
        );
    }

    // No retry loop of its own - the bounded LAN retry, if any, lives
    // entirely inside syncOnce() itself. Peer LAN host/port discovery is
    // out of scope for a background tick, so this always dials with no
    // LAN target, falling straight to the off-LAN relay leg.
    /**
     * @return bool Whether a transport leg was attempted at all, so the caller
     *              can tell a tick that ran from one that could not open an
     *              identity to run with.
     */
    private function runOneBoundedBurstFor(int $userId): bool
    {
        // The fan-out is the isolation boundary: one unreadable identity file
        // or one refused relay dial is that user's tick, not every user's. An
        // OS-scheduled process has nobody to report a fatal to.
        try {
            $result = $this->container->make(MobileSyncTriggerService::class)
                ->syncOnce($userId, ($this->session)());
        } catch (Throwable $e) {
            $this->logger?->warning('sync:mobile-pull: bounded background sync burst failed.', [
                'user_id' => $userId,
                'exception' => $e,
            ]);

            return false;
        }

        if ($result === null) {
            $this->logger?->info('sync:mobile-pull: no usable device identity — tick skipped cleanly.', [
                'user_id' => $userId,
            ]);

            return false;
        }

        $this->logger?->info('sync:mobile-pull: bounded background sync burst finished.', [
            'user_id' => $userId,
            'synced' => $result,
        ]);

        return true;
    }
}
