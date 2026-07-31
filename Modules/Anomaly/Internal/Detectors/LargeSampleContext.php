<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Detectors;

use Modules\Core\Models\User;

/**
 * @link ../../../../.docs/features/anomaly/architecture.md
 */
final readonly class LargeSampleContext
{
    /**
     * @param  list<string>  $types  transaction types in the sampled direction
     */
    public function __construct(
        public User $user,
        public array $types,
        public string $currency,
        public string $windowStart,
        public int $excludeId,
    ) {}
}
