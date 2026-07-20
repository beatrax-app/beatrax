<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * @link ../../../../../.docs/features/core/architecture.md
 */
final class DurationParser
{
    /**
     * @throws InvalidArgumentException when `$input` does not match the
     *                                  `/^\d+[dhw]$/i` grammar, or is zero
     */
    public function subFromNow(string $input, CarbonImmutable $now): CarbonImmutable
    {
        if (preg_match('/^(\d+)([dhw])$/i', $input, $matches) !== 1) {
            throw new InvalidArgumentException(sprintf(
                "Duration must match /^\\d+[dhw]\$/ (e.g. '30d', '7d', '12h', '2w'). Got: '%s'.",
                $input,
            ));
        }

        $amount = (int) $matches[1];
        if ($amount <= 0) {
            throw new InvalidArgumentException(sprintf(
                "Duration amount must be a positive integer (zero would delete every row). Got: '%s'.",
                $input,
            ));
        }
        $unit = strtolower($matches[2]);

        // The regex character class above already guarantees `$unit` is
        // one of `d|h|w`. The default arm is included so PHPStan's
        // match-exhaustiveness analysis stays happy without depending
        // on cross-statement narrowing from the preg_match guard.
        return match ($unit) {
            'd' => $now->subDays($amount),
            'h' => $now->subHours($amount),
            'w' => $now->subWeeks($amount),
            default => throw new InvalidArgumentException(sprintf(
                "Duration must match /^\\d+[dhw]\$/ (e.g. '30d', '7d', '12h', '2w'). Got: '%s'.",
                $input,
            )),
        };
    }
}
