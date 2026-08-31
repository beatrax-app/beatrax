<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Transfers\Public\Contracts\PairsTransferLegs;
use Modules\Transfers\Public\Enums\CounterLegOrder;
use Modules\Transfers\Public\Services\PairLookup;
use Modules\Transfers\Public\Support\CounterLegMatch;
use Modules\Transfers\Public\Support\CounterLegWindow;

// The forward arm's candidate set and its ordering, pinned row by row. Every
// case here was written and run green against the hand-rolled query inside
// TransferPairer, before PairLookup grew the parameters to express the same
// ask: a swap that changes which leg pairs fails here, not on a dashboard.

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'counter-leg-pin',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $this->asn = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN counter-leg pin',
        'slug' => 'clp-pin-asn',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    $this->ics = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ICS counter-leg pin',
        'slug' => 'clp-pin-ics',
        'kind' => 'ics_card',
        'iban' => 'ICS-CARD',
        'default_currency' => 'EUR',
    ]);

    $this->run = ImportRun::create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/counter-leg-pin.csv',
        'sha256' => str_repeat('n', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    /** @var PairsTransferLegs $pairer */
    $pairer = $this->app->make(PairsTransferLegs::class);
    $this->pairer = $pairer;

    /** @var PairLookup $lookup */
    $lookup = $this->app->make(PairLookup::class);
    $this->lookup = $lookup;
});

/**
 * @param  array<string, mixed>  $overrides
 */
function counterLegPinTx(User $user, Account $account, ImportRun $run, array $overrides = []): Transaction
{
    static $rowIndex = 0;
    $rowIndex++;

    return Transaction::query()->create(array_merge([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => TransactionType::TransferIn->value,
        'posted_at' => '2026-05-15',
        'booked_at' => '2026-05-15 12:00:00',
        'value_date' => '2026-05-15',
        'amount_minor' => 1000,
        'currency' => 'EUR',
        'settled_amount_minor' => 1000,
        'settled_currency' => 'EUR',
        'counterparty_iban' => null,
        'counterparty_name' => 'Counter leg '.$rowIndex,
        // Varied per row so two fixture legs may share account, date and amount
        // without colliding on the transactions_fingerprint_uq tuple.
        'counterparty_normalized' => 'counter-leg-'.$rowIndex,
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => $rowIndex,
        'fingerprint' => str_pad((string) $rowIndex, 64, 'n', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ], $overrides));
}

it('takes the earliest candidate by booked_at, not the one nearest the firing leg', function (): void {
    // Created first, so it also holds the lower id: neither booked_at order nor
    // id order can be mistaken for the other in the result.
    $nearer = counterLegPinTx($this->user, $this->ics, $this->run, [
        'amount_minor' => 4400,
        'settled_amount_minor' => 4400,
        'booked_at' => '2026-05-16 12:00:00',
        'posted_at' => '2026-05-16',
    ]);
    $earlier = counterLegPinTx($this->user, $this->ics, $this->run, [
        'amount_minor' => 4400,
        'settled_amount_minor' => 4400,
        'booked_at' => '2026-05-13 12:00:00',
        'posted_at' => '2026-05-13',
    ]);

    $firing = counterLegPinTx($this->user, $this->asn, $this->run, [
        'type' => TransactionType::TransferOut->value,
        'amount_minor' => -4400,
        'settled_amount_minor' => -4400,
        'counterparty_iban' => 'ICS-CARD',
    ]);

    expect($this->pairer->pairOne($firing, $this->user))->toBe($earlier->id);
    expect($nearer->refresh()->pair_transaction_id)->toBeNull();
});

it('breaks a booked_at tie on the lower id', function (): void {
    $first = counterLegPinTx($this->user, $this->ics, $this->run, [
        'amount_minor' => 5500,
        'settled_amount_minor' => 5500,
        'booked_at' => '2026-05-16 09:00:00',
        'posted_at' => '2026-05-16',
    ]);
    $second = counterLegPinTx($this->user, $this->ics, $this->run, [
        'amount_minor' => 5500,
        'settled_amount_minor' => 5500,
        'booked_at' => '2026-05-16 09:00:00',
        'posted_at' => '2026-05-16',
    ]);

    $firing = counterLegPinTx($this->user, $this->asn, $this->run, [
        'type' => TransactionType::TransferOut->value,
        'amount_minor' => -5500,
        'settled_amount_minor' => -5500,
        'counterparty_iban' => 'ICS-CARD',
    ]);

    expect($this->pairer->pairOne($firing, $this->user))->toBe($first->id);
    expect($second->refresh()->pair_transaction_id)->toBeNull();
});

it('skips a candidate in another currency even when it sits nearer and earlier', function (): void {
    $foreign = counterLegPinTx($this->user, $this->ics, $this->run, [
        'amount_minor' => 6600,
        'settled_amount_minor' => 6600,
        'currency' => 'USD',
        'settled_currency' => 'USD',
        'booked_at' => '2026-05-14 12:00:00',
        'posted_at' => '2026-05-14',
    ]);
    $matching = counterLegPinTx($this->user, $this->ics, $this->run, [
        'amount_minor' => 6600,
        'settled_amount_minor' => 6600,
        'booked_at' => '2026-05-17 12:00:00',
        'posted_at' => '2026-05-17',
    ]);

    $firing = counterLegPinTx($this->user, $this->asn, $this->run, [
        'type' => TransactionType::TransferOut->value,
        'amount_minor' => -6600,
        'settled_amount_minor' => -6600,
        'counterparty_iban' => 'ICS-CARD',
    ]);

    expect($this->pairer->pairOne($firing, $this->user))->toBe($matching->id);
    expect($foreign->refresh()->pair_transaction_id)->toBeNull();
});

it('skips a candidate that already carries a partner', function (): void {
    $filler = counterLegPinTx($this->user, $this->ics, $this->run, [
        'amount_minor' => 111,
        'settled_amount_minor' => 111,
    ]);
    $taken = counterLegPinTx($this->user, $this->ics, $this->run, [
        'amount_minor' => 7700,
        'settled_amount_minor' => 7700,
        'booked_at' => '2026-05-14 12:00:00',
        'posted_at' => '2026-05-14',
        'pair_transaction_id' => $filler->id,
    ]);
    $free = counterLegPinTx($this->user, $this->ics, $this->run, [
        'amount_minor' => 7700,
        'settled_amount_minor' => 7700,
        'booked_at' => '2026-05-17 12:00:00',
        'posted_at' => '2026-05-17',
    ]);

    $firing = counterLegPinTx($this->user, $this->asn, $this->run, [
        'type' => TransactionType::TransferOut->value,
        'amount_minor' => -7700,
        'settled_amount_minor' => -7700,
        'counterparty_iban' => 'ICS-CARD',
    ]);

    expect($this->pairer->pairOne($firing, $this->user))->toBe($free->id);
    expect($taken->refresh()->pair_transaction_id)->toBe($filler->id);
});

it('skips a candidate whose type is not a transfer leg', function (): void {
    $income = counterLegPinTx($this->user, $this->ics, $this->run, [
        'type' => TransactionType::Income->value,
        'amount_minor' => 8800,
        'settled_amount_minor' => 8800,
        'booked_at' => '2026-05-14 12:00:00',
        'posted_at' => '2026-05-14',
    ]);
    $transfer = counterLegPinTx($this->user, $this->ics, $this->run, [
        'amount_minor' => 8800,
        'settled_amount_minor' => 8800,
        'booked_at' => '2026-05-17 12:00:00',
        'posted_at' => '2026-05-17',
    ]);

    $firing = counterLegPinTx($this->user, $this->asn, $this->run, [
        'type' => TransactionType::TransferOut->value,
        'amount_minor' => -8800,
        'settled_amount_minor' => -8800,
        'counterparty_iban' => 'ICS-CARD',
    ]);

    expect($this->pairer->pairOne($firing, $this->user))->toBe($transfer->id);
    expect($income->refresh()->pair_transaction_id)->toBeNull();
});

it('accepts either transfer type as a candidate, not only the opposite of the firing leg', function (): void {
    $sameDirection = counterLegPinTx($this->user, $this->ics, $this->run, [
        'type' => TransactionType::TransferOut->value,
        'amount_minor' => 9900,
        'settled_amount_minor' => 9900,
        'booked_at' => '2026-05-14 12:00:00',
        'posted_at' => '2026-05-14',
    ]);
    $oppositeDirection = counterLegPinTx($this->user, $this->ics, $this->run, [
        'amount_minor' => 9900,
        'settled_amount_minor' => 9900,
        'booked_at' => '2026-05-17 12:00:00',
        'posted_at' => '2026-05-17',
    ]);

    $firing = counterLegPinTx($this->user, $this->asn, $this->run, [
        'type' => TransactionType::TransferOut->value,
        'amount_minor' => -9900,
        'settled_amount_minor' => -9900,
        'counterparty_iban' => 'ICS-CARD',
    ]);

    expect($this->pairer->pairOne($firing, $this->user))->toBe($sameDirection->id);
    expect($oppositeDirection->refresh()->pair_transaction_id)->toBeNull();
});

it('refuses to pair a zero-amount leg with itself when its counterparty IBAN is its own account', function (): void {
    // Its own negation is its own amount, so nothing about the row itself
    // separates it from the partner it is looking for.
    $selfReferential = counterLegPinTx($this->user, $this->asn, $this->run, [
        'type' => TransactionType::TransferOut->value,
        'amount_minor' => 0,
        'settled_amount_minor' => 0,
        'counterparty_iban' => 'NL57ASNB0123456789',
    ]);

    expect($this->pairer->pairOne($selfReferential, $this->user))->toBeNull();
    expect($selfReferential->refresh()->pair_transaction_id)->toBeNull();
});

it('leaves a candidate outside the window alone even when nothing else is on offer', function (): void {
    counterLegPinTx($this->user, $this->ics, $this->run, [
        'amount_minor' => 2200,
        'settled_amount_minor' => 2200,
        'booked_at' => '2026-05-19 00:00:00',
        'posted_at' => '2026-05-19',
    ]);

    $firing = counterLegPinTx($this->user, $this->asn, $this->run, [
        'type' => TransactionType::TransferOut->value,
        'amount_minor' => -2200,
        'settled_amount_minor' => -2200,
        'counterparty_iban' => 'ICS-CARD',
    ]);

    expect($this->pairer->pairOne($firing, $this->user))->toBeNull();
});

it('reaches PairLookup for the forward arm rather than keeping a second copy of the search', function (): void {
    $source = (string) file_get_contents(base_path('Modules/Transfers/Internal/Services/TransferPairer.php'));

    expect(str_contains($source, PairLookup::class))->toBeTrue(
        'The forward arm still builds its own counter-leg SELECT. Two copies of one query is how '
        .'the predicates and the ordering drift apart without anyone choosing that.',
    );
});

it('pairs the very leg PairLookup returns when asked the forward arm\'s question', function (): void {
    $nearer = counterLegPinTx($this->user, $this->ics, $this->run, [
        'amount_minor' => 3300,
        'settled_amount_minor' => 3300,
        'booked_at' => '2026-05-16 12:00:00',
        'posted_at' => '2026-05-16',
    ]);
    $earlier = counterLegPinTx($this->user, $this->ics, $this->run, [
        'amount_minor' => 3300,
        'settled_amount_minor' => 3300,
        'booked_at' => '2026-05-13 12:00:00',
        'posted_at' => '2026-05-13',
    ]);

    $firing = counterLegPinTx($this->user, $this->asn, $this->run, [
        'type' => TransactionType::TransferOut->value,
        'amount_minor' => -3300,
        'settled_amount_minor' => -3300,
        'counterparty_iban' => 'ICS-CARD',
    ]);

    $viaLookup = $this->lookup->counterLegOnAccount(
        new CounterLegMatch(
            accountId: $this->ics->id,
            amountMinor: 3300,
            types: [TransactionType::TransferOut, TransactionType::TransferIn],
            currency: 'EUR',
            unpairedOnly: true,
            excludeTransactionId: $firing->id,
        ),
        new CounterLegWindow(CarbonImmutable::parse('2026-05-15 12:00:00'), 3, CounterLegOrder::EarliestBooked),
        $this->user,
    );

    expect($viaLookup)->toBe($earlier->id);
    expect($this->pairer->pairOne($firing, $this->user))->toBe($viaLookup);
    expect($nearer->refresh()->pair_transaction_id)->toBeNull();
});

it('orders nearest-to-centre ahead of earliest for the chain-resolution caller', function (): void {
    $earlier = counterLegPinTx($this->user, $this->asn, $this->run, [
        'amount_minor' => 4400,
        'settled_amount_minor' => 4400,
        'booked_at' => '2026-05-13 12:00:00',
        'posted_at' => '2026-05-13',
    ]);
    $nearer = counterLegPinTx($this->user, $this->asn, $this->run, [
        'amount_minor' => 4400,
        'settled_amount_minor' => 4400,
        'booked_at' => '2026-05-16 12:00:00',
        'posted_at' => '2026-05-16',
    ]);

    expect($this->lookup->counterLegOnAccount(
        new CounterLegMatch(
            accountId: $this->asn->id,
            amountMinor: 4400,
            types: [TransactionType::TransferIn],
            currency: null,
            unpairedOnly: false,
            excludeTransactionId: null,
        ),
        new CounterLegWindow(CarbonImmutable::parse('2026-05-15 12:00:00'), 3, CounterLegOrder::NearestToCentre),
        $this->user,
    ))->toBe($nearer->id, 'earlier id '.$earlier->id.', nearer id '.$nearer->id);
});

it('settles an equidistant pair on the earlier booked_at, whichever row was imported first', function (): void {
    $before = counterLegPinTx($this->user, $this->asn, $this->run, [
        'amount_minor' => 5500,
        'settled_amount_minor' => 5500,
        'booked_at' => '2026-05-14 12:00:00',
        'posted_at' => '2026-05-14',
    ]);
    counterLegPinTx($this->user, $this->asn, $this->run, [
        'amount_minor' => 5500,
        'settled_amount_minor' => 5500,
        'booked_at' => '2026-05-16 12:00:00',
        'posted_at' => '2026-05-16',
    ]);

    $laterFirst = counterLegPinTx($this->user, $this->asn, $this->run, [
        'amount_minor' => 6600,
        'settled_amount_minor' => 6600,
        'booked_at' => '2026-05-16 12:00:00',
        'posted_at' => '2026-05-16',
    ]);
    $earlierSecond = counterLegPinTx($this->user, $this->asn, $this->run, [
        'amount_minor' => 6600,
        'settled_amount_minor' => 6600,
        'booked_at' => '2026-05-14 12:00:00',
        'posted_at' => '2026-05-14',
    ]);

    $ask = fn (int $amountMinor): ?int => $this->lookup->counterLegOnAccount(
        new CounterLegMatch(
            accountId: $this->asn->id,
            amountMinor: $amountMinor,
            types: [TransactionType::TransferIn],
            currency: null,
            unpairedOnly: false,
            excludeTransactionId: null,
        ),
        new CounterLegWindow(CarbonImmutable::parse('2026-05-15 12:00:00'), 3, CounterLegOrder::NearestToCentre),
        $this->user,
    );

    expect($ask(5500))->toBe($before->id);
    expect($ask(6600))->toBe($earlierSecond->id, 'later-but-first id '.$laterFirst->id);
});

it('settles two rows sharing a booked_at on the lower id for the chain-resolution caller', function (): void {
    $first = counterLegPinTx($this->user, $this->asn, $this->run, [
        'amount_minor' => 7700,
        'settled_amount_minor' => 7700,
        'booked_at' => '2026-05-16 09:00:00',
        'posted_at' => '2026-05-16',
    ]);
    counterLegPinTx($this->user, $this->asn, $this->run, [
        'amount_minor' => 7700,
        'settled_amount_minor' => 7700,
        'booked_at' => '2026-05-16 09:00:00',
        'posted_at' => '2026-05-16',
    ]);

    expect($this->lookup->counterLegOnAccount(
        new CounterLegMatch(
            accountId: $this->asn->id,
            amountMinor: 7700,
            types: [TransactionType::TransferIn],
            currency: null,
            unpairedOnly: false,
            excludeTransactionId: null,
        ),
        new CounterLegWindow(CarbonImmutable::parse('2026-05-15 12:00:00'), 3, CounterLegOrder::NearestToCentre),
        $this->user,
    ))->toBe($first->id);
});
