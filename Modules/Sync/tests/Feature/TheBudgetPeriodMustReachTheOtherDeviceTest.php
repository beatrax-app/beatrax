<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Budgets\Public\Services\CarryoverQuery;
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

// `envelope_assignments.period_start` is keyed to the reader's period-start
// day. Move that day on the desktop and EnvelopePeriodRekeyer re-keys every
// assignment AND syncs the re-keyed rows — so a phone that never received the
// setting asks PeriodQuery for a window no synced row is keyed to, and the
// whole budget reads as zero. The setting has to travel with the rows.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-08-10 11:00:00');

    $this->user = User::create([
        'username' => 'period-sync-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($this->user);

    $this->groceries = Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'period-sync-groceries-'.bin2hex(random_bytes(3)),
        'kind' => 'expense',
        'display_order' => 1,
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function bindUserSettingsDeviceWriter(int $userId, string $deviceId): string
{
    $keypair = sodium_crypto_sign_keypair();
    $secretKey = sodium_crypto_sign_secretkey($keypair);
    $publicKey = sodium_crypto_sign_publickey($keypair);

    /** @var OpLogWriter $writer */
    $writer = app(OpLogWriter::class, [
        'deviceId' => $deviceId,
        'userId' => $userId,
        'secretKey' => $secretKey,
        'publicKey' => $publicKey,
    ]);
    app()->instance(OpLogWriter::class, $writer);

    return bin2hex($publicKey);
}

/**
 * @return list<OpLogEntry>
 */
function userSettingsOpsAfter(DatabaseManager $db, int $userId, int $afterId): array
{
    return $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
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

function userSettingsMaxOpLogId(DatabaseManager $db): int
{
    $max = $db->connection()->table('op_log_entries')->max('id');

    return is_numeric($max) ? (int) $max : 0;
}

it('carries a moved budget period to the other device, so its envelopes are not read as zero', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $publicKey = bindUserSettingsDeviceWriter((int) $this->user->id, 'device-desktop');
    $watermark = userSettingsMaxOpLogId($db);

    // The desktop moves the period to the 25th. The rekeyer re-keys the
    // assignment and captures it; the setting itself has to be captured too.
    app(EnvelopeWriter::class)->setAssigned(
        $this->user,
        $this->groceries->id,
        app(PeriodQuery::class)->current()->start,
        20000,
    );

    // Mounted by its registered alias: naming the class would put a
    // Sync -> Shell\Internal crossing on the pinned import list for a screen
    // this test only drives.
    Livewire::test('core.settings-page')
        ->set('periodStartDay', 25)
        ->call('save')
        ->call('save')
        ->assertHasNoErrors();

    $ops = userSettingsOpsAfter($db, (int) $this->user->id, $watermark);

    expect(array_values(array_filter(
        $ops,
        static fn (OpLogEntry $op): bool => $op->table === 'users' && $op->field === 'period_start_day',
    )))->not->toBe([], 'Moving the budget period put nothing on the wire, so the other device never hears about it.');

    // The phone: same reader, its own row, still on the default day.
    $db->connection()->table('users')->where('id', $this->user->id)->update(['period_start_day' => 1]);

    (new OpLogReplayer($db, ['device-desktop' => $publicKey], new MergeRulesRegistry))
        ->replay($ops, (int) $this->user->id);

    expect((int) $db->connection()->table('users')->where('id', $this->user->id)->value('period_start_day'))
        ->toBe(25, 'The replayed setting never reached the row.');

    $this->user->refresh();
    $fold = app(CarryoverQuery::class)->forUserAndPeriod($this->user, app(PeriodQuery::class)->current());

    expect($fold['rows'][$this->groceries->id]->assignedMinor)
        ->toBe(20000, 'The phone asked for a period no synced assignment is keyed to.');

    expect($db->connection()->table('op_log_quarantine')->where('user_id', $this->user->id)->count())->toBe(0);
});
