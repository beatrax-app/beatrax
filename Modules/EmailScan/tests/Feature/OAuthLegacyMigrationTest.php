<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\Listeners\EmitOAuthReauthRequiredAlert;
use Modules\EmailScan\Models\OAuthSecret;

// Both the migration and the listener resolve paths through
// UserDataPathService, so NATIVEPHP_STORAGE_PATH is pointed at a throwaway
// directory to keep the developer's real secrets directory out of reach.

function oauthLegacyMigration(): object
{
    return require base_path(
        'Modules/Auth/Database/Migrations/2026_05_20_000002_rename_legacy_email_oauth_json.php'
    );
}

beforeEach(function (): void {
    $this->tmpStorage = sys_get_temp_dir().'/oauth-legacy-'.bin2hex(random_bytes(6));
    $this->files = new Filesystem;
    $this->files->makeDirectory($this->tmpStorage.'/app/secrets', 0700, recursive: true);
    putenv('NATIVEPHP_STORAGE_PATH='.$this->tmpStorage);

    $this->legacy = $this->tmpStorage.'/app/secrets/email-oauth.json';
    $this->backup = $this->tmpStorage.'/app/secrets/email-oauth.json.pre-phase-12.bak';
    $this->readme = $this->tmpStorage.'/app/secrets/README.md';
});

afterEach(function (): void {
    putenv('NATIVEPHP_STORAGE_PATH');

    if ($this->files->isDirectory($this->tmpStorage)) {
        $this->files->deleteDirectory($this->tmpStorage);
    }
});

it('renames a legacy email-oauth.json to the .bak rollback file at mode 0600', function (): void {
    $this->files->put($this->legacy, '{"providers":{}}');

    oauthLegacyMigration()->up();

    expect($this->files->exists($this->legacy))->toBeFalse();
    expect($this->files->exists($this->backup))->toBeTrue();
    expect($this->files->exists($this->readme))->toBeTrue();

    if (PHP_OS_FAMILY !== 'Windows') {
        $mode = fileperms($this->backup) & 0777;
        expect(decoct($mode))->toBe('600');
    }
});

it('is idempotent — a second run does not overwrite the .bak or error', function (): void {
    $this->files->put($this->legacy, '{"original":true}');
    oauthLegacyMigration()->up();
    $backupContents = $this->files->get($this->backup);

    // A stray legacy file reappearing must not clobber the rollback copy.
    $this->files->put($this->legacy, '{"original":false}');
    oauthLegacyMigration()->up();

    expect($this->files->get($this->backup))->toBe($backupContents);
});

it('is a no-op when no legacy file exists', function (): void {
    oauthLegacyMigration()->up();

    expect($this->files->exists($this->backup))->toBeFalse();
    expect($this->files->exists($this->readme))->toBeFalse();
});

it('writes one re-authorize warning when the user has no oauth_secrets and the .bak exists', function (): void {
    $user = User::query()->create([
        'username' => 'reauth-fixture',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);
    $this->files->put($this->backup, 'rollback');

    $listener = $this->app->make(EmitOAuthReauthRequiredAlert::class);
    $listener->handle();

    $alerts = SystemAlert::query()
        ->where('user_id', $user->id)
        ->where('kind', 'oauth.reauth_required')
        ->get();
    expect($alerts)->toHaveCount(1);
    expect($alerts->first()->severity)->toBe('warning');
    expect($alerts->first()->message)->toContain('Re-authorize Gmail and Microsoft');

    // Second invocation must not add a duplicate un-acknowledged row.
    $listener->handle();
    expect(SystemAlert::query()
        ->where('user_id', $user->id)
        ->where('kind', 'oauth.reauth_required')
        ->count())->toBe(1);
});

it('does not fire the alert when the user already has an oauth_secrets row', function (): void {
    $user = User::query()->create([
        'username' => 'has-secrets-fixture',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);
    $this->files->put($this->backup, 'rollback');

    OAuthSecret::query()->create([
        'user_id' => $user->id,
        'provider' => 'gmail',
        'client_id' => 'id',
        'client_secret' => 'secret',
        'redirect_uri' => 'http://127.0.0.1/cb',
        'tokens_blob' => null,
    ]);

    $this->app->make(EmitOAuthReauthRequiredAlert::class)->handle();

    expect(SystemAlert::query()
        ->where('user_id', $user->id)
        ->where('kind', 'oauth.reauth_required')
        ->count())->toBe(0);
});

it('does not fire the alert when no .bak rollback file exists', function (): void {
    $user = User::query()->create([
        'username' => 'no-bak-fixture',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    $this->app->make(EmitOAuthReauthRequiredAlert::class)->handle();

    expect(SystemAlert::query()
        ->where('user_id', $user->id)
        ->where('kind', 'oauth.reauth_required')
        ->count())->toBe(0);
});
