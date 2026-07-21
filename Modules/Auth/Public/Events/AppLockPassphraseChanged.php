<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Events;

/**
 * @link ../../../../.docs/features/auth/architecture.md
 */
final readonly class AppLockPassphraseChanged
{
    public function __construct(
        public int $userId,
        public string $oldKek,
        public string $newKek,
    ) {}
}
