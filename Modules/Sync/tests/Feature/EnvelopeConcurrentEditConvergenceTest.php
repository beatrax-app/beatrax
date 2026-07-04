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

/*
 * Wave 0 RED stub for Req 11's convergence half (13.2-VALIDATION.md),
 * mirroring TransactionSplitConcurrentEditConvergenceTest's shape. None of
 * EnvelopeWriter (Plan 04), the envelope_assignments table (Plan 02), or the
 * registry/listener/provider wiring (Plan 05) exist yet -- expected to fail
 * on the missing class/table, never a parse error.
 *
 * Scenario: one assignment row is seeded once, so both "device A" and
 * "device B" hold an IDENTICAL assignment row (same stable PK, per D-01's
 * updateOrCreate upsert). Offline (no cross-sync yet): device A edits
 * assigned_minor to one value; device B (unaware of A's edit) edits the
 * SAME field to a different value. Both devices' real, captured op-log
 * entries are merged and replayed. Per-(table,pk,field) LWW must converge
 * DETERMINISTICALLY -- replaying the merged op set in either application
 * order must yield the IDENTICAL final state (order-independence is the
 * property this test pins, since the internal HLC tie-break rule is not
 * something this Wave 0 stub needs to hard-code).
 */

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

/**
 * Constructs a fresh OpLogWriter for the given device, binds it into the
 * container (so SyncCaptureListener's lazy resolution picks it up), and
 * returns its hex-encoded public key for the replayer's device-key map.
 */
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

    // Origin device seeds the assignment -- both device A and device B are
    // assumed to already hold this identical row (same PK, D-01 upsert).
    bindEnvelopeDeviceWriter((int) $this->user->id, 'device-origin');
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->period->start, 20000);

    $assignmentId = (int) $db->connection()->table('envelope_assignments')
        ->where('user_id', $this->user->id)
        ->where('category_id', $this->groceries->id)
        ->where('period_start', $this->period->start->toDateString())
        ->value('id');

    // --- Device A (offline): edits assigned_minor. ---
    $pkA = bindEnvelopeDeviceWriter((int) $this->user->id, 'device-a');
    $watermarkA = envelopeAssignmentMaxOpLogId($db);
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->period->start, 25000);
    $aOps = envelopeAssignmentOpsAfter($db, (int) $this->user->id, $watermarkA);
    expect($aOps)->not->toBeEmpty();

    // Restore the live row to its ORIGINAL value -- device B is offline and
    // has NOT seen A's edit (scratch-state manipulation only; the final
    // converged state is entirely determined by replaying the merged ops).
    $db->connection()->table('envelope_assignments')->where('id', $assignmentId)->update(['assigned_minor' => 20000]);

    // --- Device B (offline, independently): edits the SAME field to a different value. ---
    $pkB = bindEnvelopeDeviceWriter((int) $this->user->id, 'device-b');
    $watermarkB = envelopeAssignmentMaxOpLogId($db);
    app(EnvelopeWriter::class)->setAssigned($this->user, $this->groceries->id, $this->period->start, 30000);
    $bOps = envelopeAssignmentOpsAfter($db, (int) $this->user->id, $watermarkB);
    expect($bOps)->not->toBeEmpty();

    $deviceKeys = ['device-a' => $pkA, 'device-b' => $pkB];

    // Replay in one order.
    $replayerForward = new OpLogReplayer($db, $deviceKeys, new MergeRulesRegistry);
    $replayerForward->replay([...$aOps, ...$bOps], (int) $this->user->id);
    $forwardResult = (int) $db->connection()->table('envelope_assignments')->where('id', $assignmentId)->value('assigned_minor');

    // Reset and replay in the REVERSE order -- LWW must converge to the SAME value.
    $db->connection()->table('envelope_assignments')->where('id', $assignmentId)->update(['assigned_minor' => 20000]);
    $replayerReverse = new OpLogReplayer($db, $deviceKeys, new MergeRulesRegistry);
    $replayerReverse->replay([...$bOps, ...$aOps], (int) $this->user->id);
    $reverseResult = (int) $db->connection()->table('envelope_assignments')->where('id', $assignmentId)->value('assigned_minor');

    expect($reverseResult)->toBe($forwardResult);
    expect([25000, 30000])->toContain($forwardResult);

    // Exactly one row -- no duplicate/lost assignment.
    expect($db->connection()->table('envelope_assignments')->where('id', $assignmentId)->count())->toBe(1);

    // No quarantine -- both devices' keys were known, signatures verified,
    // and every _create_required/strategy path resolved cleanly.
    expect($db->connection()->table('op_log_quarantine')->where('user_id', $this->user->id)->count())->toBe(0);
});
