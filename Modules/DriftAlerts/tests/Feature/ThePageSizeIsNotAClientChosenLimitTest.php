<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Modules\DriftAlerts\Internal\Http\Livewire\DriftPage;
use Modules\DriftAlerts\Public\Enums\DriftAlertState;
use Modules\DriftAlerts\Public\Enums\DriftPageTab;
use Modules\DriftAlerts\Tests\Support\DriftAlertFixture;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-05-20 09:00:00');
    $this->user = DriftAlertFixture::user('pagesize');
    $this->actingAs($this->user);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// pageSize is a SQL LIMIT. A public property is client-controlled, so the page
// grew only through loadMore(); a written value reached the query untouched and
// a negative one asked for LIMIT 0 — an empty page with no control back.

it('refuses a client-written page size', function (): void {
    DriftAlertFixture::alert($this->user, [
        'state' => DriftAlertState::Acknowledged->value,
        'actioned_at' => CarbonImmutable::now(),
    ]);

    $component = Livewire::test(DriftPage::class)
        ->set('tab', DriftPageTab::History->value);

    expect(fn () => $component->set('pageSize', 500_000_000))
        ->toThrow(CannotUpdateLockedPropertyException::class);
    expect($component->get('pageSize'))->toBe(26);
});

it('refuses a negative page size that would render an empty page', function (): void {
    DriftAlertFixture::alert($this->user, [
        'state' => DriftAlertState::Acknowledged->value,
        'actioned_at' => CarbonImmutable::now(),
    ]);

    $component = Livewire::test(DriftPage::class)
        ->set('tab', DriftPageTab::History->value);

    expect(fn () => $component->set('pageSize', -1))
        ->toThrow(CannotUpdateLockedPropertyException::class);
    expect($component->get('pageSize'))->toBe(26);
    expect($component->viewData('rows'))->toHaveCount(1);
});

it('still grows through loadMore', function (): void {
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
});
