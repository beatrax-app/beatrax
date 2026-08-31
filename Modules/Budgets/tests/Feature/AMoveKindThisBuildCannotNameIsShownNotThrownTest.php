<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Budgets\Internal\Http\Livewire\BudgetsPage;
use Modules\Budgets\Public\Services\EnvelopeBalanceQuery;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Enums\Currency;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;

uses(RefreshDatabase::class);

// `envelope_moves.kind` is a bare string(32) with no CHECK constraint, it is a
// create-only synced table with `kind` in `_create_required`, and the applier
// writes the peer's value verbatim. A household member on a build that adds a
// third kind therefore lands a spelling this build has no case for — and
// EnvelopeMoveKind::from() raised a ValueError that took the whole /budgets
// page down. Guessing 'out' instead would have been worse: the history line
// draws its direction from the kind, so the reader would have been shown the
// wrong side of a real move.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-04 11:00:00');

    $this->user = User::create([
        'username' => 'unknown-kind-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'base_currency' => Currency::Eur->value,
    ]);
    $this->actingAs($this->user);

    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'kind-groceries-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1]);
    $this->dining = Category::create(['user_id' => null, 'name' => 'Dining', 'slug' => 'kind-dining-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 2]);

    $this->period = app(PeriodQuery::class)->current();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// The peer is a real OpLogWriter under its own device key, so the ops carry a
// signature the replayer verifies rather than a row written straight into the
// table by the test.
function newerPeerWrites(int $userId, string $kind, int $categoryId, int $counterpartId, string $periodStart): string
{
    $keypair = sodium_crypto_sign_keypair();

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => 'device-newer-build',
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => sodium_crypto_sign_publickey($keypair),
    ]);
    app()->instance(OpLogWriter::class, $writer);

    $writer->writeCreateRow('envelope_moves', 9_001, [
        'id' => 9_001,
        'category_id' => $categoryId,
        'counterpart_category_id' => $counterpartId,
        'period_start' => $periodStart,
        'amount_minor' => 2_500,
        'currency' => Currency::Eur->value,
        'kind' => $kind,
        'memo' => 'from a newer build',
    ]);

    return bin2hex(sodium_crypto_sign_publickey($keypair));
}

/**
 * @return list<OpLogEntry>
 */
function envelopeMoveOps(DatabaseManager $db, int $userId): array
{
    return $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'envelope_moves')
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

function replayNewerPeersMove(User $user, Category $to, Category $from, CarbonImmutable $periodStart): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $publicKey = newerPeerWrites((int) $user->id, 'move_sideways', (int) $to->id, (int) $from->id, $periodStart->toDateString());

    (new OpLogReplayer($db, ['device-newer-build' => $publicKey], new MergeRulesRegistry))
        ->replay(envelopeMoveOps($db, (int) $user->id), (int) $user->id);
}

it('lands the peer row verbatim, because nothing in the schema refuses a kind it has never seen', function (): void {
    replayNewerPeersMove($this->user, $this->groceries, $this->dining, $this->period->start);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    expect($db->connection()->table('envelope_moves')->where('user_id', $this->user->id)->value('kind'))
        ->toBe('move_sideways');
});

it('reads the history back without raising on a kind it cannot name', function (): void {
    replayNewerPeersMove($this->user, $this->groceries, $this->dining, $this->period->start);

    $moves = app(EnvelopeBalanceQuery::class)
        ->recentMovesFor((int) $this->user->id, (int) $this->groceries->id, $this->period);

    expect($moves)->toHaveCount(1)
        ->and($moves[0]->kind)->toBeNull()
        ->and($moves[0]->amountMinor)->toBe(2_500);
});

it('renders the budgets page and names the move it cannot read rather than guessing its direction', function (): void {
    replayNewerPeersMove($this->user, $this->groceries, $this->dining, $this->period->start);

    Livewire::test(BudgetsPage::class)
        ->assertOk()
        ->assertSee('from a newer build');
});
