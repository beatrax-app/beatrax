<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Modules\Core\Models\User;
use Modules\Core\Models\UserPreference;
use Modules\Core\Public\Services\UserPreferenceWriter;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpLogWriter;
use Modules\Sync\Internal\OpLog\OpType;

uses(RefreshDatabase::class);

/*
 * One row per user, holding the calendar account filters, both index view
 * modes and the skipped-update list. It had merge rules and no capture, so the
 * settings a user chose after pairing stayed on the device they chose them on:
 * the phone kept re-showing an update they had already skipped on the desktop.
 *
 * Four Livewire components wrote this row, each with its own updateOrCreate.
 * The capture lives on the shared writer they now all go through, because the
 * same dispatch pasted four times is the one the fifth caller forgets.
 */

function preferenceSyncUser(): User
{
    return User::query()->create([
        'username' => 'pref-sync-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// Binds the device identity the capture listener resolves, and hands back the
// public key so the same history can be verified as a peer would verify it.
function bindPreferenceSyncWriter(int $userId): string
{
    $keypair = sodium_crypto_sign_keypair();
    $publicKey = sodium_crypto_sign_publickey($keypair);

    app()->instance(OpLogWriter::class, app(OpLogWriter::class, [
        'deviceId' => 'preference-device',
        'userId' => $userId,
        'secretKey' => sodium_crypto_sign_secretkey($keypair),
        'publicKey' => $publicKey,
    ]));

    return bin2hex($publicKey);
}

/** @return Collection<int, stdClass> */
function preferenceOps(int $userId)
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    return $db->connection()->table('op_log_entries')
        ->where('user_id', $userId)
        ->where('table_name', 'user_preferences')
        ->get();
}

it('captures the preference row the first time it is written', function (): void {
    $user = preferenceSyncUser();
    bindPreferenceSyncWriter((int) $user->id);

    app(UserPreferenceWriter::class)->write((int) $user->id, ['reports_index_view' => 'list']);

    $ops = preferenceOps((int) $user->id);

    expect($ops)->not->toBeEmpty('a preference that reaches no op log can never reach a peer')
        ->and($ops->pluck('op_type')->unique()->all())->toBe(['create_row'])
        // The whole row, not just the column written: the peer has never held
        // one, so it needs the defaults this device materialised too.
        ->and($ops->pluck('field')->all())->toContain(
            'user_id',
            'reports_index_view',
            'counterparty_index_view',
            'skipped_update_versions',
        );
});

it('captures a later change as one op per column written', function (): void {
    $user = preferenceSyncUser();
    app(UserPreferenceWriter::class)->write((int) $user->id, ['reports_index_view' => 'list']);

    // Bound after the create so only the second write's ops are in view.
    bindPreferenceSyncWriter((int) $user->id);

    app(UserPreferenceWriter::class)->write((int) $user->id, [
        'calendar_entries_accounts' => [4, 9],
        'calendar_balance_accounts' => [4],
    ]);

    $ops = preferenceOps((int) $user->id);

    expect($ops->pluck('op_type')->unique()->all())->toBe(['set'])
        ->and($ops->pluck('field')->sort()->values()->all())
        ->toBe(['calendar_balance_accounts', 'calendar_entries_accounts']);
});

it('rebuilds the preference row on a peer that never held one', function (): void {
    $user = preferenceSyncUser();
    $publicKeyHex = bindPreferenceSyncWriter((int) $user->id);

    $writer = app(UserPreferenceWriter::class);
    $writer->write((int) $user->id, ['counterparty_index_view' => 'list']);
    $writer->write((int) $user->id, [
        'skipped_update_versions' => ['0.1.1'],
        'calendar_balance_accounts' => [4, 9],
    ]);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $connection = $db->connection();

    // Stand in for the receiving device: the same signed history against a
    // database whose preference row does not exist at all, which is the phone
    // that kept offering an update the desktop had already been told to skip.
    $connection->table('user_preferences')->where('user_id', $user->id)->delete();

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
        deviceKeys: ['preference-device' => $publicKeyHex],
        rules: new MergeRulesRegistry,
    ))->replay($entries, (int) $user->id);

    $rebuilt = UserPreference::withoutGlobalScopes()->where('user_id', $user->id)->first();

    expect($rebuilt)->not->toBeNull('the peer has to end up holding the preference row itself')
        ->and($rebuilt->counterparty_index_view)->toBe('list')
        // A JSON column travels as the stored text, so it has to come back as
        // a list rather than as the string that carried it.
        ->and($rebuilt->skipped_update_versions)->toBe(['0.1.1'])
        ->and($rebuilt->calendar_balance_accounts)->toBe([4, 9]);
});

it('keeps every preference write behind the shared writer', function (): void {
    $offenders = [];

    /** @var iterable<SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules')));

    foreach ($files as $file) {
        $path = $file->getPathname();

        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        // Seeders and migrations are how demo and schema-level rows are meant
        // to arrive, and the writer itself is the one place that may write.
        if (preg_match('#/(tests|Database/Seeders|Database/Migrations)/#', $path) === 1) {
            continue;
        }

        if (str_ends_with($path, 'Public/Services/UserPreferenceWriter.php')) {
            continue;
        }

        $source = (string) file_get_contents($path);

        $touchesModel = str_contains($source, 'use Modules\Core\Models\UserPreference;')
            && preg_match('/(updateOrCreate|firstOrCreate)\(|->save\(\)/', $source) === 1;
        $touchesTable = preg_match("/table\('user_preferences'\)\s*->\s*(insert|update|upsert|delete)/", $source) === 1;

        if ($touchesModel || $touchesTable) {
            $offenders[] = str_replace(base_path().'/', '', $path);
        }
    }

    expect($offenders)->toBe([], sprintf(
        "These write user_preferences without going through UserPreferenceWriter, so the change never reaches a peer:\n  - %s",
        implode("\n  - ", $offenders),
    ));
});
