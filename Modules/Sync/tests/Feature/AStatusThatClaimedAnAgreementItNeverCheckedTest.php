<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Sync\Internal\Http\Livewire\SyncQuarantineNotice;
use Modules\Sync\Internal\OpLog\QuarantineOutcome;
use Modules\Sync\Internal\OpLog\QuarantineReason;
use Modules\Sync\Public\Enums\SyncOverallStatus;
use Modules\Sync\Public\Http\Livewire\SyncStatusSection;
use Modules\Sync\Public\Services\SyncStatusService;

uses(RefreshDatabase::class);

// Measured on a paired Galaxy A51 after two full exchanges: 65 refused
// operations sat in op_log_quarantine while the devices screen read "All
// devices up to date". Every one of them was recoverable, so the terminal
// notice said nothing, and every one was older than the watermark the pass
// that recorded them stamped, so the backlog notice said nothing either.

const AGREEMENT_SELF = 'agreement-self-device-id';

const AGREEMENT_PEER = 'agreement-peer-device-id';

function agreementUser(): User
{
    return User::query()->create([
        'username' => 'agreement-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function agreementDevice(DatabaseManager $db, int $userId, string $deviceId, bool $isSelf): void
{
    $at = '2026-09-01T09:00:00+02:00';

    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => 'Name of '.$deviceId,
        'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
        'x25519_public_key_hex' => str_repeat('c', 64),
        'safety_number_words' => 'three spot buzz rich dove puzzle',
        'is_self' => $isSelf,
        'paired_at' => $at,
        'confirmed_at' => $at,
        'created_at' => $at,
        'updated_at' => $at,
    ]);
}

// A household whose every exchange closed cleanly and which owes a peer
// nothing: the exact state the phone was in, and the only one in which the
// aggregate is allowed to claim agreement at all.
function agreementSettledHousehold(DatabaseManager $db, int $userId): void
{
    $at = '2026-09-01T09:00:00+02:00';

    $db->connection()->table('sync_sessions')->insert([
        'user_id' => $userId,
        'local_device_id' => AGREEMENT_SELF,
        'peer_device_id' => AGREEMENT_PEER,
        'status' => 'closed',
        'error_message' => null,
        'connected_at' => $at,
        'last_seen_at' => $at,
        'created_at' => $at,
        'updated_at' => $at,
    ]);

    agreementDevice($db, $userId, AGREEMENT_SELF, isSelf: true);
    agreementDevice($db, $userId, AGREEMENT_PEER, isSelf: false);
}

function agreementRefusal(DatabaseManager $db, int $userId, QuarantineReason $reason, string $pk, string $field = 'amount_minor'): void
{
    $db->connection()->table('op_log_quarantine')->insert([
        'user_id' => $userId,
        'op_entry_id' => null,
        'table_name' => 'transactions',
        'pk' => $pk,
        'device_id' => AGREEMENT_PEER,
        'reason' => $reason->value,
        'gdk_epoch' => null,
        'hlc_l' => 1,
        'hlc_c' => 0,
        'raw_value' => $field,
        'created_at' => '2026-09-01 08:00:00',
    ]);
}

it('does not claim agreement while this device is holding a refusal no pass takes again', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = (int) agreementUser()->id;
    agreementSettledHousehold($db, $userId);

    /** @var SyncStatusService $status */
    $status = app(SyncStatusService::class);

    expect($status->overallStatus($userId))->toBe(SyncOverallStatus::AllSynced);

    agreementRefusal($db, $userId, QuarantineReason::UnplaceableCollision, '4001');

    expect($status->overallStatus($userId))->not->toBe(SyncOverallStatus::AllSynced);
});

it('does not claim agreement while a hold a later pass could still undo is here', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = (int) agreementUser()->id;
    agreementSettledHousehold($db, $userId);

    agreementRefusal($db, $userId, QuarantineReason::MissingReference, '4002');

    expect(app(SyncStatusService::class)->overallStatus($userId))->not->toBe(SyncOverallStatus::AllSynced);
});

it('says it on the line the reader checks, not only in the notice below it', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = agreementUser();
    $userId = (int) $user->id;
    agreementSettledHousehold($db, $userId);

    agreementRefusal($db, $userId, QuarantineReason::UnplaceableCollision, '4003');

    Livewire::actingAs($user)->test(SyncStatusSection::class)
        ->assertDontSee(Lang::get('sync::status.all_synced'));
});

it('leads the reader to what is held rather than reporting a number with nowhere to go', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = agreementUser();
    $userId = (int) $user->id;
    agreementSettledHousehold($db, $userId);

    agreementRefusal($db, $userId, QuarantineReason::MissingReference, '4004');

    Livewire::actingAs($user)->test(SyncStatusSection::class)
        ->assertSee(Lang::get('sync::status.held_detail_link'));
});

it('counts a refused record once, not once per field the create carried', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = agreementUser();
    $userId = (int) $user->id;
    agreementSettledHousehold($db, $userId);

    foreach (['amount_minor', 'currency', 'posted_at', 'description'] as $field) {
        agreementRefusal($db, $userId, QuarantineReason::MissingReference, '4005', $field);
    }

    Livewire::actingAs($user)->test(SyncStatusSection::class)
        ->assertSee(Lang::choice('sync::status.held', 1));
});

// A refusal a pass retires and one it never takes again are different facts
// about the reader's data, and the surface that reports a loss must not be the
// one that reports a wait.
it('keeps a refusal nothing retries apart from a hold that is still answerable', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $recoverableReader = (int) agreementUser()->id;
    $terminalReader = (int) agreementUser()->id;

    agreementSettledHousehold($db, $recoverableReader);
    agreementSettledHousehold($db, $terminalReader);

    agreementRefusal($db, $recoverableReader, QuarantineReason::MissingReference, '4006');
    agreementRefusal($db, $terminalReader, QuarantineReason::UnplaceableCollision, '4007');

    /** @var SyncStatusService $status */
    $status = app(SyncStatusService::class);

    expect($status->overallStatus($recoverableReader))
        ->not->toBe($status->overallStatus($terminalReader));
});

// Terminal outranks recoverable for the reason Withheld outranks Behind: what
// decides the ladder is what clears the state, and nothing clears this one.
it('reports the half no pass takes again when both halves are here', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $userId = (int) agreementUser()->id;
    agreementSettledHousehold($db, $userId);

    agreementRefusal($db, $userId, QuarantineReason::MissingReference, '4008');
    agreementRefusal($db, $userId, QuarantineReason::UnplaceableCollision, '4009');

    /** @var SyncStatusService $status */
    $status = app(SyncStatusService::class);

    $terminalOnly = (int) agreementUser()->id;
    agreementSettledHousehold($db, $terminalOnly);
    agreementRefusal($db, $terminalOnly, QuarantineReason::UnplaceableCollision, '4010');

    expect($status->overallStatus($userId))->toBe($status->overallStatus($terminalOnly));
});

// The recoverable half had no block of its own: the notice draws only what
// QuarantineOutcome speaks for, and that is the terminal set by construction.
it('shows the reader what is held, not only what was lost', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = agreementUser();
    agreementSettledHousehold($db, (int) $user->id);

    agreementRefusal($db, (int) $user->id, QuarantineReason::MissingReference, '4011');

    $rendered = Livewire::actingAs($user)->test(SyncQuarantineNotice::class);

    $rendered->assertSee(Lang::get('sync::quarantine.held.body'));

    foreach (QuarantineOutcome::cases() as $outcome) {
        $rendered->assertDontSee(Lang::get($outcome->bodyKey()));
    }
});

// A count whose link goes nowhere is the silence it replaced, wearing a
// underline. The two files are edited independently, so the anchor and its
// target are pinned to each other here.
it('points the reader at an element the notice actually draws', function (): void {
    $status = (string) file_get_contents(base_path('Modules/Sync/Resources/views/livewire/sync-status-section.blade.php'));
    $notice = (string) file_get_contents(base_path('Modules/Sync/Resources/views/livewire/sync-quarantine-notice.blade.php'));

    expect($status)->toContain('#sync-refused-changes')
        ->and($notice)->toContain('id="sync-refused-changes"');
});
