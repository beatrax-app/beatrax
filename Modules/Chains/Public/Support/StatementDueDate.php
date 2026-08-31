<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Support;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\Core\Public\Support\SafeDate;

// The day a card statement asks to be paid, and how far a payment may sit from
// that day and still be the payment for it. The issuer prints the day; only a
// statement that prints none is dated by adding GRACE_DAYS to the period it
// bills.

// GRACE_DAYS was measured on a synthesised statement. The real one this repo
// commits prints a deadline twenty-four days past the period the app derives,
// so a derived due day fell outside the matching window and the payment made
// on the day asked for settled nothing.
/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-tolerance-calibrated-on-a-synthesised-fixture-while-a-real-one-disagrees
 */
final readonly class StatementDueDate
{
    public const int GRACE_DAYS = 5;

    public const int MATCH_WINDOW_DAYS = 10;

    // Both arguments are this app's own dateTime columns by the time they
    // arrive, so the time half is an artefact and normalising is the right
    // read. The printed day is refused where it is SUPPLIED instead —
    // IcsStatementHeader::paymentDueDate() returns null on a line it half-read.
    public static function of(?string $printedDueDate, string $periodEnd): CarbonImmutable
    {
        $printed = $printedDueDate === null ? null : SafeDate::normalisedDayOrNull($printedDueDate);
        if ($printed !== null) {
            return $printed;
        }

        $billed = SafeDate::normalisedDayOrNull($periodEnd);
        if ($billed === null) {
            throw new InvalidArgumentException('card_statements.period_end does not read as a day.');
        }

        return $billed->addDays(self::GRACE_DAYS);
    }

    /**
     * @return array{0: string, 1: string} the days a settlement posted on
     *                                     $postedAt could have been meant
     *                                     for, stated over a printed due day
     */
    public static function printedDueWindow(CarbonImmutable $postedAt): array
    {
        return [
            $postedAt->subDays(self::MATCH_WINDOW_DAYS)->startOfDay()->toDateTimeString(),
            $postedAt->addDays(self::MATCH_WINDOW_DAYS)->endOfDay()->toDateTimeString(),
        ];
    }

    // The same window stated over period_end, for the statements that printed
    // no deadline: their due day IS period_end + GRACE_DAYS, so the window
    // over period_end is the printed one shifted back by exactly that.
    /**
     * @return array{0: string, 1: string}
     */
    public static function derivedDueWindow(CarbonImmutable $postedAt): array
    {
        return self::printedDueWindow($postedAt->subDays(self::GRACE_DAYS));
    }
}
