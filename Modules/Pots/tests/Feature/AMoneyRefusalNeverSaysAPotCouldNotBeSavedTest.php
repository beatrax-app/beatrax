<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Core\Public\Support\Lang;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Pots\Internal\Http\Livewire\PotsPage;
use Modules\Pots\Models\Pot;
use Modules\Pots\Public\Services\PotWriter;

beforeEach(function (): void {
    $this->user = User::create([
        'username' => 'wessel',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/mns.xml',
        'sha256' => str_repeat('m', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->writer = app(PotWriter::class);
});

function mnsCredit(int $userId, int $accountId, int $runId, int $amountMinor): void
{
    static $i = 0;
    $i++;

    Transaction::create([
        'user_id' => $userId,
        'account_id' => $accountId,
        'type' => 'transfer_in',
        'posted_at' => CarbonImmutable::now()->toDateString(),
        'booked_at' => CarbonImmutable::now()->toDateString().' 12:00:00',
        'value_date' => CarbonImmutable::now()->toDateString(),
        'amount_minor' => $amountMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => $amountMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => "MNS{$i}",
        'counterparty_normalized' => "mns{$i}",
        'normalization_version' => 1,
        'category_id' => null,
        'source_format' => 'camt053',
        'import_run_id' => $runId,
        'source_row_index' => $i + 9400,
        'fingerprint' => str_pad('mns'.$i, 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);
}

function mnsPot(int $userId, int $accountId, string $name = 'Pot'): Pot
{
    /** @var Pot $pot */
    $pot = Pot::query()->withoutGlobalScope(UserScope::class)->create([
        'user_id' => $userId,
        'account_id' => $accountId,
        'goal_id' => null,
        'category_id' => null,
        'name' => $name,
        'currency' => 'EUR',
        'status' => 'active',
    ]);

    return $pot;
}

/** @return array<string, string> handler name => its body */
function mnsMoneyHandlers(): array
{
    $source = (string) file_get_contents(
        base_path('Modules/Pots/Internal/Http/Livewire/PotsPage.php')
    );

    $bodies = [];
    foreach (['fundPot', 'withdrawPot', 'movePot'] as $handler) {
        $start = strpos($source, "public function {$handler}(");
        expect($start)->not->toBeFalse();

        $end = strpos($source, "\n    public function ", (int) $start + 1);
        $bodies[$handler] = substr($source, (int) $start, ($end === false ? strlen($source) : $end) - (int) $start);
    }

    return $bodies;
}

// The string is written for the create/edit form and says so. Reused in a
// handler that moves money, it describes an operation the reader was not
// performing and sends them back to fields that were all correct.
it('never answers with the form save wording', function (string $handler): void {
    expect(mnsMoneyHandlers()[$handler])->not->toContain('errors.generic');
})->with(['fundPot', 'withdrawPot', 'movePot']);

it('says a pot being funded is gone rather than blaming the fields', function (): void {
    Livewire::actingAs($this->user)->test(PotsPage::class)
        ->set('operationPotId', 999999)
        ->set('operationAmount', '10,00')
        ->call('fundPot')
        ->assertDispatched('toast', message: Lang::get('pots::messages.errors.pot_missing'))
        ->assertDispatched('modal-close');
});

it('says a pot being withdrawn from is gone rather than blaming the fields', function (): void {
    Livewire::actingAs($this->user)->test(PotsPage::class)
        ->set('operationPotId', 999999)
        ->set('operationAmount', '10,00')
        ->call('withdrawPot')
        ->assertDispatched('toast', message: Lang::get('pots::messages.errors.pot_missing'))
        ->assertDispatched('modal-close');
});

it('says a pot being moved out of is gone rather than blaming the fields', function (): void {
    mnsCredit($this->user->id, $this->account->id, $this->run->id, 50000);
    $target = mnsPot($this->user->id, $this->account->id, 'Target');

    Livewire::actingAs($this->user)->test(PotsPage::class)
        ->set('operationPotId', 999999)
        ->set('transferTargetPotId', (string) $target->id)
        ->set('operationAmount', '10,00')
        ->call('movePot')
        ->assertDispatched('toast', message: Lang::get('pots::messages.errors.pot_missing'))
        ->assertDispatched('modal-close');
});

// The initial amount is the create form's own money field, and an unreadable
// one used to arrive under the name box as "check the fields" — the amount box
// beside it is the field that is wrong, and it already has a sentence.
it('puts an unreadable initial amount under the amount box', function (): void {
    Livewire::actingAs($this->user)->test(PotsPage::class)
        ->set('name', 'Holiday')
        ->set('accountId', (string) $this->account->id)
        ->set('amount', 'not-a-number')
        ->call('createPot')
        ->assertSet('errorAmount', Lang::get('pots::messages.errors.amount_invalid'))
        ->assertSet('errorName', '');
});
