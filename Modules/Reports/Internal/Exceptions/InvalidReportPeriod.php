<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Exceptions;

use InvalidArgumentException;
use Modules\Reports\Internal\Enums\PeriodProblem;

// Picking the end date before the start date is an ordinary mid-edit state in a
// two-date picker, so every surface that resolves a period catches this and says
// which half is wrong. Still an InvalidArgumentException, since the aggregator's
// other vocabulary failures are one and a broad caller wants a single catch.
final class InvalidReportPeriod extends InvalidArgumentException
{
    private function __construct(public readonly PeriodProblem $problem, string $message)
    {
        parent::__construct($message);
    }

    public static function incomplete(): self
    {
        return new self(PeriodProblem::Incomplete, 'The "custom" period preset requires both customFrom and customTo dates.');
    }

    public static function malformed(string $field, string $value): self
    {
        return new self(PeriodProblem::Malformed, "The \"{$field}\" date must be a valid \"Y-m-d\" date string, got: \"{$value}\".");
    }

    public static function inverted(): self
    {
        return new self(PeriodProblem::Inverted, 'The "custom" period preset requires customTo to be on or after customFrom.');
    }
}
