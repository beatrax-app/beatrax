<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Services;

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\SafeDate;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Dto\PeriodResolution;

// endExclusive is start + 1 month through the NoOverflow variants, so Feb with
// start_day 28 never rolls into March, and the day is clamped into 1..28
// because not every month has a 29th.
final readonly class PeriodQuery
{
    // 28 so a period start exists in February. The settings validator and this
    // clamp read the same pair: widen one alone and the other silently pulls
    // the reader's choice back rather than refusing it.
    public const int MIN_START_DAY = 1;

    public const int MAX_START_DAY = 28;

    public function __construct(
        private Clock $clock,
        private CurrentUser $currentUser,
    ) {}

    public function current(): Period
    {
        return $this->containing($this->clock->now());
    }

    public function containing(CarbonImmutable $instant): Period
    {
        return $this->window($this->currentUser->periodStartDay(), $instant);
    }

    // For a writer handed the owner explicitly: the guard carries whoever is
    // browsing, which in a queued or console promote is nobody, and answering
    // from an install default would key the row to a calendar the owner
    // does not keep.
    public function containingForUser(User $user, CarbonImmutable $instant): Period
    {
        return $this->window($user->period_start_day, $instant);
    }

    // For a key written under a start day the reader has since changed: the
    // window has to be taken on the day the key was written, not on the day
    // now stored, or the row is read into the period beside its own.
    public function containingForDay(int $startDay, CarbonImmutable $instant): Period
    {
        return $this->window($startDay, $instant);
    }

    private function window(int $configuredStartDay, CarbonImmutable $instant): Period
    {
        $startDay = max(self::MIN_START_DAY, min(self::MAX_START_DAY, $configuredStartDay));

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

    // Null says the caller's stored string was never a date, not that it has
    // no period.
    public function containingDate(string $isoDate): ?Period
    {
        $parsed = self::parseIsoDate($isoDate);

        return $parsed === null ? null : $this->containing($parsed);
    }

    // The same read taken on a start day the reader no longer keeps, for a key
    // written before they moved it.
    public function containingDateForDay(string $isoDate, int $startDay): ?Period
    {
        $parsed = self::parseIsoDate($isoDate);

        return $parsed === null ? null : $this->window($startDay, $parsed);
    }

    // A stored anchor a reader can also put in the address bar, so it is
    // judged by the same reading of "is this a day" as every other supplied
    // date rather than by a fourth copy of the round-trip written here.
    private static function parseIsoDate(string $isoDate): ?CarbonImmutable
    {
        return SafeDate::dayOrNull($isoDate);
    }

    // Resolves a stored view anchor, canonicalised to the period's own start.
    // Any day inside a period selects it, so echoing the raw value let a page
    // carry one date while rendering another period's numbers. An anchor that
    // is no longer a date comes back null beside the current period.
    public function resolveAnchor(?string $isoDate): PeriodResolution
    {
        if ($isoDate !== null) {
            $selected = $this->containingDate($isoDate);
            if ($selected !== null) {
                return new PeriodResolution($selected, $selected->start->toDateString());
            }
        }

        return new PeriodResolution($this->current(), null);
    }

    // Off the period's own start day, never the guard's: the argument already
    // carries the calendar it turns on, and reading the browsing reader's
    // instead stepped back from a 25th onto the 1st -- and made a pure date
    // operation need somebody signed in at all.
    public function previous(Period $p): Period
    {
        return $this->containingForDay($p->start->day, $p->start->subDay());
    }

    public function next(Period $p): Period
    {
        return $this->containingForDay($p->start->day, $p->endExclusive);
    }
}
