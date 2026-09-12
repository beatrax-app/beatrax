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

// One row per user, with merge rules but no capture, so a setting chosen after
// pairing stayed on the device that chose it — the phone kept re-showing an
// update already skipped on the desktop. The capture lives on the shared writer
// now, because the same dispatch pasted into four callers is what a fifth forgets.

function preferenceSyncUser(): User
{
    return User::query()->create([
        'username' => 'pref-sync-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// Hands the public key back so the captured history can be verified the way a
// peer would verify it.
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

const USER_PREFERENCE_WRITE_VERBS = '(insert|insertGetId|update|updateOrInsert|upsert|delete|updateOrCreate|firstOrCreate|createOrFirst|create|save)';

// Whether a file writes user_preferences outside the shared writer.
//
// Both halves used to ask about a spelling. The table half required the verb to
// sit directly against `table('user_preferences')`, and 76% of the query-builder
// writes in this tree put a `->where()` in between — including both existing
// call sites. The model half keyed on the exact import line, so a fully
// qualified `\Modules\Core\Models\UserPreference::query()` named it without
// ever matching. Two writes that leave a preference on one device forever were
// planted, one of each shape, and this rule stayed green.
function userPreferenceWritesIn(string $source): bool
{
    // Read to the end of the statement rather than to the next arrow, so a
    // chain broken over five lines is the same chain.
    if (preg_match("/table\(\s*'user_preferences'\s*\)(.*?);/s", $source, $chain) === 1
        && preg_match('/->\s*'.USER_PREFERENCE_WRITE_VERBS.'\s*\(/', $chain[1]) === 1) {
        return true;
    }

    // The class as a reference, however it was reached: an import, an alias, or
    // a fully qualified name at the call site.
    return preg_match('/UserPreference\s*::\s*(query|where|find|create|updateOrCreate|firstOrCreate)\s*\(/', $source) === 1
        && preg_match('/->\s*'.USER_PREFERENCE_WRITE_VERBS.'\s*\(|->save\(\)/', $source) === 1;
}

it('reads a preference write in every shape the tree writes one', function (): void {
    expect(userPreferenceWritesIn("<?php \$db->table('user_preferences')->where('user_id', 1)->update(['a' => 1]);"))->toBeTrue('a where() between the table and the verb is the ordinary spelling, not an exotic one')
        ->and(userPreferenceWritesIn("<?php \$db->table('user_preferences')\n    ->where('user_id', \$id)\n    ->updateOrInsert(['k' => 1], ['v' => 2]);"))->toBeTrue('a chain broken over lines is the same chain')
        ->and(userPreferenceWritesIn('<?php \Modules\Core\Models\UserPreference::query()->updateOrCreate([], []);'))->toBeTrue('a fully qualified name never appears as an import line')
        ->and(userPreferenceWritesIn("<?php \$db->table('user_preferences')->where('user_id', 1)->first();"))->toBeFalse('a read is not a write')
        ->and(userPreferenceWritesIn("<?php \$db->table('accounts')->where('user_id', 1)->update(['a' => 1]);"))->toBeFalse('another table is not this one');
});

it('keeps every preference write behind the shared writer', function (): void {
    $offenders = [];
    $walked = 0;
    $sawTheWriter = false;

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

        $walked++;
        $source = (string) file_get_contents($path);

        if (! userPreferenceWritesIn($source)) {
            continue;
        }

        if (str_ends_with($path, 'Public/Services/UserPreferenceWriter.php')) {
            $sawTheWriter = true;

            continue;
        }

        $offenders[] = str_replace(base_path().'/', '', $path);
    }

    expect($walked)->toBeGreaterThan(3_000, 'The walk opened '.$walked.' files, too few to be this tree.');

    // The positive control: an empty offender list means "nobody else writes it"
    // only while the one file that does is still recognised as writing it.
    expect($sawTheWriter)->toBeTrue(
        'UserPreferenceWriter was not recognised as writing user_preferences, so the reader above matches nothing and the list below says nothing.',
    );

    sort($offenders);

    expect($offenders)->toBe([], sprintf(
        "These write user_preferences without going through UserPreferenceWriter, so the change never reaches a peer:\n  - %s",
        implode("\n  - ", $offenders),
    ));
});
