<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// 404, not 403: the response must not confirm that an inbox row with that id
// exists at all.

beforeEach(function (): void {
    $this->path = storage_path('app/secrets/email-oauth.json');
    if (is_file($this->path)) {
        @unlink($this->path);
    }
});

afterEach(function (): void {
    if (is_file($this->path)) {
        @unlink($this->path);
    }
});

function cuiUser(string $username): User
{
    return User::query()->create([
        'username' => str_contains($username, '@') ? (string) strtok($username, '@') : $username,
        'password' => 'fixture',
        'period_start_day' => 1,
    ]);
}

it('cross-user reconnect attempt returns 404 — never leaks the foreign inbox', function (): void {
    $userA = cuiUser('user-a@example.com');
    $userB = cuiUser('user-b@example.com');

    // The secrets store is per-user, so a provider client cannot be saved
    // before someone is bound to the guard.
    $this->actingAs($userB);

    /** @var OAuthSecretsRepository $secrets */
    $secrets = $this->app->make(OAuthSecretsRepository::class);
    $secrets->saveProviderClient(
        'gmail',
        'fake-client-id.apps.googleusercontent.com',
        'GOCSPX-fake-secret',
        'http://127.0.0.1:8000/oauth/callback/gmail',
    );

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $now = CarbonImmutable::now()->toDateTimeString();

    $inboxAId = (int) $db->connection()->table('inboxes')->insertGetId([
        'user_id' => $userA->id,
        'provider' => 'gmail',
        'email' => 'user-a@example.com',
        'backfill_window_months' => 3,
        'backfill_progress' => null,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $db->connection()->table('inbox_scan_state')->insert([
        'user_id' => $userA->id,
        'inbox_id' => $inboxAId,
        'folder' => 'INBOX',
        'status' => 'idle',
        'retry_attempts' => 0,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $this->withoutExceptionHandling();

    expect(function () use ($inboxAId): void {
        $this->get('/oauth/connect/gmail?inbox_id='.$inboxAId);
    })->toThrow(NotFoundHttpException::class);

    $check = $db->connection()->table('inboxes')->where('id', $inboxAId)->first(['user_id', 'email']);
    expect($check)->not->toBeNull();
    expect((int) $check->user_id)->toBe($userA->id);
    expect($check->email)->toBe('user-a@example.com');
});

it('GET /oauth/connect/gmail without a configured client redirects to /inboxes with a flash', function (): void {
    $user = cuiUser('no-client@example.com');
    $this->actingAs($user);

    $response = $this->get('/oauth/connect/gmail');

    $response->assertRedirect(route('inboxes.index'));
    $response->assertSessionHas('oauth_failed');
});

it('GET /oauth/connect/unknown-provider returns 404', function (): void {
    $user = cuiUser('unknown@example.com');
    $this->actingAs($user);

    /** @var OAuthSecretsRepository $secrets */
    $secrets = $this->app->make(OAuthSecretsRepository::class);
    $secrets->saveProviderClient(
        'gmail',
        'x.apps.googleusercontent.com',
        'GOCSPX-y',
        'http://127.0.0.1:8000/oauth/callback/gmail',
    );

    $this->withoutExceptionHandling();
    expect(function (): void {
        $this->get('/oauth/connect/yahoo');
    })->toThrow(NotFoundHttpException::class);
});
