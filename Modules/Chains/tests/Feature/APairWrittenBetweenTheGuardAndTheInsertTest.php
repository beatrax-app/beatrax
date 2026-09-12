<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\DB;
use Modules\Chains\Internal\ChainLinkInsertHelper;
use Modules\Chains\Internal\Enums\ChainLinkResolver;
use Modules\Chains\Public\Enums\ChainLinkKind;
use Modules\Chains\Public\Enums\ChainLinkState;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\TransactionType;
use Modules\Sync\Public\Events\EntityMutated;

// Two resolver passes for one user really do overlap: ResolveChainLinksJob is
// unique only until processing, so it drops its lock the moment handle() begins
// and a second dispatch starts beside the first. Both read "no row for this
// pair" and both write, and chain_links_pair_uq refuses the second — which used
// to leave the resolver pass dead rather than merely finished.

function ctaLinkTransaction(DatabaseManager $db, User $user, Account $account, ImportRun $run, string $key): int
{
    return (int) $db->connection()->table('transactions')->insertGetId([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => TransactionType::Expense->value,
        'posted_at' => '2026-03-10',
        'booked_at' => '2026-03-10 12:00:00',
        'value_date' => '2026-03-10',
        'amount_minor' => -1500,
        'currency' => 'EUR',
        'settled_amount_minor' => -1500,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Race '.$key,
        'counterparty_normalized' => 'race-'.$key,
        'normalization_version' => 3,
        'source_format' => 'asn-csv',
        'import_run_id' => $run->id,
        'source_row_index' => crc32($key) % 100000,
        'fingerprint' => hash('sha256', 'race-'.$key),
        'fingerprint_version' => 3,
        'created_at' => '2026-03-10 12:00:00',
        'updated_at' => '2026-03-10 12:00:00',
    ]);
}

/** @return array<string, mixed> */
function ctaLinkRow(int $fromId, int $toId): array
{
    return [
        'from_transaction_id' => $fromId,
        'to_transaction_id' => $toId,
        'kind' => ChainLinkKind::PaypalFunding->value,
        'state' => ChainLinkState::Confirmed->value,
        'confidence' => '1.000',
        'resolver' => ChainLinkResolver::Auto->value,
        'evidence' => ['signature_hash' => 'cta-race-sig'],
    ];
}

// The competing pass, committed between this one's guard and its insert.
function ctaLinkInjectAfterGuard(int $userId, int $fromId, int $toId): void
{
    $done = false;

    DB::listen(function ($query) use (&$done, $userId, $fromId, $toId): void {
        if ($done || ! str_contains($query->sql, 'chain_links')) {
            return;
        }
        if (! str_starts_with(strtolower(ltrim($query->sql)), 'select')) {
            return;
        }

        $done = true;
        DB::table('chain_links')->insert([
            'id' => 7_654_321,
            'user_id' => $userId,
            'from_transaction_id' => $fromId,
            'to_transaction_id' => $toId,
            'kind' => ChainLinkKind::PaypalFunding->value,
            'state' => ChainLinkState::Candidate->value,
            'confidence' => '0.900',
            'resolver' => ChainLinkResolver::Auto->value,
            'evidence' => '{"signature_hash":"the-other-pass"}',
            'created_at' => '2026-03-10 12:00:00',
            'updated_at' => '2026-03-10 12:00:00',
        ]);
    });
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-04-01 09:00:00');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->user = User::query()->create([
        'username' => 'cta-link-race',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
    $account = Account::query()->create([
        'user_id' => $this->user->id,
        'name' => 'race bank',
        'slug' => 'race-bank',
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => 'EUR',
    ]);
    $run = ImportRun::query()->create([
        'user_id' => $this->user->id,
        'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/cta-race.csv',
        'sha256' => str_repeat('r', 64),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    $this->payment = ctaLinkTransaction($this->db, $this->user, $account, $run, 'payment');
    $this->charge = ctaLinkTransaction($this->db, $this->user, $account, $run, 'charge');

    /** @var ChainLinkInsertHelper $helper */
    $helper = $this->app->make(ChainLinkInsertHelper::class);
    $this->helper = $helper;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('reports a pair written between the guard and the insert as already held', function (): void {
    ctaLinkInjectAfterGuard((int) $this->user->id, $this->payment, $this->charge);

    $wrote = $this->helper->insertIfNotExists(
        ctaLinkRow($this->payment, $this->charge),
        (int) $this->user->id,
    );

    expect($wrote)->toBeFalse();
});

it('leaves the winning row alone and adds no second one', function (): void {
    ctaLinkInjectAfterGuard((int) $this->user->id, $this->payment, $this->charge);

    $this->helper->insertIfNotExists(
        ctaLinkRow($this->payment, $this->charge),
        (int) $this->user->id,
    );

    $rows = DB::table('chain_links')->where('user_id', $this->user->id)->get();

    expect($rows)->toHaveCount(1)
        ->and((int) $rows[0]->id)->toBe(7_654_321)
        ->and($rows[0]->state)->toBe(ChainLinkState::Candidate->value)
        ->and($rows[0]->evidence)->toBe('{"signature_hash":"the-other-pass"}');
});

it('announces no create for a row the losing pass did not write', function (): void {
    $captured = [];
    app(Dispatcher::class)->listen(
        EntityMutated::class,
        function (EntityMutated $event) use (&$captured): void {
            $captured[] = $event;
        },
    );

    ctaLinkInjectAfterGuard((int) $this->user->id, $this->payment, $this->charge);

    $this->helper->insertIfNotExists(
        ctaLinkRow($this->payment, $this->charge),
        (int) $this->user->id,
    );

    expect($captured)->toBe([]);
});

it('still announces the create when this pass is the one that wrote the pair', function (): void {
    $captured = [];
    app(Dispatcher::class)->listen(
        EntityMutated::class,
        function (EntityMutated $event) use (&$captured): void {
            $captured[] = $event;
        },
    );

    $wrote = $this->helper->insertIfNotExists(
        ctaLinkRow($this->payment, $this->charge),
        (int) $this->user->id,
    );

    expect($wrote)->toBeTrue()
        ->and(DB::table('chain_links')->where('user_id', $this->user->id)->count())->toBe(1)
        ->and($captured)->toHaveCount(1)
        ->and($captured[0]->table)->toBe('chain_links')
        ->and($captured[0]->mutationType)->toBe('create')
        ->and($captured[0]->userId)->toBe((int) $this->user->id);
});
