<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\DriftAlerts\Internal\Http\Livewire\DriftPage;
use Modules\DriftAlerts\Public\Enums\DriftAlertState;
use Modules\DriftAlerts\Public\Enums\DriftPageTab;
use Modules\DriftAlerts\Tests\Support\DriftAlertFixture;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-20 09:00:00');
    $this->user = DriftAlertFixture::user('boundary');
    $this->actingAs($this->user);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function boundaryHistoryAlerts(int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        DriftAlertFixture::alert(test()->user, [
            'state' => DriftAlertState::Acknowledged->value,
            'actioned_at' => CarbonImmutable::now(),
        ]);
    }
}

// The control was gated on the page being FULL, so a history of exactly one
// page offered "Load more" and pressing it grew the list by nothing. The page
// now reads one row past the window and the control follows that row.
it('hides load more when the history is exactly one page', function (): void {
    boundaryHistoryAlerts(26);

    $component = Livewire::test(DriftPage::class)
        ->set('tab', DriftPageTab::History->value);

    expect($component->viewData('rows'))->toHaveCount(26);
    expect($component->viewData('hasMoreRows'))->toBeFalse();
});

it('offers load more once a twenty-seventh alert exists', function (): void {
    boundaryHistoryAlerts(27);

    $component = Livewire::test(DriftPage::class)
        ->set('tab', DriftPageTab::History->value);

    expect($component->viewData('rows'))->toHaveCount(26);
    expect($component->viewData('hasMoreRows'))->toBeTrue();
});
