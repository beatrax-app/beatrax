<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Illuminate\Contracts\Session\Session;
use Modules\Core\Internal\Encryption\PlaintextResidueSweep;
use Modules\Sync\Public\Services\EncryptionRecoveryMarkers;
use Modules\Sync\Public\Services\HistoryReprojector;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use Psr\Log\LoggerInterface;

/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md#getting-back-inside-the-guarantee
 */
final readonly class SealedLedgerRecovery
{
    public function __construct(
        private SensitiveColumnCodec $codec,
        private HistoryReprojector $reprojector,
        private EncryptionRecoveryMarkers $markers,
        private PlaintextResidueSweep $sweep,
        private LoggerInterface $log,
    ) {}

    // Rows a background writer left in the clear, and peer entries a locked
    // drain persisted but never projected. Neither is achievable without a key,
    // so the markers are read first and the keyring only once one says there is
    // work — the cost of having nothing to do is a file hash and two reads.
    public function recover(int $userId, Session $session): void
    {
        if (! $this->markers->isEnrolled($userId)) {
            return;
        }

        $digest = PlaintextResidueSweep::columnsDigest();
        $fingerprint = $this->reprojector->keyringFingerprint($userId);
        $lastFingerprint = $this->markers->reprojectedKeyringFingerprint($userId);
        $since = $this->markers->historyReprojectedAt($userId);

        $needsReseal = $this->markers->resealedColumnsDigest($userId) !== $digest;
        // Deliberately the cheap, epoch-blind question. The exact one needs the
        // keyring, and asking it here would make every page load of an enrolled
        // device decrypt a key file to learn there is nothing to do.
        $mayHaveWork = $fingerprint !== $lastFingerprint
            || $this->reprojector->hasUnexaminedQuarantine($userId, $since);

        if (! $needsReseal && ! $mayHaveWork) {
            return;
        }

        if (! $this->codec->canSeal($userId, $session)) {
            return;
        }

        if ($mayHaveWork) {
            $this->replayQuarantined($userId, $session, $since, $lastFingerprint, $fingerprint);
        }

        if ($needsReseal) {
            $this->resealResidue($userId, $session, $digest);
        }
    }

    // The marks move whether or not anything replayed. A pass that found only
    // entries this device holds no key for has answered the question for THIS
    // keyring, and re-asking it every request is the recurring full replay this
    // seam exists to stop.
    private function replayQuarantined(
        int $userId,
        Session $session,
        ?string $since,
        ?string $lastFingerprint,
        ?string $fingerprint,
    ): void {
        $replayed = $this->reprojector->replayQuarantined($userId, $session, $since, $lastFingerprint);

        $this->markers->markHistoryReprojected($userId, $fingerprint);

        if ($replayed > 0) {
            $this->log->info(
                'SealedLedgerRecovery: re-projected rows a locked drain could not.',
                ['userId' => $userId, 'rows' => $replayed],
            );
        }
    }

    private function resealResidue(int $userId, Session $session, string $digest): void
    {
        $sealed = $this->sweep->run($userId, $session);

        $this->markers->markColumnsResealed($userId, $digest);

        if ($sealed > 0) {
            // Never the values, and not the columns either: the count alone
            // says an install carried readable content under an interface
            // reporting encryption on, which is the thing worth finding later.
            $this->log->warning(
                'SealedLedgerRecovery: re-sealed registered columns that were sitting in the clear.',
                ['userId' => $userId, 'columns' => $sealed],
            );
        }
    }
}
