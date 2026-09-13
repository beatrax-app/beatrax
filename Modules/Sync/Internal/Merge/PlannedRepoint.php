<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

// A finding and what this device would do about it. Every action but one is a
// refusal, and the refusal is the default: a column no writer here announces
// reaches the same skip a column nobody has looked at would.
final readonly class PlannedRepoint
{
    public const string REPOINT = 'repoint';

    public function __construct(
        public UntranslatedParentId $finding,
        public string $action,
    ) {}

    public function writes(): bool
    {
        return $this->action === self::REPOINT;
    }
}
