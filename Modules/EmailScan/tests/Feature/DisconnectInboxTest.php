<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Http;
use Modules\Core\Models\User;
use Modules\EmailScan\Internal\OAuth\GoogleOAuthProvider;
use Modules\EmailScan\Public\Actions\DisconnectInbox;
use Modules\EmailScan\Public\Enums\MailProvider;
use Modules\EmailScan\Public\Services\OAuthSecretsRepository;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * DisconnectInbox is the token-revocation path: a leaked Gmail refresh token
 * cannot outlive the disconnect, and the local inbox + credentials are gone
 * afterwards. The provider revoke is best-effort — a failed revoke still
 * completes the local delete rather than stranding the row.
 */

function disconnectSetupInbox(string $email, string $provider, string $refreshToken): array
{
    $user = User::query()->create([
        'username' => 'disc-'.substr(md5($email), 0, 8),
        'password' => 'x',
        'is_developer' => true,
    ]);
    test()->actingAs($user);

    $db = app(DatabaseManager::class)->connection();
    $inboxId = $db->table('inboxes')->insertGetId([
        'user_id' => $user->id,
        'provider' => $provider,
        'email' => $email,
        'backfill_window_months' => 3,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    app(OAuthSecretsRepository::class)->saveInboxRefreshToken(
        inboxId: $inboxId,
        provider: $provider,
        email: $email,
        refreshToken: $refreshToken,
        scope: 'https://www.googleapis.com/auth/gmail.readonly',
        expiresAt: null,
    );

    return [$user, $inboxId];
}

it('revokes the Gmail token and removes the inbox on disconnect', function (): void {
    Http::fake(['https://oauth2.googleapis.com/revoke' => Http::response('', 200)]);
    [$user, $inboxId] = disconnectSetupInbox('gmail@example.com', MailProvider::Gmail->value, 'refresh-abc');

    app(DisconnectInbox::class)($inboxId, $user);

    Http::assertSent(fn ($request): bool => $request->url() === 'https://oauth2.googleapis.com/revoke'
        && $request['token'] === 'refresh-abc');

    $db = app(DatabaseManager::class)->connection();
    expect($db->table('inboxes')->where('id', $inboxId)->exists())->toBeFalse()
        ->and(app(OAuthSecretsRepository::class)->loadInbox($inboxId))->toBeNull();
});

it('still removes the inbox when the provider revoke fails', function (): void {
    Http::fake(['https://oauth2.googleapis.com/revoke' => Http::response('nope', 500)]);
    [$user, $inboxId] = disconnectSetupInbox('gmail2@example.com', MailProvider::Gmail->value, 'refresh-xyz');

    app(DisconnectInbox::class)($inboxId, $user);

    $db = app(DatabaseManager::class)->connection();
    expect($db->table('inboxes')->where('id', $inboxId)->exists())->toBeFalse();
});

it('refuses to disconnect an inbox owned by another user', function (): void {
    [, $inboxId] = disconnectSetupInbox('owner@example.com', MailProvider::Gmail->value, 'refresh-1');
    $intruder = User::query()->create(['username' => 'intruder', 'password' => 'x', 'is_developer' => false]);

    expect(fn () => app(DisconnectInbox::class)($inboxId, $intruder))
        ->toThrow(NotFoundHttpException::class);

    expect(app(DatabaseManager::class)->connection()->table('inboxes')->where('id', $inboxId)->exists())->toBeTrue();
});

it('adds a PKCE code_challenge to the Gmail authorization URL', function (): void {
    $pkceUser = User::query()->create(['username' => 'pkce', 'password' => 'x', 'is_developer' => true]);
    test()->actingAs($pkceUser);
    app(OAuthSecretsRepository::class)->saveProviderClient(
        MailProvider::Gmail->value,
        '123.apps.googleusercontent.com',
        'GOCSPX-secret',
        'http://127.0.0.1:8000/oauth/callback/gmail',
    );

    $authorization = app(GoogleOAuthProvider::class)->getAuthorizationUrl('state-1', 'http://127.0.0.1:8000/oauth/callback/gmail');
    parse_str((string) parse_url($authorization->url, PHP_URL_QUERY), $params);

    expect($params)->toHaveKey('code_challenge')
        ->and($params['code_challenge_method'] ?? null)->toBe('S256')
        ->and($authorization->pkceVerifier)->not->toBe('');
});
