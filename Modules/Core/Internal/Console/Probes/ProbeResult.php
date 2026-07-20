<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console\Probes;

/**
 * @link ../../../../../.docs/features/core/architecture.md
 */
final readonly class ProbeResult
{
    /**
     * @param  'ok'|'warning'|'critical'  $severity
     * @param  array<string, scalar|null>  $metadata
     */
    public function __construct(
        public string $severity,
        public string $message,
        public array $metadata = [],
    ) {}
}
