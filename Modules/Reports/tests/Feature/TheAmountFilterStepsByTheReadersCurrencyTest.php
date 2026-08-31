<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Reports\Internal\Http\Livewire\ReportBuilder;

uses(RefreshDatabase::class);

// The report builder reuses the search filter language verbatim, including the
// hundredth step that a yen reader's own bound parser refuses.

function reportFilterUser(string $currency): User
{
    return User::create([
        'username' => 'report-filter-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'base_currency' => $currency,
    ]);
}

it('steps a yen report amount filter by whole yen', function (): void {
    $html = Livewire::actingAs(reportFilterUser(Currency::Jpy->value))
        ->test(ReportBuilder::class)
        ->html();

    expect($html)->toContain('step="1"')
        ->and($html)->not->toContain('step="0.01"');
});

it('still steps a euro report amount filter by cents', function (): void {
    $html = Livewire::actingAs(reportFilterUser(Currency::Eur->value))
        ->test(ReportBuilder::class)
        ->html();

    expect($html)->toContain('step="0.01"');
});
