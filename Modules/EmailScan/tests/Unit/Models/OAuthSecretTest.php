<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\EmailScan\Models\OAuthSecret;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $this->db = $db;

    $this->user = User::query()->create([
        'username' => 'oauth-secret-fixture',
        'password' => 'fixture-password',
        'period_start_day' => 1,
    ]);
});

it('roundtrips client_secret through the encrypted cast', function (): void {
    $plaintext = 'secret-value-'.bin2hex(random_bytes(8));

    $secret = OAuthSecret::query()->create([
        'user_id' => $this->user->id,
        'provider' => 'gmail',
        'client_id' => 'client-id-value',
        'client_secret' => $plaintext,
        'redirect_uri' => 'http://127.0.0.1:8000/oauth/callback/gmail',
        'tokens_blob' => null,
    ]);

    $reloaded = OAuthSecret::query()->findOrFail($secret->id);
    expect($reloaded->client_secret)->toBe($plaintext);

    $cipher = $this->db->connection()
        ->table('oauth_secrets')
        ->where('id', $secret->id)
        ->value('client_secret');

    expect($cipher)->toBeString();
    expect($cipher)->not->toContain($plaintext);
    expect(strlen((string) $cipher))->toBeGreaterThan(20);
});

it('roundtrips tokens_blob through the encrypted cast', function (): void {
    $plaintext = '{"access_token":"at-'.bin2hex(random_bytes(8)).'","refresh_token":"rt-value"}';

    $secret = OAuthSecret::query()->create([
        'user_id' => $this->user->id,
        'provider' => 'microsoft',
        'client_id' => 'client-id-value',
        'client_secret' => 'a-secret',
        'redirect_uri' => 'http://127.0.0.1:8000/oauth/callback/microsoft',
        'tokens_blob' => $plaintext,
    ]);

    $reloaded = OAuthSecret::query()->findOrFail($secret->id);
    expect($reloaded->tokens_blob)->toBe($plaintext);

    $cipher = $this->db->connection()
        ->table('oauth_secrets')
        ->where('id', $secret->id)
        ->value('tokens_blob');

    expect($cipher)->toBeString();
    expect($cipher)->not->toContain($plaintext);
    expect($cipher)->not->toContain('refresh_token');
    expect(strlen((string) $cipher))->toBeGreaterThan(20);
});
