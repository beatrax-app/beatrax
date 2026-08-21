<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Budgets\Public\Services\EnvelopeWriter;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;

uses(RefreshDatabase::class);

// Two devices edit the same assignment field while offline, and the merged
// ops are replayed in both orders. Order-independence is the property pinned
// here rather than which value wins: a merge that agreed with itself only when
// ops arrived in one order would still look correct from one device.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-04 11:00:00');

    $this->user = User::create([
        'username' => 'assign-concurrent-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->groceries = Category::create(['user_id' => null, 'name' => 'Groceries', 'slug' => 'assign-conc-groceries-'.bin2hex(random_bytes(3)), 'kind' => 'expense', 'display_order' => 1]);

    $this->period = app(PeriodQuery::class)->current();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

// Bound into the container so the capture listener's lazy resolution picks it
// up, and returns the public key the replayer needs to verify what it signs.
function bindEnvelopeDeviceWriter(int $userId, string $deviceId): string
{
    $keypair = sodium_crypto_sign_keypair();
    $sk = sodium_crypto_sign_secretkey($keypair);
    $pk = sodium_crypto_sign_publickey($keypair);

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => $deviceId,
        'userId' => $userId,
        'secretKey' => $sk,
        'publicKey' => $pk,
    ]);
    app()->instance(OpLogWriter::class, $writer);

    return bin2hex($pk);
}

/**
 * @return list<OpLogEntry>
 */
function envelopeAssignmentOpsAfter(DatabaseManager $db, int $userId, int $afterId): array
{
    return $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'envelope_assignments')
        ->where('id', '>', $afterId)
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

function envelopeAssignmentMaxOpLogId(DatabaseManager $db): int
{
    $max = $db->connection()->table('op_log_entries')->max('id');

    return is_numeric($max) ? (int) $max : 0;
}

it('two devices concurrently editing the SAME assignment field converge deterministically regardless of replay order', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // Seeded once, so both devices start from an identical row under the same
    // stable pk.
    bindEnvelopeDeviceWriter((int) $this->user->id, 'device-origin');
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->period->start, 20000);

    $assignmentId = (int) $db->connection()->table('envelope_assignments')
        ->where('user_id', $this->user->id)
        ->where('category_id', $this->groceries->id)
        ->where('period_start', $this->period->start->toDateString())
        ->value('id');

    $pkA = bindEnvelopeDeviceWriter((int) $this->user->id, 'device-a');
    $watermarkA = envelopeAssignmentMaxOpLogId($db);
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->period->start, 25000);
    $aOps = envelopeAssignmentOpsAfter($db, (int) $this->user->id, $watermarkA);
    expect($aOps)->not->toBeEmpty();

    // Device B never saw A's edit, so the live row goes back to where B would
    // have found it. The converged state comes only from replaying the ops.
    $db->connection()->table('envelope_assignments')->where('id', $assignmentId)->update(['assigned_minor' => 20000]);

    $pkB = bindEnvelopeDeviceWriter((int) $this->user->id, 'device-b');
    $watermarkB = envelopeAssignmentMaxOpLogId($db);
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->period->start, 30000);
    $bOps = envelopeAssignmentOpsAfter($db, (int) $this->user->id, $watermarkB);
    expect($bOps)->not->toBeEmpty();

    $deviceKeys = ['device-a' => $pkA, 'device-b' => $pkB];

    $replayerForward = new OpLogReplayer($db, $deviceKeys, new MergeRulesRegistry);
    $replayerForward->replay([...$aOps, ...$bOps], (int) $this->user->id);
    $forwardResult = (int) $db->connection()->table('envelope_assignments')->where('id', $assignmentId)->value('assigned_minor');

    // The reverse order must land on the same value.
    $db->connection()->table('envelope_assignments')->where('id', $assignmentId)->update(['assigned_minor' => 20000]);
    $replayerReverse = new OpLogReplayer($db, $deviceKeys, new MergeRulesRegistry);
    $replayerReverse->replay([...$bOps, ...$aOps], (int) $this->user->id);
    $reverseResult = (int) $db->connection()->table('envelope_assignments')->where('id', $assignmentId)->value('assigned_minor');

    expect($reverseResult)->toBe($forwardResult);
    expect([25000, 30000])->toContain($forwardResult);

    expect($db->connection()->table('envelope_assignments')->where('id', $assignmentId)->count())->toBe(1);

    expect($db->connection()->table('op_log_quarantine')->where('user_id', $this->user->id)->count())->toBe(0);
});
