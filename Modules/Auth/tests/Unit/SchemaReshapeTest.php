<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// RefreshDatabase has already run every migration against the test connection,
// so these introspect the live schema rather than re-running the migrator.

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;
});

it('migrates users table: no email column; username unique', function (): void {
    $schema = $this->db->connection()->getSchemaBuilder();

    expect($schema->hasColumn('users', 'email'))->toBeFalse();
    expect($schema->hasColumn('users', 'username'))->toBeTrue();

    $indexNames = collect($this->db->connection()->select(
        "SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='users'",
    ))->pluck('name')->all();

    $usernameUnique = collect($indexNames)->first(
        static fn (string $name): bool => str_contains($name, 'users') && str_contains($name, 'username'),
    );
    expect($usernameUnique)->not->toBeNull('expected a unique index on users.username');
});

it('users table has is_developer and force_password_change columns', function (): void {
    $schema = $this->db->connection()->getSchemaBuilder();

    expect($schema->hasColumn('users', 'is_developer'))->toBeTrue();
    expect($schema->hasColumn('users', 'force_password_change_at_next_login'))->toBeTrue();
});

it('user_recovery_codes table has expected columns and no updated_at', function (): void {
    $schema = $this->db->connection()->getSchemaBuilder();

    expect($schema->hasTable('user_recovery_codes'))->toBeTrue();

    foreach (['id', 'user_id', 'code_hash', 'used_at', 'created_at'] as $column) {
        expect($schema->hasColumn('user_recovery_codes', $column))->toBeTrue(
            "Expected user_recovery_codes column '{$column}' to exist",
        );
    }

    expect($schema->hasColumn('user_recovery_codes', 'updated_at'))->toBeFalse();
});

it('oauth_secrets table has expected columns', function (): void {
    $schema = $this->db->connection()->getSchemaBuilder();

    expect($schema->hasTable('oauth_secrets'))->toBeTrue();

    $columns = [
        'id', 'user_id', 'provider', 'client_id', 'client_secret',
        'redirect_uri', 'tokens_blob', 'created_at', 'updated_at',
    ];

    foreach ($columns as $column) {
        expect($schema->hasColumn('oauth_secrets', $column))->toBeTrue(
            "Expected oauth_secrets column '{$column}' to exist",
        );
    }
});

it('oauth_secrets table has unique (user_id, provider) index', function (): void {
    $indexes = $this->db->connection()->select(
        "SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='oauth_secrets'",
    );

    $uniqueIndex = collect($indexes)
        ->pluck('name')
        ->first(static fn (string $name): bool => str_contains($name, 'oauth_secrets')
            && str_contains($name, 'user_id')
            && str_contains($name, 'provider'));

    expect($uniqueIndex)->not->toBeNull('expected a unique (user_id, provider) index');
});

it('oauth_secrets provider trigger rejects invalid value', function (): void {
    $userId = $this->db->connection()->table('users')->insertGetId([
        'username' => 'trigger-fixture',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $caught = null;
    try {
        $this->db->connection()->table('oauth_secrets')->insert([
            'user_id' => $userId,
            'provider' => 'apple',
            'client_id' => 'cid',
            'client_secret' => 'secret',
            'redirect_uri' => 'http://127.0.0.1:8000/oauth/callback/apple',
            'tokens_blob' => null,
            'created_at' => '2026-05-20 00:00:00',
            'updated_at' => '2026-05-20 00:00:00',
        ]);
    } catch (QueryException $e) {
        $caught = $e;
    }

    expect($caught)->not->toBeNull();
    expect($caught?->getMessage())->toContain('Invalid oauth_secrets.provider value');
    expect($this->db->connection()->table('oauth_secrets')->count())->toBe(0);
});

it('oauth_secrets provider trigger accepts gmail and microsoft', function (string $provider): void {
    $userId = $this->db->connection()->table('users')->insertGetId([
        'username' => 'accepts-fixture-'.$provider,
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);

    $id = $this->db->connection()->table('oauth_secrets')->insertGetId([
        'user_id' => $userId,
        'provider' => $provider,
        'client_id' => 'cid',
        'client_secret' => 'secret',
        'redirect_uri' => 'http://127.0.0.1:8000/oauth/callback/'.$provider,
        'tokens_blob' => null,
        'created_at' => '2026-05-20 00:00:00',
        'updated_at' => '2026-05-20 00:00:00',
    ]);

    $row = $this->db->connection()->table('oauth_secrets')->where('id', $id)->first();
    expect($row?->provider)->toBe($provider);
})->with(['gmail', 'microsoft']);
