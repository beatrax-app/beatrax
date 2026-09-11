<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\SystemAlertSeverity;
use Modules\Core\Public\Services\SystemAlertWriter;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\StoredCopy;
use Psr\Log\LoggerInterface;

// Two devices that each enrolled and each imported before pairing keep their
// own counterparty key forever, so the declined wrap arrives again every
// pass. Reported per pass it is a log line nobody reads; reported once it is
// a fact about the household the reader can act on.
/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
 */
final readonly class BlindIndexDivergenceAlerts
{
    public const string KIND = 'sync.blind_index.diverged';

    public function __construct(
        private SystemAlertWriter $alerts,
        private Clock $clock,
        private LoggerInterface $logger,
    ) {}

    public function diverged(int $userId): void
    {
        if (! $this->raise($userId)) {
            return;
        }

        $this->logger->error('GdkEpochControlHandler: both this device and the peer already hold rows keyed under different blind-index keys — keeping the local key; merchant identity will not match across the two devices until one side is re-derived.', [
            'user_id' => $userId,
        ]);
    }

    // Taken down by the wrap that proves the split ended rather than by the
    // reader: an alert only a human can close, for a fault that fixed itself,
    // teaches them to dismiss the next one without reading it.
    public function converged(int $userId): void
    {
        try {
            $this->alerts->withdrawForUser($userId, self::KIND, $this->clock->now());
        } catch (\Throwable $e) {
            $this->logger->warning('BlindIndexDivergenceAlerts: the divergence alert could not be withdrawn.', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // True when THIS pass is the one that reported it, which is what keeps the
    // log line and the reader's alert at the same cardinality. A write that
    // fails reports too: silence about a divergence nothing resolves is worse
    // than saying it twice.
    private function raise(int $userId): bool
    {
        $line = CopyLine::of('core::alerts.messages.sync_blind_index_diverged');

        try {
            return $this->alerts->raiseOnceForUser(
                userId: $userId,
                kind: self::KIND,
                severity: SystemAlertSeverity::Warning->value,
                message: $line->sentence(),
                metadata: StoredCopy::inParams($line),
            ) !== null;
        } catch (\Throwable $e) {
            $this->logger->warning('BlindIndexDivergenceAlerts: the divergence alert could not be written.', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return true;
        }
    }
}
