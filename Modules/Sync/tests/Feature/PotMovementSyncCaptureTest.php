<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\ImportRun;
use Modules\Ledger\Models\Transaction;
use Modules\Pots\Models\Pot;
use Modules\Pots\Public\Services\PotWriter;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;

uses(RefreshDatabase::class);

// `pots` synced and `pot_movements` did not, so a paired phone listed every pot
// at a balance of EUR 0,00 while the page promised the balances always add up to
// the real account balance. The movement ledger IS the balance.

/** @return array{user: User, potId: int} */
function potMovementFixture(): array
{
    $user = User::query()->create([
        'username' => 'potmove-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $account = Account::query()->create([
        'user_id' => $user->id,
        'name' => 'ASN',
        'slug' => 'potmove-asn-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL57ASNB'.strtoupper(bin2hex(random_bytes(5))),
        'default_currency' => 'EUR',
    ]);

    $run = ImportRun::query()->create([
        'user_id' => $user->id,
        'source_format' => 'camt053',
        'raw_file_path' => '/tmp/potmove.xml',
        'sha256' => hash('sha256', 'potmove-'.bin2hex(random_bytes(6))),
        'uploaded_at' => CarbonImmutable::now(),
        'status' => 'previewed',
    ]);

    Transaction::query()->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'type' => 'transfer_in',
        'posted_at' => CarbonImmutable::now()->toDateString(),
        'booked_at' => CarbonImmutable::now()->toDateString().' 12:00:00',
        'value_date' => CarbonImmutable::now()->toDateString(),
        'amount_minor' => 375464,
        'currency' => 'EUR',
        'settled_amount_minor' => 375464,
        'settled_currency' => 'EUR',
        'counterparty_name' => 'Salary',
        'counterparty_normalized' => 'salary',
        'normalization_version' => 1,
        'source_format' => 'camt053',
        'import_run_id' => $run->id,
        'source_row_index' => 9001,
        'fingerprint' => str_pad('potmove'.bin2hex(random_bytes(4)), 64, '0', STR_PAD_LEFT),
        'fingerprint_version' => 1,
    ]);

    $pot = Pot::query()->withoutGlobalScope(UserScope::class)->create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'goal_id' => null,
        'category_id' => null,
        'name' => 'Annual insurance',
        'currency' => 'EUR',
        'status' => 'active',
    ]);

    return ['user' => $user, 'potId' => (int) $pot->id];
}

// Hands the public key back so the captured history can be verified the way a
// peer would verify it.
function bindPotMovementWriter(int $userId): string
{
    $keypair = sodium_crypto_sign_keypair();
    $publicKey = sodium_crypto_sign_publickey($keypair);

    app()->instance(OpLogWriter::class, app(OpLogWriter::class, [
        'deviceId' => 'pot-movement-device',
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => $publicKey,
    ]));

    return bin2hex($publicKey);
}

it('captures a funding movement the moment it is written', function (): void {
    ['user' => $user, 'potId' => $potId] = potMovementFixture();
    bindPotMovementWriter((int) $user->id);

    app(PotWriter::class)->fund($user, $potId, '50,00', 'Kwartaalpremie');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $ops = $db->connection()->table('op_log_entries')
        ->where('user_id', $user->id)
        ->where('table_name', 'pot_movements')
        ->get();

    expect($ops)->not->toBeEmpty('a movement that reaches no op log can never reach a peer')
        ->and($ops->pluck('op_type')->unique()->all())->toBe(['create_row'])
        ->and($ops->pluck('field')->all())->toContain('pot_id', 'amount_minor', 'currency', 'kind', 'memo');
});

it('captures both legs of a transfer, each against its own pot', function (): void {
    ['user' => $user, 'potId' => $potId] = potMovementFixture();

    $second = Pot::query()->withoutGlobalScope(UserScope::class)->create([
        'user_id' => $user->id,
        'account_id' => Pot::query()->withoutGlobalScope(UserScope::class)->find($potId)?->account_id,
        'goal_id' => null,
        'category_id' => null,
        'name' => 'Holiday',
        'currency' => 'EUR',
        'status' => 'active',
    ]);

    app(PotWriter::class)->fund($user, $potId, '50,00');

    // Bound after the funding so only the transfer's own ops are in view.
    bindPotMovementWriter((int) $user->id);

    app(PotWriter::class)->transfer($user, $potId, (int) $second->id, '20,00');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $pks = $db->connection()->table('op_log_entries')
        ->where('user_id', $user->id)
        ->where('table_name', 'pot_movements')
        ->distinct()
        ->pluck('pk');

    expect($pks)->toHaveCount(2, 'a transfer is a paired row and both legs have to travel');
});

it('rebuilds the balance on a peer that never saw the movement', function (): void {
    ['user' => $user, 'potId' => $potId] = potMovementFixture();
    $publicKeyHex = bindPotMovementWriter((int) $user->id);

    app(PotWriter::class)->fund($user, $potId, '50,00', 'Kwartaalpremie');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $connection = $db->connection();

    // Stand in for the receiving device: the same signed history, applied to a
    // database whose pot exists and whose ledger is empty — which is exactly
    // the phone that read EUR 0,00 against every pot.
    $connection->table('pot_movements')->where('user_id', $user->id)->delete();

    $entries = [];
    foreach ($connection->table('op_log_entries')->where('user_id', $user->id)->orderBy('hlc_l')->orderBy('hlc_c')->get() as $row) {
        $entries[] = new OpLogEntry(
            table: (string) $row->table_name,
            pk: is_numeric($row->pk) ? (int) $row->pk : (string) $row->pk,
            field: (string) $row->field,
            value: $row->value === null ? null : (string) $row->value,
            hlcL: (int) $row->hlc_l,
            hlcC: (int) $row->hlc_c,
            deviceId: (string) $row->device_id,
            opType: OpType::from((string) $row->op_type),
            signature: (string) $row->signature,
            userId: (int) $user->id,
            gdkEpoch: $row->gdk_epoch === null ? null : (int) $row->gdk_epoch,
        );
    }

    (new OpLogReplayer(
        db: $db,
        deviceKeys: ['pot-movement-device' => $publicKeyHex],
        rules: new MergeRulesRegistry,
    ))->replay($entries, (int) $user->id);

    $rebuilt = $connection->table('pot_movements')->where('pot_id', $potId)->first();

    expect($rebuilt)->not->toBeNull('the peer has to end up holding the movement itself')
        ->and((int) $rebuilt->amount_minor)->toBe(5000)
        ->and((string) $rebuilt->kind)->toBe('fund')
        ->and((string) $rebuilt->memo)->toBe('Kwartaalpremie')
        ->and((int) $connection->table('pot_movements')->where('pot_id', $potId)->sum('amount_minor'))->toBe(5000);
});
