<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Pots\Internal\Http\Livewire\PotsPage;
use Modules\Pots\Models\Pot;

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'yenclear-'.bin2hex(random_bytes(4)),
        'password' => 'opensesame',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'Japan Trip Bank',
        'slug' => 'yenclear-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'JP57ASNB'.strtoupper(bin2hex(random_bytes(5))),
        'default_currency' => Currency::Jpy->value,
    ]);

    $run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/yenclear.xml',
        'sha256' => hash('sha256', 'yenclear-'.bin2hex(random_bytes(6))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    // ¥120,000 unallocated, in whole yen.
    Transaction::create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'type' => 'transfer_in',
        'posted_at' => CarbonImmutable::now()->toDateString(),
        'booked_at' => CarbonImmutable::now()->toDateString().' 12:00:00',
        'value_date' => CarbonImmutable::now()->toDateString(),
        'amount_minor' => 120_000,
        'currency' => Currency::Jpy->value,
        'settled_amount_minor' => 120_000,
        'settled_currency' => Currency::Jpy->value,
        'counterparty_name' => 'Bureau de change',
        'counterparty_normalized' => 'bureau de change',
        'normalization_version' => 1,
        'category_id' => null,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => 9102,
        'fingerprint' => str_pad('yenclear', 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    $this->pot = Pot::query()->withoutGlobalScope(UserScope::class)->create([
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'goal_id' => null,
        'category_id' => null,
        'name' => 'Ryokan',
        'currency' => Currency::Jpy->value,
        'status' => 'active',
    ]);
});

it('drops a yen funding refusal once the amount is brought under the ceiling', function (): void {
    Livewire::test(PotsPage::class)
        ->set('operationPotId', $this->pot->id)
        ->set('operationKind', 'fund')
        ->set('operationAmount', '300000')
        ->call('fundPot')
        ->assertSet('errorAmount', fn (string $v): bool => $v !== '')
        ->set('operationAmount', '13840')
        ->assertSet('errorAmount', '');
});

it('funds a yen pot in whole yen through the page', function (): void {
    Livewire::test(PotsPage::class)
        ->set('operationPotId', $this->pot->id)
        ->set('operationKind', 'fund')
        ->set('operationAmount', '13840')
        ->call('fundPot')
        ->assertSet('errorAmount', '');

    expect((int) DB::table('pot_movements')->where('pot_id', $this->pot->id)->sum('amount_minor'))->toBe(13_840);
});
