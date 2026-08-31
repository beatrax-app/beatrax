<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Pots\Internal\Http\Livewire\PotsPage;
use Modules\Pots\Public\Services\PotBalanceQuery;
use Modules\Pots\Public\Services\PotWriter;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;

uses(RefreshDatabase::class);

// `pot_movements.kind` has the same exposure envelope_moves.kind had: an
// unconstrained string(32) on a create-only synced table with `kind` in
// `_create_required`, written verbatim from the peer. PotMovementKind::from()
// raised, and the blade's own match() over the four cases would have raised
// again right after it — both on the /pots page of the OLDER device.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-04 11:00:00');

    $this->user = User::create([
        'username' => 'pot-unknown-kind-'.bin2hex(random_bytes(4)),
        'password' => 'opensesame',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
    $this->actingAs($this->user);

    $this->account = Account::create([
        'user_id' => $this->user->id,
        'name' => 'ASN',
        'slug' => 'asn-pot-kind',
        'kind' => AccountKind::Bank->value,
        'iban' => 'NL57ASNB0123456789',
        'default_currency' => Currency::Eur->value,
    ]);

    $this->pot = app(PotWriter::class)->save($this->user, 'Boodschappen', null, $this->account->id, null, null);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @return list<OpLogEntry>
 */
function potMovementOps(DatabaseManager $db, int $userId): array
{
    return $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'pot_movements')
        ->orderBy('id')
        ->get()
        ->map(static function (object $row): OpLogEntry {
            $pk = is_numeric($row->pk) ? (int) $row->pk : (string) $row->pk;

            return new OpLogEntry(
                table: (string) $row->table_name,
                pk: $pk,
                field: (string) $row->field,
                value: $row->value !== null ? (string) $row->value : null,
                hlcL: (int) $row->hlc_l,
                hlcC: (int) $row->hlc_c,
                deviceId: (string) $row->device_id,
                opType: OpType::from((string) $row->op_type),
                signature: (string) $row->signature,
                userId: (int) $row->user_id,
            );
        })
        ->all();
}

function replayNewerPeersMovement(User $user, int $potId): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $keypair = sodium_crypto_sign_keypair();

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => 'device-newer-build',
        'userId' => (int) $user->id,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]);
    app()->instance(OpLogWriter::class, $writer);

    $writer->writeCreateRow('pot_movements', 9_001, [
        'id' => 9_001,
        'pot_id' => $potId,
        'amount_minor' => 2_500,
        'currency' => Currency::Eur->value,
        'kind' => 'round_up',
        'memo' => 'from a newer build',
    ]);

    (new OpLogReplayer($db, ['device-newer-build' => bin2hex(sodium_crypto_sign_publickey($keypair))], new MergeRulesRegistry))
        ->replay(potMovementOps($db, (int) $user->id), (int) $user->id);
}

it('lands the peer movement verbatim, because nothing in the schema refuses a kind it has never seen', function (): void {
    replayNewerPeersMovement($this->user, (int) $this->pot->id);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    expect($db->connection()->table('pot_movements')->where('id', 9_001)->value('kind'))->toBe('round_up');
});

it('reads the pot history back without raising on a kind it cannot name', function (): void {
    replayNewerPeersMovement($this->user, (int) $this->pot->id);

    $row = app(PotBalanceQuery::class)->forUser($this->user)[0];

    expect($row->recentMovements)->toHaveCount(1)
        ->and($row->recentMovements[0]->kind)->toBeNull()
        ->and($row->recentMovements[0]->amountMinor)->toBe(2_500);
});

it('renders the pots page and names the movement it cannot read', function (): void {
    replayNewerPeersMovement($this->user, (int) $this->pot->id);

    Livewire::test(PotsPage::class)
        ->assertOk()
        ->assertSee('from a newer build');
});
