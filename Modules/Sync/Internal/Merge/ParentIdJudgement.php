<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

// What the log says about one of the peer's numbers. A null verdict is the
// peer and this device agreeing, which is the ordinary answer and the one that
// never reaches a reader.
final readonly class ParentIdJudgement
{
    private function __construct(
        public ?UntranslatedParentVerdict $verdict,
        public ?string $correct,
        public string $evidence,
    ) {}

    public static function agreed(string $evidence): self
    {
        return new self(null, null, $evidence);
    }

    public static function misfiled(string $correct, string $evidence): self
    {
        return new self(UntranslatedParentVerdict::Misfiled, $correct, $evidence);
    }

    public static function unplaceable(string $evidence): self
    {
        return new self(UntranslatedParentVerdict::Unplaceable, null, $evidence);
    }

    public static function unspoken(string $evidence): self
    {
        return new self(UntranslatedParentVerdict::Unspoken, null, $evidence);
    }
}
