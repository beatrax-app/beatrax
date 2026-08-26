<?php

declare(strict_types=1);

namespace Modules\Forecasting\Tests\Support;

use Carbon\CarbonImmutable;

/**
 * @link ../../../../.docs/features/forecasting/forecast-corpus.md#the-clock-every-fixture-is-read-against
 */
final class ForecastCorpus
{
    // The notional "today" every fixture is anchored to. Both the shape test
    // and the end-to-end contract test read it from here, so the invariant
    // that no occurrence reaches it cannot be true in one file and false in
    // the other.
    public const string TODAY = '2026-05-01';

    public static function clock(): CarbonImmutable
    {
        return CarbonImmutable::parse(self::TODAY)->startOfDay();
    }

    public static function dir(): string
    {
        return dirname(__DIR__).'/fixtures/forecast-corpus';
    }

    public static function path(string $name): string
    {
        return self::dir().'/'.$name.'.php';
    }

    /**
     * @return list<string>
     */
    public static function paths(): array
    {
        /** @var list<string> $paths */
        $paths = glob(self::dir().'/*.php') ?: [];
        sort($paths);

        return $paths;
    }
}
