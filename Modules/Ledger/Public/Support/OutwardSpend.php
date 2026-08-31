<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Support;

// Spend is signed on purpose, so a category's period net can come out below
// zero. Ranking, share and empty state are one decision here rather than three
// contradicting ones, and the narrowing is the donut's: keep the parts running
// the way the whole runs, and carry out what that left for the screen to say.
/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-one-directional-figure-ranked-on-a-signed-sum
 */
final readonly class OutwardSpend
{
    /**
     * @param  array<int, int>  $rankedMinor  largest first, every value above zero
     * @param  int  $totalMinor  the sum of $rankedMinor, and the only whole a share here is cut from
     * @param  int  $inwardMinor  signed sum of the keys left out, which is money that came back
     */
    private function __construct(
        public array $rankedMinor,
        public int $totalMinor,
        public int $inwardMinor,
        public int $inwardCount,
    ) {}

    /**
     * @param  array<int, int>  $spendByKey  signed net spend per key, all in one currency
     */
    public static function from(array $spendByKey, ?int $limit = null): self
    {
        $outward = [];
        $inwardMinor = 0;
        $inwardCount = 0;

        foreach ($spendByKey as $key => $spendMinor) {
            if ($spendMinor > 0) {
                $outward[$key] = $spendMinor;

                continue;
            }

            // Nought is neither: a category refunded to exactly what it cost
            // spent nothing, and nothing came back out of it either.
            if ($spendMinor < 0) {
                $inwardMinor += $spendMinor;
                $inwardCount++;
            }
        }

        arsort($outward);

        if ($limit !== null) {
            $outward = array_slice($outward, 0, $limit, preserve_keys: true);
        }

        return new self($outward, array_sum($outward), $inwardMinor, $inwardCount);
    }

    public function shareOf(int $partMinor): float
    {
        return self::share($partMinor, $this->totalMinor);
    }

    // Both ends are tested. A whole that is nought or negative has no parts to
    // be a fraction of, and a part running the other way is not a fraction of
    // this whole either -- it is what the whole was narrowed down from.
    public static function share(int $partMinor, int $wholeMinor): float
    {
        if ($partMinor <= 0 || $wholeMinor <= 0) {
            return 0.0;
        }

        return $partMinor / $wholeMinor;
    }
}
