<?php

declare(strict_types=1);

namespace Modules\Onboarding\Internal\Services;

use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\SafeDate;
use Modules\Ledger\Public\ValueObjects\Money;
use Modules\Onboarding\Internal\Enums\StartingBalanceRejection;

// The card's detected figures and the map the wizard writes from are both
// public Livewire properties, so the check that used to sit on one submit
// button belongs to the value instead — every path to accounts.starting_
// balance_* runs through here.
final readonly class StartingBalanceRule
{
    private const int MIN_MINOR = -1_000_000_000_00;

    private const int MAX_MINOR = 1_000_000_000_00;

    public function __construct(private Clock $clock) {}

    // The match(true) arms are ordered most-specific-first, so the message
    // the user can actually act on wins.
    public function error(?int $minor, ?string $date, string $currency): ?string
    {
        return match ($this->reject($minor, $date)) {
            StartingBalanceRejection::AmountMissing => Lang::get('onboarding::starting_balance.errors.invalid_amount'),
            StartingBalanceRejection::AmountOutOfRange => Lang::get('onboarding::starting_balance.errors.amount_range', [
                'min' => Money::ofMinor(self::MIN_MINOR, $currency)->formatWholeUnits(),
                'max' => Money::ofMinor(self::MAX_MINOR, $currency)->formatWholeUnits(),
            ]),
            StartingBalanceRejection::DateMissing => Lang::get('onboarding::starting_balance.errors.pick_date'),
            StartingBalanceRejection::DateUnreadable => Lang::get('onboarding::starting_balance.errors.pick_valid_date'),
            StartingBalanceRejection::DateInFuture => Lang::get('onboarding::starting_balance.errors.future_date'),
            null => null,
        };
    }

    public function accepts(?int $minor, ?string $date): bool
    {
        return $this->reject($minor, $date) === null;
    }

    /**
     * @return array{minor: int, date: string}|null
     */
    public function confirmed(mixed $confirmation): ?array
    {
        if (! is_array($confirmation)) {
            return null;
        }

        $minor = $confirmation['minor'] ?? null;
        if (is_string($minor) && preg_match('/^-?\d+$/', $minor) === 1) {
            $minor = (int) $minor;
        }
        $date = $confirmation['date'] ?? null;

        if (! is_int($minor) || ! is_string($date) || ! $this->accepts($minor, $date)) {
            return null;
        }

        return ['minor' => $minor, 'date' => $date];
    }

    // strtotime() read this column, and it reads far more than a date: it
    // accepted 'yesterday', 'last friday' and '2026-02-31', the last as 3
    // March. The wizard's map is a public Livewire property, so the value
    // arriving here is not only what the picker put there.
    private function reject(?int $minor, ?string $date): ?StartingBalanceRejection
    {
        $day = $date === null ? null : SafeDate::dayOrNull($date);

        return match (true) {
            $minor === null => StartingBalanceRejection::AmountMissing,
            $minor < self::MIN_MINOR || $minor > self::MAX_MINOR => StartingBalanceRejection::AmountOutOfRange,
            $date === null || trim($date) === '' => StartingBalanceRejection::DateMissing,
            $day === null => StartingBalanceRejection::DateUnreadable,
            $day->greaterThan($this->clock->now()->startOfDay()) => StartingBalanceRejection::DateInFuture,
            default => null,
        };
    }
}
