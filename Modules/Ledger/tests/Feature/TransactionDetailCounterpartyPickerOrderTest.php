<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Ledger\Internal\Http\Livewire\TransactionDetail;
use Modules\Ledger\Models\Account;

// The reassignment picker has no search box, so its order is the only way to
// find a merchant in it. It sorted the decrypted names by bytes, which files
// every accented one after Z — LocaleCollator is the seam the account and
// budget lists already order through.

it('orders the reassignment picker by the reader alphabet', function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);

    $account = Account::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('iban', 'NL57ASNB0123456789')
        ->firstOrFail();

    $now = now()->toDateTimeString();
    foreach (['Zeta Zaken', 'Ångström AB', 'Émile Fleurs', 'Alpha BV'] as $index => $name) {
        DB::table('counterparties')->insert([
            'user_id' => $this->fixtureUser->id,
            'type' => 'merchant',
            'slug' => 'tdpo-'.$index,
            'display_name' => $name,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    $transaction = $this->makeTransaction(
        $this->fixtureUser,
        $account,
        $this->makeImportRun($this->fixtureUser),
        ['type' => 'expense', 'amount_minor' => -2500],
    );

    $rows = Livewire::test(TransactionDetail::class, ['transactionId' => $transaction->id])
        ->viewData('counterparties');

    expect($rows->pluck('display_name')->all())
        ->toBe(['Alpha BV', 'Ångström AB', 'Émile Fleurs', 'Zeta Zaken']);
});
