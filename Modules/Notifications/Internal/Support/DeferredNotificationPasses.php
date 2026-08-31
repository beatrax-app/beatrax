<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Support;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Session\Session;
use Modules\Core\Public\Scheduling\DailyLocalWindow;
use Modules\Core\Public\Services\EncryptionMigrationService;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Notifications\Internal\Enums\DeferredNotificationPass;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

/**
 * @link ../../../../.docs/features/mobile/background-sync-cannot-hold-the-key.md#the-scheduled-passes-that-cannot-write-either
 */
final readonly class DeferredNotificationPasses
{
    private const string KEY_PREFIX = 'beatrax:deferred-notification-pass:';

    // The runner is resolved on demand, never injected: it pulls four modules'
    // dispatch seams into the container, and the marking half — which is what
    // every scheduled pass calls — must not pay for a graph it will not use.
    public function __construct(
        private Container $container,
        private Repository $cache,
        private EncryptionMigrationService $encryption,
        private SensitiveColumnCodec $codec,
        private SessionFactory $session,
    ) {}

    // Asked BEFORE the pass reads anything. That ordering is the privacy
    // property: the mark then records that a keyless process was asked to run,
    // never that content was derivable — recording the second would put in the
    // clear exactly what sealing `notifications.trigger_type` hides.
    public function deferIfKeyless(int $userId, DeferredNotificationPass $pass): bool
    {
        if (! $this->sealedOut($userId, ($this->session)())) {
            return false;
        }

        // The scheduler re-asks hourly for nudges and daily for the triggers, so
        // a mark that expires is re-made long before a locked phone is opened.
        // The span is taken from the window rather than restated here, which is
        // what a comment asserting the two matched could not keep true.
        $this->cache->put(
            self::key($userId, $pass),
            true,
            DailyLocalWindow::claimTtlSeconds(),
        );

        return true;
    }

    /**
     * @return list<DeferredNotificationPass>
     */
    public function outstandingFor(int $userId): array
    {
        $outstanding = [];

        foreach (DeferredNotificationPass::cases() as $pass) {
            if ($this->cache->has(self::key($userId, $pass))) {
                $outstanding[] = $pass;
            }
        }

        return $outstanding;
    }

    // The whole cost of having nothing to do is one cache read per pass: the
    // keyring is only opened once a mark says a pass is waiting on it, so an
    // install that never enabled encryption never builds the codec's graph.
    public function runOutstanding(int $userId, Session $session): void
    {
        $outstanding = $this->outstandingFor($userId);

        if ($outstanding === [] || $this->sealedOut($userId, $session)) {
            return;
        }

        $runner = $this->container->make(DeferredNotificationPassRunner::class);

        foreach ($outstanding as $pass) {
            $runner->run($userId, $pass);

            // Cleared after the run, so a pass that threw leaves its mark
            // standing and the next keyed request takes it again.
            $this->cache->forget(self::key($userId, $pass));
        }
    }

    // The two halves the codec itself distinguishes. A user who never enabled
    // encryption is not sealed out — their columns are plaintext by design and
    // the scheduler writes them fine — so deferring theirs would replay work
    // that already happened.
    private function sealedOut(int $userId, Session $session): bool
    {
        return $this->encryption->isEnabled($userId) && ! $this->codec->canSeal($userId, $session);
    }

    private static function key(int $userId, DeferredNotificationPass $pass): string
    {
        return self::KEY_PREFIX.$userId.':'.$pass->value;
    }
}
