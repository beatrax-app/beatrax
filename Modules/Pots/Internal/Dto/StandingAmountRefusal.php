<?php

declare(strict_types=1);

namespace Modules\Pots\Internal\Dto;

use Modules\Pots\Public\Services\PotWriter;

// The refusal standing over an amount box, as the three values that make it
// one. The ceiling travels with its denomination because the re-test parses
// the corrected figure to compare it: a yen pot's "13840" read at a hundredth
// was 100x the ceiling, so the refusal never cleared however it was retyped.
final readonly class StandingAmountRefusal
{
    public function __construct(
        private string $message,
        private ?int $limitMinor,
        private string $limitCurrency,
    ) {}

    // A refusal names the figure printed beside the box, and the box goes on
    // being edited underneath it. Re-tested rather than cleared: 300 corrected
    // to 100 against 241,09 available stops applying, and 500 does not.
    public function stillRefuses(string $typed, bool $blankIsAllowed, PotWriter $writer): bool
    {
        if ($this->message === '') {
            return false;
        }

        if (trim($typed) === '') {
            return ! $blankIsAllowed;
        }

        $minor = $writer->parseAmount($typed, $this->limitCurrency !== '' ? $this->limitCurrency : null);

        return $minor === null
            || ($this->limitMinor !== null && $minor > $this->limitMinor);
    }
}
