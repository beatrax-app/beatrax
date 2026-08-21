<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\PinVerificationService;
use Modules\Auth\Internal\Recovery\RecoveryCodeAuthenticator;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use Modules\Core\Public\Actions\AcknowledgeSystemAlert;
use Modules\Core\Public\Actions\RecordUpdateAvailableAlert;
use Modules\Core\Public\Dto\UpdateManifestDto;
use Modules\Core\Public\Services\SystemAlertWriter;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;

uses(RefreshDatabase::class);

// system_alerts is two tables wearing one name. A row with an owner says
// something about the account and travels; a row with a null owner says
// something about the machine, and the peer has its own probes and its own row
// ids, so a create would land on the wrong id and a lone SET on none at all.

function systemAlertUser(): User
{
    return User::query()->create([
        'username' => 'alert-sync-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// Hands the public key back so the captured history can be verified the way a
// peer would verify it.
function bindSystemAlertWriter(int $userId): string
{
    $keypair = sodium_crypto_sign_keypair();
    $publicKey = sodium_crypto_sign_publickey($keypair);

    app()->instance(OpLogWriter::class, app(OpLogWriter::class, [
        'deviceId' => 'system-alert-device',
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => $publicKey,
    ]));

    return bin2hex($publicKey);
}

/** @return Collection<int, stdClass> */
function systemAlertOps(int $userId)
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'system_alerts')
        ->get();
}

it('captures an owned alert the moment it is raised', function (): void {
    $user = systemAlertUser();
    bindSystemAlertWriter((int) $user->id);

    app(SystemAlertWriter::class)->raiseForUser(
        userId: (int) $user->id,
        kind: 'oauth_reconsent_required',
        severity: 'warning',
        message: 'Reconnect your Gmail',
        metadata: ['inbox_id' => 7, 'provider' => 'gmail'],
    );

    $ops = systemAlertOps((int) $user->id);

    expect($ops)->not->toBeEmpty('an owned alert that reaches no op log can never reach a peer')
        ->and($ops->pluck('op_type')->unique()->all())->toBe(['create_row'])
        ->and($ops->pluck('field')->all())->toContain('kind', 'severity', 'message', 'metadata', 'created_at');
});

it('leaves a machine-local alert off the log entirely', function (): void {
    $user = systemAlertUser();
    bindSystemAlertWriter((int) $user->id);

    app(RecordUpdateAvailableAlert::class)(new UpdateManifestDto(
        latestVersion: '0.2.0',
        sha512Hex: str_repeat('a', 128),
        publishedAt: CarbonImmutable::now(),
        channel: 'stable',
    ));

    expect(systemAlertOps((int) $user->id))->toBeEmpty(
        'a system-wide alert belongs to no one, and the peer raises its own under its own id',
    );
});

it('captures the acknowledgement of an owned alert as a set on acknowledged_at', function (): void {
    $user = systemAlertUser();
    $alert = app(SystemAlertWriter::class)->raiseForUser(
        userId: (int) $user->id,
        kind: 'open_banking_reconsent_required',
        severity: 'warning',
        message: 'Reconnect your bank',
    );

    // Bound after the create so only the acknowledgement's ops are in view.
    bindSystemAlertWriter((int) $user->id);

    app(AcknowledgeSystemAlert::class)((int) $alert->id, $user);

    $ops = systemAlertOps((int) $user->id);

    expect($ops->pluck('op_type')->unique()->all())->toBe(['set'])
        ->and($ops->pluck('field')->all())->toBe(['acknowledged_at'])
        ->and($ops->pluck('pk')->all())->toBe([(string) $alert->id]);
});

it('does not capture the acknowledgement of a machine-local alert', function (): void {
    $user = systemAlertUser();

    /** @var SystemAlert $alert */
    $alert = SystemAlert::query()->create([
        'user_id' => null,
        'kind' => 'backup_overdue',
        'severity' => 'warning',
        'message' => 'No verified backups found under the backups directory.',
    ]);

    bindSystemAlertWriter((int) $user->id);

    app(AcknowledgeSystemAlert::class)((int) $alert->id, $user);

    expect(systemAlertOps((int) $user->id))->toBeEmpty(
        'a SET naming a pk the peer never received is an op it can only quarantine',
    );
});

it('rebuilds the alert and its acknowledgement on a peer that never saw either', function (): void {
    $user = systemAlertUser();
    $publicKeyHex = bindSystemAlertWriter((int) $user->id);

    $alert = app(SystemAlertWriter::class)->raiseForUser(
        userId: (int) $user->id,
        kind: 'oauth_reconsent_required',
        severity: 'warning',
        message: 'Reconnect your Outlook',
        metadata: ['inbox_id' => 3, 'provider' => 'microsoft'],
    );

    app(AcknowledgeSystemAlert::class)((int) $alert->id, $user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $connection = $db->connection();

    // Stand in for the receiving device: the same signed history against a
    // database that never held the alert — which is the phone still showing
    // "Reconnect your Outlook" after the desktop reconnected.
    $connection->table('system_alerts')->where('user_id', $user->id)->delete();

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
        deviceKeys: ['system-alert-device' => $publicKeyHex],
        rules: new MergeRulesRegistry,
    ))->replay($entries, (int) $user->id);

    $rebuilt = $connection->table('system_alerts')->where('id', $alert->id)->first();

    expect($rebuilt)->not->toBeNull('the peer has to end up holding the alert itself')
        ->and((int) $rebuilt->user_id)->toBe((int) $user->id)
        ->and((string) $rebuilt->kind)->toBe('oauth_reconsent_required')
        ->and((string) $rebuilt->message)->toBe('Reconnect your Outlook')
        ->and($rebuilt->acknowledged_at)->not->toBeNull('an alert cleared on one device must not still be shouting on the other');
});

// The three owned alerts the app raises about a user's own security — a PIN
// lockout, a recovery code, a failed keyring re-wrap — went straight to the
// model and so reached neither the op log nor the other device.

/** Enables the app lock and hands back the PIN, so a wrong one can be offered. */
function armAppLockFor(User $user): string
{
    app(AppLockProvisioner::class)->enable((int) $user->id, '123456', 'fixture');

    return '123456';
}

it('captures the PIN hard-cap lockout alert', function (): void {
    $user = systemAlertUser();
    $this->actingAs($user);
    armAppLockFor($user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // Wound up in the database rather than through nine real attempts, each of
    // which would pay the Argon2id cost.
    $db->connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->update(['failed_attempts' => PinVerificationService::HARD_CAP - 1]);

    bindSystemAlertWriter((int) $user->id);

    app(PinVerificationService::class)->verify((int) $user->id, '000000', app(Session::class));

    expect(systemAlertOps((int) $user->id))->not->toBeEmpty(
        'the device that just locked the user out is the one device that already knows',
    );
});

it('captures the alert a corrupt PIN wrap blob raises', function (): void {
    $user = systemAlertUser();
    $this->actingAs($user);
    $pin = armAppLockFor($user);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->update(['kdf_salt' => null, 'pin_wrapped_key' => null]);

    bindSystemAlertWriter((int) $user->id);

    app(PinVerificationService::class)->verify((int) $user->id, $pin, app(Session::class));

    expect(systemAlertOps((int) $user->id))->not->toBeEmpty();
});

it('captures a consumed recovery code', function (): void {
    $user = systemAlertUser();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    // Drawn from the generator's ambiguous-free alphabet: an I, L, O, 0 or 1
    // is stripped on normalisation and the reformatted code stops matching.
    $code = 'ABCD-EFGH-JKMN-PQRS';
    $db->connection()->table('user_recovery_codes')->insert([
        'user_id' => $user->id,
        'code_hash' => app(Hasher::class)->make($code),
        'created_at' => CarbonImmutable::now(),
    ]);

    bindSystemAlertWriter((int) $user->id);

    expect(app(RecoveryCodeAuthenticator::class)->verify($user->username, $code))->not->toBeNull();
    expect(systemAlertOps((int) $user->id))->not->toBeEmpty(
        'a recovery code spent on one device is news the other device needs',
    );
});

it('captures a failed recovery-code attempt', function (): void {
    $user = systemAlertUser();
    bindSystemAlertWriter((int) $user->id);

    expect(app(RecoveryCodeAuthenticator::class)->verify($user->username, 'ZZZZ-ZZZZ-ZZZZ-ZZZZ'))->toBeNull();
    expect(systemAlertOps((int) $user->id))->not->toBeEmpty();
});
