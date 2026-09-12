<?php

declare(strict_types=1);

namespace Modules\FX\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Core\Public\Support\Lang;
use Modules\FX\Public\Services\ExchangeRateService;
use Modules\FX\Public\Support\BundledRates;

// What one figure has to say about its own conversion: the rates that built it
// and the codes no rate reached. Twenty-eight surfaces rendered the second half
// and none the first, because the first was thrown away one call earlier. The
// oldest leg answers for the whole, as it does for any multi-leg conversion.
/**
 * @link ../../../../.docs/features/fx/architecture.md#a-converted-figure-carries-the-rate-that-made-it
 */
final readonly class ConversionDisclosure
{
    /**
     * @param  list<RateUsed>  $rates
     * @param  list<string>  $unconverted  currency codes left out for want of a rate
     */
    private function __construct(
        public string $currency,
        public array $rates,
        public array $unconverted,
    ) {}

    public static function none(): self
    {
        return new self('', [], []);
    }

    /**
     * @param  list<string>  $unconverted
     */
    public static function of(RateSet $rates, array $unconverted = []): self
    {
        sort($unconverted);

        return new self($rates->targetCurrency, $rates->all(), $unconverted);
    }

    // Nothing to render rather than an empty line: a figure whose buckets were
    // all already in the reader's currency converted nothing and has nothing to
    // disclose, which is the passthrough B10 gives a zero-cost path.
    public function isEmpty(): bool
    {
        return $this->rates === [] && $this->unconverted === [];
    }

    public function hasRates(): bool
    {
        return $this->rates !== [];
    }

    public function isPartial(): bool
    {
        return $this->unconverted !== [];
    }

    public function unconvertedList(): string
    {
        return implode(', ', $this->unconverted);
    }

    public function oldest(): ?RateUsed
    {
        $oldest = null;

        foreach ($this->rates as $rate) {
            if ($rate->asOf === null) {
                continue;
            }

            if ($oldest?->asOf === null || $rate->asOf < $oldest->asOf) {
                $oldest = $rate;
            }
        }

        return $oldest ?? ($this->rates[0] ?? null);
    }

    public function asOf(): ?CarbonImmutable
    {
        return $this->oldest()?->asOf;
    }

    public function source(): ?string
    {
        return $this->oldest()?->source;
    }

    public function sourceLabel(): string
    {
        return $this->oldest()?->sourceLabel() ?? Lang::get('core::fx.source_fallback');
    }

    // Null where nothing is stale, so a renderer has no branch of its own to
    // get wrong. Only `fx:refresh-rates` ends the wait and it skips a reader
    // with online fetching off, so promising them a refresh names a job
    // nothing runs — which is why the caller's toggle changes the sentence.
    public function staleNote(?bool $onlineEnabled = null): ?string
    {
        if (! $this->isStale()) {
            return null;
        }

        $key = match (true) {
            $this->source() === BundledRates::SOURCE => 'core::fx.stale_bundled',
            $onlineEnabled === false => 'core::fx.stale_offline',
            default => 'core::fx.stale_old',
        };

        return Lang::choice($key, ExchangeRateService::STALE_DAYS_THRESHOLD);
    }

    public function isStale(): bool
    {
        foreach ($this->rates as $rate) {
            if ($rate->isStale) {
                return true;
            }
        }

        return false;
    }

    // The age of the oldest leg in days, which is the number that separates a
    // rate fetched this morning from the bundled snapshot a fresh install
    // converts at for as long as online refresh stays off.
    public function ageInDaysAt(CarbonImmutable $when): ?int
    {
        return $this->oldest()?->ageInDaysAt($when);
    }
}
