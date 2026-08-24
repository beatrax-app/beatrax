<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Services\BaseCurrency;

// One render of a money-heavy page reaches BaseCurrency about a hundred times.
// Reading the setting has to cost the same as reading it once, or the /settings
// picker buys a hundred queries per page.

beforeEach(function (): void {
    $this->reader = User::create([
        'username' => 'onceper-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'base_currency' => Currency::Usd->value,
    ]);
    $this->actingAs($this->reader);
});

it('hands the whole render one resolver rather than one per figure', function (): void {
    expect(app(BaseCurrency::class))->toBe(app(BaseCurrency::class));
});

it('costs a hundred figures no more queries than one figure', function (): void {
    DB::flushQueryLog();
    DB::enableQueryLog();

    $first = BaseCurrency::value();
    $afterOne = count(DB::getQueryLog());

    for ($i = 0; $i < 100; $i++) {
        BaseCurrency::value();
    }

    $afterAHundred = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($first)->toBe(Currency::Usd->value)
        ->and($afterAHundred)->toBe($afterOne);
});
