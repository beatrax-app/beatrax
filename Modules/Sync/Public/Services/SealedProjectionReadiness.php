<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Illuminate\Contracts\Session\Session;

// Enrolment and a held key are two different facts, and three callers of the
// recovery pass disagreed about which one gated it. `current_epoch` is a plain
// integer pointer saying the rows are SUPPOSED to be sealed.
/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md#a-pass-that-cannot-seal-must-not-run
 */
final readonly class SealedProjectionReadiness
{
    public function __construct(
        private EncryptionRecoveryMarkers $markers,
        private SensitiveColumnCodec $codec,
    ) {}

    // Either nothing a projection writes has to be sealed, or the key it would
    // seal with is in reach. False is the state that turns every create a pass
    // rebuilds into a `strategy_error` hold, which is answered by replaying
    // every device's ops at the pk rather than the refused author's create.
    public function canProject(int $userId, Session $session): bool
    {
        return ! $this->markers->isEnrolled($userId) || $this->codec->canSeal($userId, $session);
    }
}
