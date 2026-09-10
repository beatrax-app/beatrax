<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\Counterparties\Public\Contracts\CounterpartyResolver;
use Modules\Import\Models\MerchantAlias;
use Modules\Import\Public\Actions\MergeMerchantAliases;
use Modules\Ledger\Public\Dto\CanonicalTransaction;
use Modules\Sync\Public\Events\EntityMutated;

uses(RefreshDatabase::class);

// A merge rewrote merchant_aliases and nothing else, so the next import
// resolved the merged name, slugged it differently and minted a SECOND
// counterparty. The reader's history for one merchant then sat under two rows
// with nothing joining them, and a merge has no undo.

beforeEach(function (): void {
    $suffix = bin2hex(random_bytes(4));

    $this->user = User::create([
        'username' => 'alias-history-'.$suffix,
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $this->db = $db;

    $this->accountId = $db->connection()->table('accounts')->insertGetId([
        'user_id' => $this->user->id, 'name' => 'ASN', 'slug' => 'alias-history-'.$suffix,
        'kind' => 'bank', 'iban' => 'NL00ASNB'.strtoupper($suffix), 'default_currency' => 'EUR',
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);

    $this->runId = $db->connection()->table('import_runs')->insertGetId([
        'user_id' => $this->user->id, 'source_format' => 'asn-csv',
        'raw_file_path' => '/tmp/alias-history-'.$suffix.'.csv',
        'sha256' => hash('sha256', $suffix), 'uploaded_at' => '2026-01-01 00:00:00',
        'status' => 'previewed', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);

    $this->shellA = MerchantAlias::create([
        'user_id' => $this->user->id,
        'pattern' => 'BCK*SHELL PIETER NIEUW A *0001',
        'generalized_pattern' => 'shell pieter nieuw a',
        'friendly_name' => 'Shell Pieter A',
    ]);
    $this->shellB = MerchantAlias::create([
        'user_id' => $this->user->id,
        'pattern' => 'BCK*SHELL PIETER NIEUW B *0002',
        'generalized_pattern' => 'shell pieter nieuw b',
        'friendly_name' => 'Shell Pieter B',
    ]);
});

// The file names no counterparty, which is the case the fork needs: the
// resolver then takes the alias's friendly name as the display name, and the
// slug follows it.
function aliasHistoryResolve(User $user, int $accountId, string $description): int
{
    $tx = new CanonicalTransaction(
        userId: $user->id,
        accountId: $accountId,
        type: 'expense',
        postedAt: CarbonImmutable::create(2026, 5, 1, 12, 0, 0),
        bookedAt: CarbonImmutable::create(2026, 5, 1, 12, 0, 0),
        valueDate: CarbonImmutable::create(2026, 5, 1, 12, 0, 0),
        amountMinor: -1099,
        currency: 'EUR',
        settledAmountMinor: -1099,
        settledCurrency: 'EUR',
        counterpartyName: null,
        counterpartyIban: null,
        counterpartyNormalized: 'shell',
        normalizationVersion: 1,
        description: $description,
        categoryId: null,
        sourceFormat: 'asn_csv',
        importRunId: 1,
        sourceRowIndex: 1,
        sourceRef: null,
    );

    $dto = app(CounterpartyResolver::class)->resolve($tx, $user);

    return $dto?->counterpartyId ?? 0;
}

function aliasHistoryTxn(DatabaseManager $db, int $userId, int $accountId, int $runId, int $counterpartyId, string $description): int
{
    static $row = 0;
    $row++;

    return $db->connection()->table('transactions')->insertGetId([
        'user_id' => $userId, 'account_id' => $accountId, 'import_run_id' => $runId,
        'fingerprint' => hash('sha256', 'alias-history-'.$row.bin2hex(random_bytes(8))),
        'posted_at' => '2026-05-01', 'booked_at' => '2026-05-01 00:00:00', 'value_date' => '2026-05-01',
        'amount_minor' => -1099, 'currency' => 'EUR',
        'settled_amount_minor' => -1099, 'settled_currency' => 'EUR',
        'counterparty_id' => $counterpartyId, 'counterparty_normalized' => 'shell-'.$row,
        'counterparty_name' => 'SHELL', 'normalization_version' => 1,
        'description' => $description, 'type' => 'expense',
        'source_format' => 'asn-csv', 'source_row_index' => $row, 'fingerprint_version' => 3,
        'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);
}

it('leaves one counterparty holding every transaction the merged aliases named', function (): void {
    $descriptionA = 'BCK*SHELL PIETER NIEUW A *0001';
    $descriptionB = 'BCK*SHELL PIETER NIEUW B *0002';

    $cpA = aliasHistoryResolve($this->user, $this->accountId, $descriptionA);
    $cpB = aliasHistoryResolve($this->user, $this->accountId, $descriptionB);

    expect($cpA)->toBeGreaterThan(0)->and($cpB)->toBeGreaterThan(0)->and($cpA)->not->toBe($cpB);

    $txA = aliasHistoryTxn($this->db, $this->user->id, $this->accountId, $this->runId, $cpA, $descriptionA);
    $txB = aliasHistoryTxn($this->db, $this->user->id, $this->accountId, $this->runId, $cpB, $descriptionB);

    app(MergeMerchantAliases::class)(
        $this->user,
        [$this->shellA->id, $this->shellB->id],
        'Shell Pieter',
        'shell pieter nieuw',
    );

    // What the next import does: resolve the merged name off each description.
    $afterA = aliasHistoryResolve($this->user, $this->accountId, $descriptionA);
    $afterB = aliasHistoryResolve($this->user, $this->accountId, $descriptionB);

    expect($afterA)->toBe($afterB, 'The merged alias resolves to two counterparties, so the history is still split.');

    $rows = $this->db->connection()->table('counterparties')
        ->where('user_id', $this->user->id)
        ->pluck('display_name', 'id');

    expect($rows->all())->toHaveCount(1, 'The absorbed counterparty outlived the merge: '.$rows->implode(', '));

    $pointers = $this->db->connection()->table('transactions')
        ->where('user_id', $this->user->id)
        ->whereIn('id', [$txA, $txB])
        ->pluck('counterparty_id')
        ->unique()
        ->all();

    expect(array_values($pointers))->toBe([$afterA]);
});

it('announces every row the merge repoints and the counterparty it removes', function (): void {
    $descriptionA = 'BCK*SHELL PIETER NIEUW A *0001';
    $descriptionB = 'BCK*SHELL PIETER NIEUW B *0002';

    $cpA = aliasHistoryResolve($this->user, $this->accountId, $descriptionA);
    $cpB = aliasHistoryResolve($this->user, $this->accountId, $descriptionB);
    $txB = aliasHistoryTxn($this->db, $this->user->id, $this->accountId, $this->runId, $cpB, $descriptionB);
    aliasHistoryTxn($this->db, $this->user->id, $this->accountId, $this->runId, $cpA, $descriptionA);

    /** @var list<EntityMutated> $captured */
    $captured = [];
    Event::listen(EntityMutated::class, function (EntityMutated $event) use (&$captured): void {
        $captured[] = $event;
    });

    app(MergeMerchantAliases::class)(
        $this->user,
        [$this->shellA->id, $this->shellB->id],
        'Shell Pieter',
        'shell pieter nieuw',
    );

    $repointed = array_values(array_filter(
        $captured,
        static fn (EntityMutated $e): bool => $e->table === 'transactions'
            && $e->mutationType === 'edit'
            && array_key_exists('counterparty_id', $e->dirtyFields),
    ));

    expect(array_map(static fn (EntityMutated $e): int|string => $e->pk, $repointed))->toContain($txB);

    $removed = array_values(array_filter(
        $captured,
        static fn (EntityMutated $e): bool => $e->table === 'counterparties' && $e->mutationType === 'delete',
    ));

    expect($removed)->not->toBeEmpty('The counterparty the merge removed was never announced, so the peer keeps it.');
});

it('records on the surviving counterparty which names it absorbed', function (): void {
    $descriptionA = 'BCK*SHELL PIETER NIEUW A *0001';
    $descriptionB = 'BCK*SHELL PIETER NIEUW B *0002';

    aliasHistoryResolve($this->user, $this->accountId, $descriptionA);
    aliasHistoryResolve($this->user, $this->accountId, $descriptionB);

    app(MergeMerchantAliases::class)(
        $this->user,
        [$this->shellA->id, $this->shellB->id],
        'Shell Pieter',
        'shell pieter nieuw',
    );

    /** @var stdClass|null $survivor */
    $survivor = $this->db->connection()->table('counterparties')
        ->where('user_id', $this->user->id)
        ->first(['display_name', 'metadata']);

    expect($survivor)->not->toBeNull();

    $metadata = is_string($survivor->metadata ?? null) ? json_decode((string) $survivor->metadata, true) : [];
    $absorbed = is_array($metadata) && is_array($metadata['merged_from'] ?? null) ? $metadata['merged_from'] : [];

    expect(array_column($absorbed, 'name'))->toBe(['Shell Pieter B']);
});

// The foreign key is nullOnDelete and a null counterparty_id matches every
// merchant, so a removal that took the column with it would turn one
// merchant's mute into a mute over the whole ledger.
it('carries a suppression rule across instead of letting the removal null it', function (): void {
    $descriptionA = 'BCK*SHELL PIETER NIEUW A *0001';
    $descriptionB = 'BCK*SHELL PIETER NIEUW B *0002';

    aliasHistoryResolve($this->user, $this->accountId, $descriptionA);
    $cpB = aliasHistoryResolve($this->user, $this->accountId, $descriptionB);

    $ruleId = $this->db->connection()->table('anomaly_suppression_rules')->insertGetId([
        'user_id' => $this->user->id, 'counterparty_id' => $cpB, 'detector' => 'large',
        'direction' => 'expense', 'amount_band_low_minor' => -5000, 'amount_band_high_minor' => -1000,
        'currency' => 'EUR', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
    ]);

    app(MergeMerchantAliases::class)(
        $this->user,
        [$this->shellA->id, $this->shellB->id],
        'Shell Pieter',
        'shell pieter nieuw',
    );

    $survivorId = $this->db->connection()->table('counterparties')
        ->where('user_id', $this->user->id)
        ->value('id');

    expect($this->db->connection()->table('anomaly_suppression_rules')->where('id', $ruleId)->value('counterparty_id'))
        ->toEqual($survivorId);
});

it('renames the surviving counterparty so the next import slugs onto it', function (): void {
    $descriptionA = 'BCK*SHELL PIETER NIEUW A *0001';
    aliasHistoryResolve($this->user, $this->accountId, $descriptionA);
    aliasHistoryResolve($this->user, $this->accountId, 'BCK*SHELL PIETER NIEUW B *0002');

    app(MergeMerchantAliases::class)(
        $this->user,
        [$this->shellA->id, $this->shellB->id],
        'Shell Pieter',
        'shell pieter nieuw',
    );

    /** @var stdClass $survivor */
    $survivor = $this->db->connection()->table('counterparties')
        ->where('user_id', $this->user->id)
        ->first(['slug', 'display_name', 'merchant_name']);

    expect($survivor->slug)->toBe('shell-pieter')
        ->and($survivor->display_name)->toBe('Shell Pieter')
        ->and($survivor->merchant_name)->toBe('Shell Pieter');
});

it('leaves another reader holding the same merchant name untouched', function (): void {
    $foreigner = User::create([
        'username' => 'alias-history-foreign-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $theirs = $this->db->connection()->table('counterparties')->insertGetId([
        'user_id' => $foreigner->id, 'type' => 'merchant', 'slug' => 'shell-pieter-b',
        'display_name' => 'Shell Pieter B', 'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    aliasHistoryResolve($this->user, $this->accountId, 'BCK*SHELL PIETER NIEUW A *0001');
    aliasHistoryResolve($this->user, $this->accountId, 'BCK*SHELL PIETER NIEUW B *0002');

    app(MergeMerchantAliases::class)(
        $this->user,
        [$this->shellA->id, $this->shellB->id],
        'Shell Pieter',
        'shell pieter nieuw',
    );

    expect($this->db->connection()->table('counterparties')->where('id', $theirs)->value('display_name'))
        ->toBe('Shell Pieter B');
});

// Nothing is absorbed here — only one of the two aliases had ever named a
// counterparty — and the fork happens anyway, because the row keeps a slug the
// merged name no longer resolves to.
it('follows the merged name even when there is nothing to absorb', function (): void {
    aliasHistoryResolve($this->user, $this->accountId, 'BCK*SHELL PIETER NIEUW A *0001');

    app(MergeMerchantAliases::class)(
        $this->user,
        [$this->shellA->id, $this->shellB->id],
        'Shell Pieter',
        'shell pieter nieuw',
    );

    $slugs = $this->db->connection()->table('counterparties')
        ->where('user_id', $this->user->id)
        ->pluck('slug')
        ->all();

    expect($slugs)->toBe(['shell-pieter'])
        ->and(aliasHistoryResolve($this->user, $this->accountId, 'BCK*SHELL PIETER NIEUW A *0001'))
        ->toBeGreaterThan(0);
});
