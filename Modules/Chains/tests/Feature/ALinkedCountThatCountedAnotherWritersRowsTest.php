<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Chains\Internal\Jobs\ResolveChainLinksJob;
use Modules\Chains\Internal\Resolvers\IcsSettlementResolver;
use Modules\Chains\Internal\Resolvers\PaypalFundingResolver;
use Modules\Chains\Internal\Resolvers\RetypeByAliasResolver;
use Modules\Chains\Models\CardStatement;
use Modules\Chains\Models\ChainLink;
use Modules\Chains\Models\ChainResolutionRun;
use Modules\Chains\Public\Contracts\UpsertsCardStatements;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\DeviceMintedRowId;
use Modules\Import\Database\Seeders\DefaultKnownCounterpartyIbansSeeder;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Sync\Public\Events\EntityMutated;
use Modules\Transfers\Public\Contracts\PairsTransferLegs;

uses(RefreshDatabase::class);

// The desktop runs four processes against one SQLite file, and chain_links is
// a synced table: the applier lands a peer's rows in the same window a
// resolution pass is walking. Counting the whole table before and after a pass
// therefore measured every writer's work and attributed it to this one.

/**
 * @return array{user: User, expenses: list<int>, transfer: int}
 */
function anotherWritersRowsFixture(): array
{
    $user = User::query()->create([
        'username' => 'linked-count-user',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $icsAccount = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ICS linked-count fixture',
        'slug' => 'ics-linked-count',
        'kind' => 'ics_card',
        'iban' => 'ICS-CARD',
        'default_currency' => 'EUR',
    ]);
    $icsRun = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'ics-pdf',
        'raw_file_path' => '/tmp/linked-count.pdf',
        'sha256' => str_repeat('l', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    // The 23-expense statement the other job tests settle: one transfer_out
    // closes it, so the pass writes exactly 23 chain_links.
    $totalMinor = 84732;
    $count = 23;
    $each = intdiv($totalMinor, $count);
    $remainder = $totalMinor - ($each * ($count - 1));

    $expenses = [];

    for ($i = 1; $i <= $count; $i++) {
        $absRow = $i === $count ? $remainder : $each;
        $day = str_pad((string) min(28, max(1, $i)), 2, '0', STR_PAD_LEFT);
        $expenses[] = (int) Transaction::query()->create([
            'user_id' => $user->id,
            'account_id' => $icsAccount->id,
            'type' => 'expense',
            'posted_at' => "2026-05-{$day}",
            'booked_at' => "2026-05-{$day} 12:00:00",
            'value_date' => "2026-05-{$day}",
            'amount_minor' => -$absRow,
            'currency' => 'EUR',
            'settled_amount_minor' => -$absRow,
            'settled_currency' => 'EUR',
            'counterparty_name' => 'Merchant '.$i,
            'counterparty_normalized' => 'merchant-'.$i,
            'normalization_version' => 1,
            'source_format' => 'ics-pdf',
            'import_run_id' => $icsRun->id,
            'source_row_index' => $i,
            'fingerprint' => str_pad('lx'.$i, 64, 'l', STR_PAD_LEFT),
            'fingerprint_version' => 3,
        ])->id;
    }

    $bankAccount = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN linked-count bank',
        'slug' => 'asn-linked-count',
        'kind' => 'bank',
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);
    app(DefaultKnownCounterpartyIbansSeeder::class)->run($user);
    $asnRun = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/linked-count.csv',
        'sha256' => str_repeat('m', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $transfer = (int) Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $bankAccount->id,
        'type' => 'transfer_out',
        'posted_at' => '2026-05-29',
        'booked_at' => '2026-05-29 12:00:00',
        'value_date' => '2026-05-29',
        'amount_minor' => -$totalMinor,
        'currency' => 'EUR',
        'settled_amount_minor' => -$totalMinor,
        'settled_currency' => 'EUR',
        'counterparty_iban' => 'NL08ABNA0526650664',
        'counterparty_name' => 'ASN Bulk',
        'counterparty_normalized' => 'asn-bulk',
        'normalization_version' => 1,
        'source_format' => 'asn-csv',
        'import_run_id' => $asnRun->id,
        'source_row_index' => 9999,
        'fingerprint' => str_pad('ltr', 64, 't', STR_PAD_LEFT),
        'fingerprint_version' => 3,
    ])->id;

    CardStatement::query()->create([
        'user_id' => $user->id,
        'account_id' => $icsAccount->id,
        'import_run_id' => $icsRun->id,
        'period_start' => '2026-05-01 00:00:00',
        'period_end' => '2026-05-31 23:59:59',
        'total_amount_minor' => -$totalMinor,
        'open_balance_minor' => $totalMinor,
        'state' => 'open',
    ]);

    return ['user' => $user, 'expenses' => $expenses, 'transfer' => $transfer];
}

// paypal_funding, because both resolver joins read chain_links filtered to
// ics_bulk_settle + confirmed: a row of this kind is invisible to the pass
// and changes nothing about what it decides to write.
function anotherWritersLink(DatabaseManager $db, int $userId, int $fromId, int $toId): void
{
    $db->connection()->table('chain_links')->insert([
        'id' => DeviceMintedRowId::mint(),
        'user_id' => $userId,
        'from_transaction_id' => $fromId,
        'to_transaction_id' => $toId,
        'kind' => 'paypal_funding',
        'state' => 'candidate',
        'confidence' => '0.500',
        'resolver' => 'auto',
        'evidence' => '{}',
        'created_at' => '2026-05-30 00:00:00',
        'updated_at' => '2026-05-30 00:00:00',
    ]);
}

function runTheChainPassForLinkedCount(int $userId): void
{
    $job = new ResolveChainLinksJob($userId);
    $job->handle(
        app(DatabaseManager::class),
        app(Clock::class),
        app(RetypeByAliasResolver::class),
        app(PairsTransferLegs::class),
        app(UpsertsCardStatements::class),
        app(IcsSettlementResolver::class),
        app(PaypalFundingResolver::class),
    );
}

it('records the links the pass wrote, not the row another writer added while it ran', function (): void {
    $fixture = anotherWritersRowsFixture();
    $userId = (int) $fixture['user']->id;

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // The applier's row lands the instant the pass writes its first link,
    // which is exactly the window between the two counts the job used to take.
    $landed = false;
    Event::listen(function (EntityMutated $event) use (&$landed, $db, $userId, $fixture): void {
        if ($landed || $event->table !== 'chain_links') {
            return;
        }
        $landed = true;
        anotherWritersLink($db, $userId, $fixture['transfer'], $fixture['expenses'][0]);
    });

    runTheChainPassForLinkedCount($userId);

    /** @var ChainResolutionRun $run */
    $run = ChainResolutionRun::query()->where('user_id', $userId)->latest('id')->firstOrFail();

    expect($landed)->toBeTrue('the rival writer never ran, so the test proved nothing')
        ->and(ChainLink::query()->where('user_id', $userId)->count())->toBe(24)
        ->and((int) $run->linked_count)->toBe(23);
});

it('records the links the pass wrote, not a count reduced by rows another writer deleted', function (): void {
    $fixture = anotherWritersRowsFixture();
    $userId = (int) $fixture['user']->id;

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // Forty-six rows standing before the pass starts, so a sweep of them under
    // the old arithmetic leaves the run reporting fewer links than none.
    foreach ($fixture['expenses'] as $expenseId) {
        anotherWritersLink($db, $userId, $expenseId, $fixture['transfer']);
        anotherWritersLink($db, $userId, $fixture['transfer'], $expenseId);
    }

    // A transaction deleted on the other device takes its chain_links with it,
    // and the cascade fires with no announcement of its own.
    $swept = false;
    Event::listen(function (EntityMutated $event) use (&$swept, $db, $userId): void {
        if ($swept || $event->table !== 'chain_links') {
            return;
        }
        $swept = true;
        $db->connection()->table('chain_links')
            ->where('user_id', $userId)
            ->where('kind', 'paypal_funding')
            ->delete();
    });

    runTheChainPassForLinkedCount($userId);

    /** @var ChainResolutionRun $run */
    $run = ChainResolutionRun::query()->where('user_id', $userId)->latest('id')->firstOrFail();

    expect($swept)->toBeTrue('the rival writer never ran, so the test proved nothing')
        ->and(ChainLink::query()->where('user_id', $userId)->count())->toBe(23)
        ->and((int) $run->linked_count)->toBe(23);
});
