<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Chains\Public\Http\Livewire\ChainDrawer;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;

// The drawer offers the same Confirm/Reject chips /chains/review does, against
// the same two Public actions, and the same two tabs can race over them. The
// queue answers a gone row with its own error line; the drawer handed the 404
// straight to the browser.

function drawerChipUser(): User
{
    return User::query()->create([
        'username' => 'drawer-chip-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

beforeEach(function (): void {
    $this->user = drawerChipUser();
    $account = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'drawer chip asn',
        'slug' => 'drawer-chip-asn-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);
    $run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/drawer-chip.csv',
        'sha256' => hash('sha256', 'drawer-chip-'.$this->user->id),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
    $this->transaction = Transaction::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'type' => 'expense',
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'value_date' => '2026-05-10',
        'amount_minor' => -1500,
        'currency' => 'EUR',
        'settled_amount_minor' => -1500,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Netflix',
        'counterparty_normalized' => 'netflix',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => 1,
        'fingerprint' => hash('sha256', 'drawer-chip-tx-'.$this->user->id),
        'fingerprint_version' => 3,
    ]);
});

it('answers a drawer confirm on a link that is gone with the drawer error line', function (): void {
    Livewire::actingAs($this->user)
        ->test(ChainDrawer::class)
        ->call('open', (int) $this->transaction->id)
        ->call('confirm', 999999)
        ->assertStatus(200)
        ->assertSet('actionError', Lang::get('core::errors.no_longer_here'))
        ->assertSee(Lang::get('core::errors.no_longer_here'));
});

it('answers a drawer reject on a link that is gone with the drawer error line', function (): void {
    Livewire::actingAs($this->user)
        ->test(ChainDrawer::class)
        ->call('open', (int) $this->transaction->id)
        ->call('reject', 999999)
        ->assertStatus(200)
        ->assertSet('actionError', Lang::get('core::errors.no_longer_here'));
});

it('clears the drawer error line once a later chip lands', function (): void {
    $component = Livewire::actingAs($this->user)
        ->test(ChainDrawer::class)
        ->call('open', (int) $this->transaction->id)
        ->call('confirm', 999999)
        ->assertSet('actionError', Lang::get('core::errors.no_longer_here'));

    $component->call('open', (int) $this->transaction->id)
        ->assertSet('actionError', null);
});
