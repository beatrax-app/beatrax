<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Event;
use Modules\Chains\Internal\Resolvers\IcsSettlementResolver;
use Modules\Chains\Models\CardStatement;
use Modules\Chains\Models\ChainLink;
use Modules\Chains\Public\Services\ChainLinkQuery;
use Modules\Core\Models\User;
use Modules\Import\Database\Seeders\DefaultKnownCounterpartyIbansSeeder;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Sync\Public\Events\EntityMutated;

// The exceeded-tolerance hint says a bulk settlement does not add up against
// the charges the ledger holds. Import the missing charges and it does, and the
// next pass settles the statement — but nothing cleared the hint, so
// /chains/hints went on quoting a shortfall the ledger had since closed.

function hintGapUser(): User
{
    return User::query()->create([
        'username' => 'hint-gap-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
}

function hintGapExpense(object $ctx, int $absMinor, int $rowIndex, string $day): Transaction
{
    return Transaction::query()->create([
        'user_id' => $ctx->user->id,
        'account_id' => $ctx->ics->id,
        'type' => 'expense',
        'posted_at' => '2026-05-'.$day,
        'booked_at' => '2026-05-'.$day.' 12:00:00',
        'value_date' => '2026-05-'.$day,
        'amount_minor' => -$absMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => -$absMinor,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Merchant '.$rowIndex,
        'counterparty_normalized' => 'merchant-'.$rowIndex,
        'normalization_version' => 3,
        'source_format' => 'ics-pdf',
        'import_run_id' => $ctx->icsRun->id,
        'source_row_index' => $rowIndex,
        'fingerprint' => hash('sha256', 'hint-gap-exp-'.$ctx->user->id.'-'.$rowIndex),
        'fingerprint_version' => 3,
    ]);
}

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
    $this->user = hintGapUser();

    $this->ics = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Hint gap card',
        'slug' => 'hint-gap-card-'.bin2hex(random_bytes(3)),
        'kind' => 'ics_card',
        'iban' => 'ICS-HINT-GAP',
        'default_currency' => 'EUR',
    ]);
    $this->bank = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'Hint gap bank',
        'slug' => 'hint-gap-bank-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);

    app(DefaultKnownCounterpartyIbansSeeder::class)->run($this->user);

    $this->icsRun = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/hint-gap.pdf',
        'sha256' => hash('sha256', 'hint-gap-ics-'.$this->user->id),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);
    $this->asnRun = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/hint-gap.csv',
        'sha256' => hash('sha256', 'hint-gap-asn-'.$this->user->id),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $statement = CardStatement::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->ics->id,
        'import_run_id' => $this->icsRun->id,
        'period_start' => '2026-05-01 00:00:00',
        'period_end' => '2026-05-31 23:59:59',
        'total_amount_minor' => -60000,
        'open_balance_minor' => 60000,
        'currency' => 'EUR',
        'state' => 'open',
    ]);
    $this->statementId = (int) $statement->id;

    // Ten of the fifteen charges the statement covers are in the ledger; the
    // rest have not been imported yet.
    for ($i = 1; $i <= 10; $i++) {
        hintGapExpense($this, 4000, $i, str_pad((string) $i, 2, '0', STR_PAD_LEFT));
    }

    Transaction::query()->create([
        'user_id' => $this->user->id,
        'account_id' => $this->bank->id,
        'type' => 'transfer_out',
        'posted_at' => '2026-05-29',
        'booked_at' => '2026-05-29 12:00:00',
        'value_date' => '2026-05-29',
        'amount_minor' => -60000,
        'currency' => 'EUR',
        'settled_amount_minor' => -60000,
        'settled_currency' => 'EUR',
        'counterparty_iban' => 'NL08ABNA0526650664',
        'counterparty_name' => 'ICS Bulk Settlement',
        'counterparty_normalized' => 'ics-bulk-settlement',
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $this->asnRun->id,
        'source_row_index' => 9999,
        'fingerprint' => hash('sha256', 'hint-gap-transfer-'.$this->user->id),
        'fingerprint_version' => 3,
    ]);

    /** @var IcsSettlementResolver $resolver */
    $resolver = $this->app->make(IcsSettlementResolver::class);
    $this->resolver = $resolver;

    /** @var ChainLinkQuery $query */
    $query = $this->app->make(ChainLinkQuery::class);
    $this->query = $query;
});

it('clears the exceeded-tolerance hint once the missing charges arrive and the statement settles', function (): void {
    $this->resolver->resolveForUser($this->user);

    // Ten of the fifteen charges are in: EUR 400.00 short of the EUR 600.00
    // paid, which is well outside the EUR 12.00 the tolerance allows.
    expect($this->query->hintCount($this->user))->toBe(1);
    expect(CardStatement::query()->findOrFail($this->statementId)->state)->toBe('open');

    for ($i = 11; $i <= 15; $i++) {
        hintGapExpense($this, 4000, $i, str_pad((string) $i, 2, '0', STR_PAD_LEFT));
    }

    $this->resolver->resolveForUser($this->user);

    expect(CardStatement::query()->findOrFail($this->statementId)->state)->toBe('settled');
    expect($this->query->hintCount($this->user))->toBe(0);
    expect($this->query->hintsForReview($this->user))->toBe([]);
    expect(ChainLink::query()
        ->where('user_id', $this->user->id)
        ->whereNull('to_transaction_id')
        ->count())->toBe(0);
});

// The dismissal a peer needs is the tombstone: without it the hint this device
// cleared is still sitting in the other device's queue.
it('tells a peer the hint is gone', function (): void {
    $this->resolver->resolveForUser($this->user);

    $hint = ChainLink::query()
        ->where('user_id', $this->user->id)
        ->whereNull('to_transaction_id')
        ->firstOrFail();

    /** @var list<EntityMutated> $captured */
    $captured = [];
    Event::listen(function (EntityMutated $event) use (&$captured): void {
        $captured[] = $event;
    });

    for ($i = 11; $i <= 15; $i++) {
        hintGapExpense($this, 4000, $i, str_pad((string) $i, 2, '0', STR_PAD_LEFT));
    }

    $this->resolver->resolveForUser($this->user);

    $deletes = array_values(array_filter(
        $captured,
        static fn (EntityMutated $event): bool => $event->table === 'chain_links' && $event->mutationType === 'delete',
    ));

    expect($deletes)->toHaveCount(1);
    expect($deletes[0]->pk)->toBe((int) $hint->id);
    expect($deletes[0]->userId)->toBe((int) $this->user->id);
});

// A hint whose gap has NOT closed is still the reader's to decide on: a second
// pass over the same ledger must leave it exactly where it was.
it('leaves the hint alone while the charges are still missing', function (): void {
    $this->resolver->resolveForUser($this->user);
    $this->resolver->resolveForUser($this->user);

    expect($this->query->hintCount($this->user))->toBe(1);
    expect(CardStatement::query()->findOrFail($this->statementId)->state)->toBe('open');
});
