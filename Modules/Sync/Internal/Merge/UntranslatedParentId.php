<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

// One id a peer sent that does not name the row the peer meant, with what the
// log proves about it. `correctValue` is filled for `Misfiled` alone -- the
// other verdicts are the refusals, and a refusal carrying a target would be a
// guess wearing the same shape as an answer.
final readonly class UntranslatedParentId
{
    public function __construct(
        public PeerParentColumn $at,
        public ?string $storedValue,
        public ?string $correctValue,
        public UntranslatedParentVerdict $verdict,
        public string $evidence,
    ) {}

    // Whether the judgement still asks for a write. A target already in the
    // column is this device having caught up, not a row to move again, which
    // is what makes a second run repair nothing without a flag to remember.
    public function stillOwed(): bool
    {
        return $this->correctValue === null || $this->correctValue !== $this->storedValue;
    }
}
