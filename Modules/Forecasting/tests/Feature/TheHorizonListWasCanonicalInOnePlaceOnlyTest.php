<?php

declare(strict_types=1);

use Modules\Forecasting\Internal\Jobs\ProjectForecastJob;
use Modules\Forecasting\Public\Enums\ForecastHorizon;

function horizonSource(string $relative): string
{
    return (string) file_get_contents(base_path('Modules/Forecasting/'.$relative));
}

it('names the set it accepts from the enum, so the refusal cannot go stale', function (): void {
    expect(fn (): ProjectForecastJob => new ProjectForecastJob(1, null, 45))
        ->toThrow(InvalidArgumentException::class, ForecastHorizon::spelledOut());
});

it('keeps no second horizon list in the views', function (): void {
    $views = [
        horizonSource('Resources/views/livewire/forecast-page.blade.php'),
        horizonSource('Resources/views/livewire/partials/net-diff-tile.blade.php'),
    ];

    foreach ($views as $source) {
        expect($source)->not->toMatch('/\[\s*30\s*,\s*60/')
            ->and($source)->not->toContain('ProjectForecastJob::HORIZON_DAYS');
    }
});

it('draws one net-diff column per horizon rather than a count frozen at three', function (): void {
    $source = horizonSource('Resources/views/livewire/partials/net-diff-tile.blade.php');

    expect($source)->toContain('lg:grid-cols-'.count(ForecastHorizon::cases()))
        ->and($source)->not->toContain('sm:grid-cols-3');
});
