<?php

declare(strict_types=1);

use Modules\Forecasting\Internal\Http\Livewire\ForecastPage;
use Modules\Forecasting\Internal\Support\ForecastChartView;
use Modules\Forecasting\Models\ForecastRun;
use Modules\Forecasting\Public\Enums\ForecastHorizon;

/**
 * @return list<string>
 */
function horizonSpellingFiles(): array
{
    $paths = [];
    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('Modules/Forecasting'), RecursiveDirectoryIterator::SKIP_DOTS),
    ) as $file) {
        $path = $file->getPathname();
        if (! $file->isFile() || ! str_ends_with($path, '.php')) {
            continue;
        }
        if (str_contains($path, '/tests/')) {
            continue;
        }
        $paths[] = $path;
    }
    sort($paths);

    return $paths;
}

/**
 * @return array<string, string> pattern => what a match means
 */
function horizonSpellingPatterns(): array
{
    return [
        // A key into an array built from ForecastHorizon::days(). Written as a
        // literal it survives the case it spells being renamed or removed, and
        // then reads an index that is not there.
        '/\$netDiff\s*\[\s*\d/' => 'a bare integer indexing $netDiff',
        // The column ProjectForecastJob validates against ForecastHorizon
        // before it will run. A literal here can name a horizon the job refuses.
        "/'horizon_days'\\s*=>\\s*\\d/" => "a bare integer as a 'horizon_days' value",
        // A constant whose name says horizon and whose value says nothing about
        // where the number came from.
        '/const\s+int\s+\w*HORIZON\w*\s*=\s*\d/' => 'a horizon constant assigned a bare integer',
    ];
}

// ForecastHorizon owns the day counts: the rail offers them, ProjectForecastJob
// refuses anything else, and the net-diff strip is keyed by them. A second
// spelling of one of those numbers is a second horizon set that agrees only
// until the enum changes.
it('spells every horizon day count through ForecastHorizon', function (): void {
    $offenders = [];

    foreach (horizonSpellingFiles() as $path) {
        $source = (string) file_get_contents($path);
        foreach (horizonSpellingPatterns() as $pattern => $meaning) {
            if (preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE) === 1) {
                $line = substr_count(substr($source, 0, $match[0][1]), "\n") + 1;
                $offenders[] = str_replace(base_path().'/', '', $path).':'.$line.' — '.$meaning;
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These name a forecast horizon without going through ForecastHorizon:',
        ...$offenders,
    ]));
});

it('takes the scenario panel tint from a horizon the net-diff array actually holds', function (): void {
    /** @var int $tintHorizon */
    $tintHorizon = (new ReflectionClassConstant(ForecastChartView::class, 'PANEL_TINT_HORIZON'))->getValue();

    expect(ForecastHorizon::days())->toContain($tintHorizon);
});

it('opens the forecast page on a horizon the rail offers and the job accepts', function (): void {
    /** @var int $default */
    $default = (new ReflectionClassConstant(ForecastPage::class, 'DEFAULT_HORIZON'))->getValue();

    expect(ForecastHorizon::tryFrom($default))->not->toBeNull();
});

it('builds a forecast run on a horizon the job accepts', function (): void {
    $horizonDays = ForecastRun::factory()->make()->horizon_days;

    expect(ForecastHorizon::tryFrom($horizonDays))->not->toBeNull();
});
