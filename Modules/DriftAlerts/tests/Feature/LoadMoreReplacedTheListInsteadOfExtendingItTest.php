<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\DriftAlerts\Internal\Http\Livewire\DriftPage;
use Modules\DriftAlerts\Public\Enums\DriftAlertState;
use Modules\DriftAlerts\Public\Enums\DriftPageTab;
use Modules\DriftAlerts\Public\Services\DriftAlertQuery;
use Modules\DriftAlerts\Tests\Support\DriftAlertFixture;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-20 09:00:00');
    $this->user = DriftAlertFixture::user('lmr');
    $this->actingAs($this->user);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// The cursor made page 2 the ONLY page: 30 acknowledged alerts showed 26, then
// "Load more" showed the remaining 4 and the first 26 disappeared, with no
// "previous" control to get them back.
it('keeps the rows already shown when load more is pressed', function (): void {
    $names = [];
    for ($i = 0; $i < 30; $i++) {
        $names[] = DriftAlertFixture::alert($this->user, [
            'state' => DriftAlertState::Acknowledged->value,
            'actioned_at' => CarbonImmutable::now(),
        ])->id;
    }

    $component = Livewire::test(DriftPage::class)
        ->set('tab', DriftPageTab::History->value);

    expect($component->viewData('rows'))->toHaveCount(26);

    $component->call('loadMore');

    expect($component->viewData('rows'))->toHaveCount(30);
    expect($names)->toHaveCount(30);
});

it('resets back to one page when the tab changes', function (): void {
    for ($i = 0; $i < 30; $i++) {
        DriftAlertFixture::alert($this->user, [
            'state' => DriftAlertState::Acknowledged->value,
            'actioned_at' => CarbonImmutable::now(),
        ]);
    }

    $component = Livewire::test(DriftPage::class)
        ->set('tab', DriftPageTab::History->value)
        ->call('loadMore');

    expect($component->viewData('rows'))->toHaveCount(30);

    $component->call('setTab', DriftPageTab::Dismissed->value)
        ->call('setTab', DriftPageTab::History->value);

    expect($component->viewData('rows'))->toHaveCount(26);
});

// The Open tab renders the grouped projection, so the keyset page it also ran
// was thrown away on every render — and the grouped read was unbounded.
it('bounds the open tab and does not run the flat page it never renders', function (): void {
    for ($i = 0; $i < 30; $i++) {
        DriftAlertFixture::alert($this->user);
    }

    $component = Livewire::test(DriftPage::class)
        ->set('tab', DriftPageTab::Open->value);

    expect($component->viewData('rows'))->toBe([]);
    expect($component->viewData('grouped'))->toHaveCount(26);

    $component->call('loadMore');

    expect($component->viewData('grouped'))->toHaveCount(30);
});

it('bounds groupedBySeriesForUser at the series limit it is given', function (): void {
    for ($i = 0; $i < 8; $i++) {
        DriftAlertFixture::alert($this->user);
    }

    expect(app(DriftAlertQuery::class)->groupedBySeriesForUser($this->user, 3))->toHaveCount(3);
});
