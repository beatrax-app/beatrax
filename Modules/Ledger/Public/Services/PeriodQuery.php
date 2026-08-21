<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Carbon\CarbonImmutable;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Dto\PeriodResolution;

// endExclusive is start + 1 month through the NoOverflow variants, so Feb with
// start_day 28 never rolls into March, and the day is clamped into 1..28
// because not every month has a 29th.
final class PeriodQuery
{
    public const DATE_FORMAT = 'Y-m-d';

    public function __construct(
        private readonly Clock $clock,
        private readonly CurrentUser $currentUser,
    ) {}

    public function current(): Period
    {
        return $this->containing($this->clock->now());
    }

    public function containing(CarbonImmutable $instant): Period
    {
        $startDay = max(1, min(28, $this->currentUser->periodStartDay()));

        $candidate = $instant->setDay($startDay)->startOfDay();
        $start = $instant->day >= $startDay
            ? $candidate
            : $candidate->subMonthNoOverflow();
        $endExclusive = $start->addMonthNoOverflow();

        $label = $startDay === 1
            ? $start->translatedFormat('F Y')
            : $start->translatedFormat('j M').' → '.$endExclusive->subDay()->translatedFormat('j M Y');

        return new Period(start: $start, endExclusive: $endExclusive, label: $label);
    }

    // Carbon accepts "2026-02-30" and normalises it to "2026-03-02", so a
    // round-trip format comparison is the real validity check. Null says the
    // caller's stored string was never a date, not that it has no period.
    public function containingDate(string $isoDate): ?Period
    {
        $parsed = CarbonImmutable::createFromFormat(self::DATE_FORMAT, $isoDate);
        if ($parsed === null || $parsed->format(self::DATE_FORMAT) !== $isoDate) {
            return null;
        }

        return $this->containing($parsed);
    }

    // Resolves a stored view anchor. An anchor that is no longer a date comes
    // back null beside the current period, so the caller drops it rather than
    // round-tripping a value that will never parse again.
    public function resolveAnchor(?string $isoDate): PeriodResolution
    {
        if ($isoDate !== null) {
            $selected = $this->containingDate($isoDate);
            if ($selected !== null) {
                return new PeriodResolution($selected, $isoDate);
            }
        }

        return new PeriodResolution($this->current(), null);
    }

    public function previous(Period $p): Period
    {
        return $this->containing($p->start->subDay());
    }

    public function next(Period $p): Period
    {
        return $this->containing($p->endExclusive);
    }
}
