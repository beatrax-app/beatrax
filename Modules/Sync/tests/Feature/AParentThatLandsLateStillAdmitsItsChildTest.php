<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\OpLog\QuarantineReason;
use Modules\Sync\Internal\OpLog\SyncBacklogState;
use Modules\Sync\Public\Services\HistoryReprojector;

uses(RefreshDatabase::class);

// A create refused because its parent had not arrived was filed beside a forged
// signature: a permanent verdict, never looked at again. It is not one. The
// parent routinely lands afterwards — a category the backfill captures today
// carries a newer clock than a transaction logged live yesterday — and the op
// log still holds every entry needed to place the child once it does.
//
// Measured on a paired iPhone: two charges absent from a 399-row ledger, three
// further syncs bringing nothing, and 34 op-log entries for one of them sitting
// unused on the device the whole time.

function lateParentUser(): User
{
    return User::query()->create([
        'username' => 'lateparent-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function lateParentQuarantine(User $user, string $reason, ?int $epoch = null): void
{
    app(DatabaseManager::class)->connection()->table('op_log_quarantine')->insert([
        'user_id' => $user->id,
        'table_name' => 'transactions',
        'pk' => '2199',
        'device_id' => 'desktop-device',
        'reason' => $reason,
        'hlc_l' => 1788189586930,
        'hlc_c' => 0,
        'raw_value' => (string) $user->id,
        'gdk_epoch' => $epoch,
        'created_at' => '2026-09-01 10:20:06',
    ]);
}

function lateParentBacklog(User $user): SyncBacklogState
{
    test()->actingAs($user);

    return app(HistoryReprojector::class)->backlogState($user->id, app(Session::class), null, null);
}

it('counts a missing parent among the verdicts a later state can undo', function (): void {
    expect(QuarantineReason::recoverable())->toContain(QuarantineReason::MissingReference->value);
});

// The half that must NOT change: this set names what a key undoes, and it is
// what the screen calls "waiting for a key".
it('keeps a missing parent out of the key-recoverable set', function (): void {
    expect(QuarantineReason::keyRecoverable())->not->toContain(QuarantineReason::MissingReference->value);
});

it('sees a row held for a missing parent as backlog worth replaying', function (): void {
    $user = lateParentUser();
    lateParentQuarantine($user, QuarantineReason::MissingReference->value);

    expect(lateParentBacklog($user))->toBe(SyncBacklogState::Deferred);
});

// A row whose parent is missing is not a row whose key is missing, and the
// screen must not offer the reader a cause that was never its own — even when
// the entry happens to carry an epoch this device does not hold.
it('never reports a missing parent as waiting for a key', function (): void {
    $user = lateParentUser();
    lateParentQuarantine($user, QuarantineReason::MissingReference->value, 99);

    expect(lateParentBacklog($user))->not->toBe(SyncBacklogState::AwaitingKey);
});

it('still reports a genuinely sealed entry as waiting for a key', function (): void {
    $user = lateParentUser();
    lateParentQuarantine($user, QuarantineReason::GdkDecryptFailed->value, 99);

    expect(lateParentBacklog($user))->toBe(SyncBacklogState::AwaitingKey);
});
