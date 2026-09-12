<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Public\Services\HistoryReprojector;

uses(RefreshDatabase::class);

// A delete the receiver refused is recorded in op_log_quarantine and nowhere
// else, and the sweep retires it once the row it names has gone. "Has it gone"
// was answered by a probe whose catch returned false — which is the same word
// as "yes, gone" — so one locked read retired the only record that a peer's
// delete was turned away, and nothing would ever look at it again.
/**
 * @link ../../../../.docs/features/sync/what-the-quarantine-tells-the-reader.md
 */
function unaskedHoldUser(): User
{
    return User::query()->create([
        'username' => 'unasked-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// A row the peer asked this device to delete and a child here still references,
// so the refusal stands for as long as the row does.
function unaskedHoldAccount(DatabaseManager $db, int $userId): int
{
    return (int) $db->connection()->table('accounts')->insertGetId([
        'user_id' => $userId,
        'name' => 'Blocked by a child',
        'slug' => 'unasked-'.bin2hex(random_bytes(3)),
        'kind' => 'bank',
        'iban' => 'NL00UNAS'.str_pad((string) $userId, 10, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
        'created_at' => '2026-09-01 00:00:00',
        'updated_at' => '2026-09-01 00:00:00',
    ]);
}

function unaskedHoldRecord(DatabaseManager $db, int $userId, int $accountId): int
{
    return (int) $db->connection()->table('op_log_quarantine')->insertGetId([
        'user_id' => $userId,
        'op_entry_id' => null,
        'table_name' => 'accounts',
        'pk' => (string) $accountId,
        'device_id' => 'unasked-peer',
        'op_type' => 'delete_tombstone',
        'reason' => 'delete_blocked_by_reference',
        'gdk_epoch' => null,
        'hlc_l' => 1,
        'hlc_c' => 0,
        'raw_value' => null,
        'created_at' => '2026-09-01 00:00:00',
    ]);
}

function unaskedHoldStands(DatabaseManager $db, int $holdId): bool
{
    return $db->connection()->table('op_log_quarantine')->where('id', $holdId)->exists();
}

// One shot at the first presence probe, which the sweep's delete-refusal arm
// makes before anything else in the pass. Every other read still answers
// truthfully, so the branch under test is the only thing that changes.
function unaskedHoldRefuseThePresenceProbe(DatabaseManager $db, bool &$armed): void
{
    $db->connection()->beforeExecuting(function (string $query) use (&$armed): void {
        if ($armed && str_contains($query, 'select exists') && str_contains($query, '"accounts"')) {
            $armed = false;

            throw new PDOException('database is locked');
        }
    });
}

it('keeps a delete refusal whose row could not be looked up at all', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    /** @var Session $session */
    $session = app(Session::class);

    $userId = (int) unaskedHoldUser()->id;
    $accountId = unaskedHoldAccount($db, $userId);
    $holdId = unaskedHoldRecord($db, $userId, $accountId);

    $armed = true;
    unaskedHoldRefuseThePresenceProbe($db, $armed);

    app(HistoryReprojector::class)->replayQuarantined($userId, $session, null, null);

    // The row is still here and the delete is still refused. A pass that could
    // not ask has to leave the record standing for the next one.
    expect($armed)->toBeFalse('the lock never fired, so this proves nothing')
        ->and(unaskedHoldStands($db, $holdId))->toBeTrue()
        ->and($db->connection()->table('accounts')->where('id', $accountId)->exists())->toBeTrue();
});

// The positive control: the sweep must still retire a refusal the row's absence
// has genuinely answered, or the phone reports refusals for deletes it honoured.
it('retires a delete refusal once the row it names is really gone', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    /** @var Session $session */
    $session = app(Session::class);

    $userId = (int) unaskedHoldUser()->id;
    $accountId = unaskedHoldAccount($db, $userId);
    $holdId = unaskedHoldRecord($db, $userId, $accountId);

    $db->connection()->table('accounts')->where('id', $accountId)->delete();

    app(HistoryReprojector::class)->replayQuarantined($userId, $session, null, null);

    expect(unaskedHoldStands($db, $holdId))->toBeFalse();
});

// The other half of the same predicate: a row that is still here is not an
// answered refusal either, and the sweep has always had to leave it alone.
it('keeps a delete refusal while the row it names is still here', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    /** @var Session $session */
    $session = app(Session::class);

    $userId = (int) unaskedHoldUser()->id;
    $accountId = unaskedHoldAccount($db, $userId);
    $holdId = unaskedHoldRecord($db, $userId, $accountId);

    app(HistoryReprojector::class)->replayQuarantined($userId, $session, null, null);

    expect(unaskedHoldStands($db, $holdId))->toBeTrue();
});
