<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Pots\Models\Pot;
use Modules\Pots\Public\Services\PotWriter;

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'yen-pot',
        'password' => 'opensesame',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'Japan Trip Bank',
        'slug' => 'jpy-bank',
        'kind' => AccountKind::Bank->value,
        'iban' => 'JP11ASNB0000000001',
        'default_currency' => Currency::Jpy->value,
        'starting_balance_minor' => 500_000,
        'starting_balance_date' => '2026-01-01',
    ]);
});

it('stores a yen initial amount as whole yen', function (): void {
    $pot = app(PotWriter::class)->save($this->user, 'Ryokan', '120000', $this->account->id, null, null);

    $moved = app(DatabaseManager::class)->connection()
        ->table('pot_movements')->where('pot_id', $pot->id)->sum('amount_minor');

    expect((int) $moved)->toBe(120_000);
});

it('funds a yen pot in whole yen', function (): void {
    /** @var Pot $pot */
    $pot = app(PotWriter::class)->save($this->user, 'Shinkansen', null, $this->account->id, null, null);

    app(PotWriter::class)->fund($this->user, $pot->id, '13.840');

    $moved = app(DatabaseManager::class)->connection()
        ->table('pot_movements')->where('pot_id', $pot->id)->sum('amount_minor');

    expect((int) $moved)->toBe(13_840);
});
