<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Auth\Public\Actions\DeleteAccountAction;
use Modules\Auth\Public\Http\Livewire\DeleteAccountSection;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\Lang;

// Not that the users row goes, but that everything it owned goes with it, that
// a household member's identical-looking data does not, and that nobody is
// left able to administer the device.

function deleteAccountUser(string $username, bool $administrator): User
{
    return User::create([
        'username' => $username,
        'password' => $username.'-password-12',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => $administrator,
    ]);
}

function deleteAccountSeedOwnedRows(DatabaseManager $db, User $user, string $marker): void
{
    $db->connection()->table('accounts')->insert([
        'user_id' => $user->id,
        'name' => $marker,
        'slug' => $marker,
        'kind' => 'checking',
        'iban' => 'NL00BANK'.str_pad((string) $user->id, 10, '0', STR_PAD_LEFT),
        'default_currency' => 'EUR',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    $db->connection()->table('user_recovery_codes')->insert([
        'user_id' => $user->id,
        'code_hash' => hash('sha256', $marker),
        'used_at' => null,
        'created_at' => '2026-01-01 00:00:00',
    ]);

    $db->connection()->table('user_preferences')->insert([
        'user_id' => $user->id,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);
}

// Stands in for a module shipping a table the purge cannot reach: the guard
// must roll the whole deletion back rather than half-erase an account.
function deleteAccountBlockPurge(DatabaseManager $db, int $userId): void
{
    $connection = $db->connection();

    $connection->statement('CREATE TABLE purge_probe (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL)');
    $connection->statement(
        "CREATE TRIGGER purge_probe_block BEFORE DELETE ON purge_probe
         BEGIN SELECT RAISE(ABORT, 'purge_probe refuses deletes'); END",
    );
    $connection->table('purge_probe')->insert(['user_id' => $userId]);
}

/** @return int rows still owned by this user across every user-scoped table */
function deleteAccountOwnedRowCount(DatabaseManager $db, int $userId): int
{
    $connection = $db->connection();
    $schema = $connection->getSchemaBuilder();
    $total = 0;

    foreach ($schema->getTableListing() as $table) {
        $name = str_contains($table, '.') ? substr($table, (int) strrpos($table, '.') + 1) : $table;

        if ($name === 'users' || ! in_array('user_id', $schema->getColumnListing($name), true)) {
            continue;
        }

        $total += $connection->table($name)->where('user_id', $userId)->count();
    }

    return $total;
}

it('erases every row the account owned and leaves the household member untouched', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $owner = deleteAccountUser('owner', administrator: true);
    $partner = deleteAccountUser('partner', administrator: false);

    deleteAccountSeedOwnedRows($db, $owner, 'owner-marker');
    deleteAccountSeedOwnedRows($db, $partner, 'partner-marker');

    expect(deleteAccountOwnedRowCount($db, $owner->id))->toBeGreaterThan(0);

    $this->actingAs($owner);

    app(DeleteAccountAction::class)($owner, 'owner-password-12');

    expect(User::query()->where('id', $owner->id)->exists())->toBeFalse();
    expect(deleteAccountOwnedRowCount($db, $owner->id))->toBe(0);

    expect(User::query()->where('id', $partner->id)->exists())->toBeTrue();
    expect(deleteAccountOwnedRowCount($db, $partner->id))->toBeGreaterThan(0);
    expect($db->connection()->table('accounts')->where('name', 'partner-marker')->count())->toBe(1);
});

it('promotes the oldest surviving account when the only administrator leaves', function (): void {
    // Otherwise the device is left with users on it, nobody able to add or
    // reset one, and /signup still closed because a user row exists.
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $owner = deleteAccountUser('owner', administrator: true);
    $first = deleteAccountUser('partner-one', administrator: false);
    $second = deleteAccountUser('partner-two', administrator: false);

    $this->actingAs($owner);

    app(DeleteAccountAction::class)($owner, 'owner-password-12');

    expect($first->fresh()?->is_developer)->toBeTrue();
    expect($second->fresh()?->is_developer)->toBeFalse();
});

it('leaves a second administrator in place rather than promoting anyone', function (): void {
    $owner = deleteAccountUser('owner', administrator: true);
    $coAdmin = deleteAccountUser('co-admin', administrator: true);
    $partner = deleteAccountUser('partner', administrator: false);

    $this->actingAs($owner);

    app(DeleteAccountAction::class)($owner, 'owner-password-12');

    expect($coAdmin->fresh()?->is_developer)->toBeTrue();
    expect($partner->fresh()?->is_developer)->toBeFalse();
});

it('erases the sync identity and group keyring that belong to the account', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $owner = deleteAccountUser('owner', administrator: true);
    $partner = deleteAccountUser('partner', administrator: false);

    $paths = app(UserDataPathService::class);

    foreach ([$owner->id, $partner->id] as $id) {
        foreach (['sync/identity', 'sync/gdk'] as $directory) {
            $path = $paths->appRelative($directory.'/'.$id.'.enc');
            @mkdir(dirname($path), 0o700, true);
            file_put_contents($path, 'key-material-'.$id);
        }
    }

    $this->actingAs($owner);

    app(DeleteAccountAction::class)($owner, 'owner-password-12');

    expect($paths->appRelative('sync/identity/'.$owner->id.'.enc'))->not->toBeFile();
    expect($paths->appRelative('sync/gdk/'.$owner->id.'.enc'))->not->toBeFile();

    expect($paths->appRelative('sync/identity/'.$partner->id.'.enc'))->toBeFile();
    expect($paths->appRelative('sync/gdk/'.$partner->id.'.enc'))->toBeFile();
});

it('refuses a wrong password and changes nothing', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $owner = deleteAccountUser('owner', administrator: true);
    deleteAccountSeedOwnedRows($db, $owner, 'owner-marker');

    $this->actingAs($owner);

    expect(fn () => app(DeleteAccountAction::class)($owner, 'not-the-password'))
        ->toThrow(ValidationException::class);

    expect(User::query()->where('id', $owner->id)->exists())->toBeTrue();
    expect(deleteAccountOwnedRowCount($db, $owner->id))->toBeGreaterThan(0);
});

// A box left empty and a box filled in wrongly are different states. Told
// "That password is not correct." for a blank field, a reader goes looking for
// the right password rather than at the field they skipped.
it('names the empty password box rather than calling it not correct', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $owner = deleteAccountUser('owner', administrator: true);
    deleteAccountSeedOwnedRows($db, $owner, 'owner-marker');

    $this->actingAs($owner);

    $messages = [];

    try {
        app(DeleteAccountAction::class)($owner, '');
    } catch (ValidationException $e) {
        $messages = $e->validator->errors()->get('password');
    }

    expect($messages)->toBe(['Enter your password.']);
    expect(User::query()->where('id', $owner->id)->exists())->toBeTrue();
});

it('offers the deletion path from settings and only deletes after confirming', function (): void {
    $owner = deleteAccountUser('owner', administrator: true);
    deleteAccountUser('partner', administrator: false);

    Livewire::actingAs($owner)
        ->test(DeleteAccountSection::class)
        ->assertSee('Delete my account and data')
        ->assertSet('confirming', false)
        ->call('startConfirm')
        ->assertSet('confirming', true)
        ->assertSee('Yes, delete everything');

    expect(User::query()->where('username', 'owner')->exists())->toBeTrue();
});

it('says out loud that a paired device keeps its own copy', function (): void {
    // The deletion must never imply it reached the other devices.
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $owner = deleteAccountUser('owner', administrator: true);

    $db->connection()->table('device_registry')->insert([
        'user_id' => $owner->id,
        'device_id' => '11111111-1111-4111-8111-111111111111',
        'name' => 'Kitchen laptop',
        'ed25519_public_key_hex' => str_repeat('a', 64),
        'x25519_public_key_hex' => str_repeat('b', 64),
        'safety_number_words' => 'one two three',
        'is_self' => 0,
        'paired_at' => '2026-01-01 00:00:00',
        'confirmed_at' => '2026-01-01 00:00:00',
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
    ]);

    Livewire::actingAs($owner)
        ->test(DeleteAccountSection::class)
        ->assertSee('Kitchen laptop')
        ->assertSee('Your other devices keep their own copy');
});

it('rolls the whole deletion back when one table cannot be cleared', function (): void {
    // Half an erased account is the worst outcome available: the user believes
    // they are gone and the ledger is still on the disk.
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $owner = deleteAccountUser('owner', administrator: true);
    deleteAccountSeedOwnedRows($db, $owner, 'owner-marker');
    deleteAccountBlockPurge($db, $owner->id);

    $before = deleteAccountOwnedRowCount($db, $owner->id);

    $this->actingAs($owner);

    expect(fn () => app(DeleteAccountAction::class)($owner, 'owner-password-12'))
        ->toThrow(RuntimeException::class);

    expect(User::query()->where('id', $owner->id)->exists())->toBeTrue();
    expect(deleteAccountOwnedRowCount($db, $owner->id))->toBe($before);
});

it('reports a failure instead of pretending the account is gone', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $owner = deleteAccountUser('owner', administrator: true);
    deleteAccountSeedOwnedRows($db, $owner, 'owner-marker');
    deleteAccountBlockPurge($db, $owner->id);

    Livewire::actingAs($owner)
        ->test(DeleteAccountSection::class)
        ->call('startConfirm')
        ->set('password', 'owner-password-12')
        ->call('deleteAccount')
        ->assertNoRedirect()
        ->assertSee('Your account was not deleted');

    expect(User::query()->where('username', 'owner')->exists())->toBeTrue();
});

it('keeps no plaintext password in the component after a rejected delete', function (): void {
    // A failure re-serialises the component into the wire snapshot, and a
    // mistyped password is a near-miss of the real one.
    $owner = deleteAccountUser('owner', administrator: true);

    $component = Livewire::actingAs($owner)
        ->test(DeleteAccountSection::class)
        ->call('startConfirm')
        ->set('password', 'wrong-password-entirely')
        ->call('deleteAccount')
        ->assertHasErrors('password')
        ->assertSet('password', '');

    expect($component->html())->not->toContain('wrong-password-entirely');
    expect(json_encode($component->snapshot))->not->toContain('wrong-password-entirely');
});

it('keeps no plaintext password in the component after a failed purge', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $owner = deleteAccountUser('owner', administrator: true);
    deleteAccountSeedOwnedRows($db, $owner, 'owner-marker');
    deleteAccountBlockPurge($db, $owner->id);

    $component = Livewire::actingAs($owner)
        ->test(DeleteAccountSection::class)
        ->call('startConfirm')
        ->set('password', 'owner-password-12')
        ->call('deleteAccount')
        ->assertSet('password', '');

    expect(json_encode($component->snapshot))->not->toContain('owner-password-12');
});

// Refuses one path, the way a held handle does on Windows. Narrow because this
// replaces the shared `files` binding Blade and the translator use too. Both
// spellings of refusal, because the one that reaches production is the quiet
// one: unlink() failing makes delete() return false, and never throws.
function deleteAccountRefuseFileDeletes(UserDataPathService $paths, string $relative, bool $throwing = false): string
{
    $blocked = $paths->appRelative($relative);

    $real = app(Filesystem::class);
    $real->ensureDirectoryExists(dirname($blocked));
    $real->put($blocked, 'fixture');

    $files = new class($blocked, $throwing) extends Filesystem
    {
        public function __construct(private readonly string $blocked, private readonly bool $throwing) {}

        public function delete($paths): bool
        {
            if ($paths !== $this->blocked) {
                return parent::delete($paths);
            }

            if ($this->throwing) {
                throw new RuntimeException('unlink(): Resource temporarily unavailable');
            }

            return false;
        }
    };

    app()->instance('files', $files);
    app()->instance(Filesystem::class, $files);

    return $blocked;
}

// The residue tier removes trees, not files, so refusing one takes the other
// half of the Filesystem surface.
function deleteAccountRefuseTreeDeletes(UserDataPathService $paths, string $relative): string
{
    $blocked = $paths->appRelative($relative);

    $real = app(Filesystem::class);
    $real->ensureDirectoryExists($blocked);
    $real->put($blocked.'/message.eml', 'fixture');

    $files = new class($blocked) extends Filesystem
    {
        public function __construct(private readonly string $blocked) {}

        public function deleteDirectory($directory, $preserve = false): bool
        {
            return $directory === $this->blocked ? false : parent::deleteDirectory($directory, $preserve);
        }
    };

    app()->instance('files', $files);
    app()->instance(Filesystem::class, $files);

    return $blocked;
}

// The requirement is that a peer cannot put the account back, and the identity
// is what a peer would put it back through. A refused unlink that commits the
// deletion anyway reaches exactly the state the requirement forbids -- and
// reached it through a false return nothing read.
it('refuses the deletion when the sync identity it must remove survives', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $owner = deleteAccountUser('owner', administrator: true);
    deleteAccountSeedOwnedRows($db, $owner, 'owner-marker');

    $before = deleteAccountOwnedRowCount($db, $owner->id);

    $this->actingAs($owner);
    $blocked = deleteAccountRefuseFileDeletes(
        app(UserDataPathService::class),
        sprintf('sync/identity/%d.enc', $owner->id),
    );

    expect(fn () => app(DeleteAccountAction::class)($owner, 'owner-password-12'))
        ->toThrow(RuntimeException::class);

    expect(User::query()->where('id', $owner->id)->exists())->toBeTrue()
        ->and(deleteAccountOwnedRowCount($db, $owner->id))->toBe($before)
        ->and($blocked)->toBeFile();
});

it('rolls back rather than commit over a group keyring that would not go', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $owner = deleteAccountUser('owner', administrator: true);
    deleteAccountSeedOwnedRows($db, $owner, 'owner-marker');

    $this->actingAs($owner);
    deleteAccountRefuseFileDeletes(
        app(UserDataPathService::class),
        sprintf('sync/gdk/%d.enc', $owner->id),
        throwing: true,
    );

    expect(fn () => app(DeleteAccountAction::class)($owner, 'owner-password-12'))
        ->toThrow(RuntimeException::class);

    expect(User::query()->where('id', $owner->id)->exists())->toBeTrue();
});

it('tells the reader nothing changed, and nothing has', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $owner = deleteAccountUser('owner', administrator: true);
    deleteAccountSeedOwnedRows($db, $owner, 'owner-marker');

    $this->actingAs($owner);
    deleteAccountRefuseFileDeletes(
        app(UserDataPathService::class),
        sprintf('sync/identity/%d.enc', $owner->id),
    );

    Livewire::test(DeleteAccountSection::class)
        ->set('password', 'owner-password-12')
        ->call('deleteAccount')
        ->assertNoRedirect()
        ->assertSee('Your account was not deleted');

    expect(User::query()->where('id', $owner->id)->exists())->toBeTrue();
});

// Downloaded mail is the account's data and goes with it, but it is not what a
// peer restores an account from -- so a tree that will not delete is reported
// and left, rather than holding the whole deletion hostage.
it('completes the deletion when only downloaded mail survives the purge', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $owner = deleteAccountUser('owner', administrator: true);
    deleteAccountSeedOwnedRows($db, $owner, 'owner-marker');

    // A household member stays, so the device-wide tier is not reached and the
    // refused directory is the only thing the purge could not do.
    deleteAccountUser('partner', administrator: false);

    $this->actingAs($owner);
    $blocked = deleteAccountRefuseTreeDeletes(
        app(UserDataPathService::class),
        sprintf('inbox/%d', $owner->id),
    );

    Livewire::test(DeleteAccountSection::class)
        ->set('password', 'owner-password-12')
        ->call('deleteAccount')
        ->assertSet('failure', null)
        ->assertRedirect('/');

    expect(User::query()->where('id', $owner->id)->exists())->toBeFalse()
        ->and(deleteAccountOwnedRowCount($db, $owner->id))->toBe(0)
        ->and(auth()->check())->toBeFalse()
        ->and($blocked)->toBeDirectory();
});

// The aggregator credential is keyed to the account and is unlocked money
// access, so it leaves with the identity rather than waiting for the last
// account on the device to go.
it('takes the connector secret keyed to the account with it', function (): void {
    $owner = deleteAccountUser('owner', administrator: true);
    $partner = deleteAccountUser('partner', administrator: false);

    $paths = app(UserDataPathService::class);
    $files = app(Filesystem::class);

    foreach ([$owner->id, $partner->id] as $id) {
        $secret = $paths->appRelative(sprintf('secrets/open-banking/%d.json', $id));
        $files->ensureDirectoryExists(dirname($secret));
        $files->put($secret, '{"client_secret":"connector-'.$id.'"}');
    }

    $this->actingAs($owner);

    app(DeleteAccountAction::class)($owner, 'owner-password-12');

    expect($paths->appRelative(sprintf('secrets/open-banking/%d.json', $owner->id)))->not->toBeFile()
        ->and($paths->appRelative(sprintf('secrets/open-banking/%d.json', $partner->id)))->toBeFile();
});

// `jobs` carries no user_id -- the owner is serialised inside the payload --
// so the schema sweep that finds every other table is blind to it. Measured on
// a phone: deleting an account left 2,385 jobs naming it, and the account that
// signed up next queued its own work behind all of them. The screen lists what
// it removes; the queue was not on the list and was not empty.
it('takes the queued work of the account with it', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $leaving = deleteAccountUser('queue-leaving', true);
    $staying = deleteAccountUser('queue-staying', false);

    $queue = function (int $userId, string $uuid) use ($db): void {
        $db->connection()->table('jobs')->insert([
            'queue' => 'default',
            'payload' => json_encode([
                'uuid' => $uuid,
                'displayName' => 'Modules\\Anomaly\\Internal\\Jobs\\DetectAnomaliesJob',
                'data' => ['command' => 'O:48:"Modules\\Anomaly\\Internal\\Jobs\\DetectAnomaliesJob":1:{s:6:"userId";i:'.$userId.';}'],
            ], JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'available_at' => 0,
            'created_at' => 0,
        ]);
    };

    $queue($leaving->id, 'leaving-1');
    $queue($leaving->id, 'leaving-2');
    $queue($staying->id, 'staying-1');

    app(DeleteAccountAction::class)($leaving, 'queue-leaving-password-12');

    $remaining = $db->connection()->table('jobs')->pluck('payload')->all();

    expect($remaining)->toHaveCount(1);
    expect($remaining[0])->toContain('userId');
    expect($remaining[0])->toContain('i:'.$staying->id.';');
});

// The heading was static and the body conditional, so on a device with no
// paired peer -- the default install -- a 600-weight amber line read "Your
// other devices keep their own copy" directly above the sentence saying this
// is the only copy, directly above the one irreversible action in the app.
it('does not promise another copy exists on a device that has no paired peer', function (): void {
    $user = deleteAccountUser('delete-copy-claim', false);

    $rendered = Livewire::actingAs($user)->test(DeleteAccountSection::class)->html();

    expect($rendered)->toContain(Lang::get('auth::delete_account.devices_heading_none'));
    expect($rendered)->not->toContain(Lang::get('auth::delete_account.devices_heading'));
});

// The delete screen says everything above is removed from this device. Cache
// rows are keyed by user id rather than by a user_id column, so the schema
// sweep never saw them: `nav-counts:<id>` held the deleted account's own badge
// counts for the rest of its TTL. Ids are not reused -- the table is
// AUTOINCREMENT -- so no later account can read them, but they are still the
// account's data outliving the sentence that promised otherwise.
it('takes the derived cache of the account with it', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $leaving = deleteAccountUser('cache-leaving', true);
    $staying = deleteAccountUser('cache-staying', false);

    $put = function (string $key) use ($db): void {
        $db->connection()->table('cache')->insert([
            'key' => $key,
            'value' => serialize(['transactions' => 3]),
            'expiration' => time() + 3600,
        ]);
    };

    $put('nav-counts:'.$leaving->id);
    $put('savings-insights:'.$leaving->id);
    $put('nav-counts:'.$staying->id);

    app(DeleteAccountAction::class)($leaving, 'cache-leaving-password-12');

    $keys = $db->connection()->table('cache')->pluck('key')->all();

    expect($keys)->not->toContain('nav-counts:'.$leaving->id);
    expect($keys)->not->toContain('savings-insights:'.$leaving->id);
    expect($keys)->toContain('nav-counts:'.$staying->id);
});
