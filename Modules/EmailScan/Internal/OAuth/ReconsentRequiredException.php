<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

use RuntimeException;
use Throwable;

/**
 * @link ../../../../.docs/features/email-scan/architecture.md
 */
final class ReconsentRequiredException extends RuntimeException
{
    public function __construct(
        public readonly int $inboxId,
        public readonly int $userId,
        public readonly string $provider,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            sprintf('OAuth re-consent required for %s inbox %d.', $provider, $inboxId),
            0,
            $previous,
        );
    }
}
