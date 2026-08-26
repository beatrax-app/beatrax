<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Modules\Ledger\Internal\Http\Livewire\TransactionDetail;
use Modules\Ledger\Models\Account;
use Modules\Sync\Public\Events\TransactionMutated;

// Read on an iPhone 12 mini at /transactions/8: the page offers "Reclassify —
// Override the detected type" over a select that opens on "Choose a type…" and
// omits the current type from its own list. The detected type is stated
// nowhere on the page, so the only way to read it is to notice which option is
// missing.

beforeEach(function (): void {
    $this->seedFixtureUserAndAccount();
    $this->actingAs($this->fixtureUser);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-15 12:00:00'));
    Event::fake([TransactionMutated::class]);

    /** @var Account $account */
    $account = Account::query()
        ->where('user_id', $this->fixtureUser->id)
        ->where('iban', 'NL57ASNB0123456789')
        ->firstOrFail();
    $this->account = $account;
    $this->run = $this->makeImportRun($this->fixtureUser);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('states the type the reclassify control offers to override', function (): void {
    $tx = $this->makeTransaction($this->fixtureUser, $this->account, $this->run, [
        'type' => 'income',
        'amount_minor' => 50000,
        'posted_at' => '2026-05-10',
        'booked_at' => '2026-05-10 12:00:00',
        'counterparty_name' => 'Nordwind Media BV',
    ]);

    $html = Livewire::test(TransactionDetail::class, ['transactionId' => $tx->id])->html();

    expect($html)->toMatch('/data-testid="tx-detail-type"[^>]*>\s*Income\s*</');
});
