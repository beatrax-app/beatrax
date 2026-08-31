<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Mapping;

use Carbon\CarbonImmutable;

// Which projection a curve is: how far ahead it reaches, whose scenario it is,
// and the day it opens on. The three travel together through every shape the
// forecast is built in — a mapped run, a flat line, a computing sentinel — and
// a curve carrying one of them from a different run draws the wrong days.
final readonly class ForecastWindow
{
    public function __construct(
        public int $horizonDays,
        public ?int $scenarioId,
        public CarbonImmutable $asOf,
    ) {}

    public function openingOn(CarbonImmutable $asOf): self
    {
        return new self($this->horizonDays, $this->scenarioId, $asOf);
    }
}
