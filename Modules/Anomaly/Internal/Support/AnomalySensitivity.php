<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Support;

use InvalidArgumentException;

// The two anomaly settings, their range and their defaults in one place. The
// range used to be enforced only in the settings form, so a stored 500 reached
// kForSensitivity() as k = -15 and clamped to MAXIMUM sensitivity: a setting
// that degrades into its own opposite has to be a type, not a convention.
final readonly class AnomalySensitivity
{
    public const int DEFAULT_PERCENT = 50;

    public const int MIN_PERCENT = 1;

    public const int MAX_PERCENT = 100;

    // The companion setting: the same form writes it, the same migration
    // seeds it and the same evaluator reads it.
    public const int DEFAULT_MIN_AMOUNT_MINOR = 1000;

    private function __construct(public int $percent) {}

    public static function from(int $percent): self
    {
        $sensitivity = self::tryFrom($percent);
        if ($sensitivity === null) {
            throw new InvalidArgumentException(sprintf(
                'AnomalySensitivity: percent must be between %d and %d; got %d.',
                self::MIN_PERCENT,
                self::MAX_PERCENT,
                $percent,
            ));
        }

        return $sensitivity;
    }

    public static function tryFrom(int $percent): ?self
    {
        if ($percent < self::MIN_PERCENT || $percent > self::MAX_PERCENT) {
            return null;
        }

        return new self($percent);
    }

    public static function default(): self
    {
        return new self(self::DEFAULT_PERCENT);
    }

    // A stored value outside the range reads as the DEFAULT, never as the
    // nearest bound: clamping an over-large number lands on maximum
    // sensitivity, which is the silent failure this type exists to stop.
    public static function fromStored(mixed $value): self
    {
        return (is_numeric($value) ? self::tryFrom((int) $value) : null) ?? self::default();
    }
}
