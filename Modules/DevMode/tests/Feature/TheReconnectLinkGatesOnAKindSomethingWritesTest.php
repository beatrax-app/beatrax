<?php

declare(strict_types=1);

use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;
use Modules\Core\Public\Navigation\Destination;
use Modules\Core\Public\Support\Lang;

// The dev console's Open alerts card gates its re-auth link on a
// system_alerts.kind. No writer in the tree spells `oauth_reauth_required`,
// so the gate it used to carry could not fire for any row the app produces.
function devReconnectDeveloper(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);
}

function devReconnectHtml(User $user, string $kind, string $message): string
{
    SystemAlert::query()->create([
        'user_id' => $user->id,
        'kind' => $kind,
        'severity' => 'warning',
        'message' => $message,
        'metadata' => ['inbox_id' => 4, 'provider' => 'gmail'],
    ]);

    $response = test()->actingAs($user)->get('/dev');
    $response->assertOk();

    return (string) $response->getContent();
}

it('offers the re-auth link on the kind the inbox scanner writes when a token lapses', function (): void {
    $html = devReconnectHtml(
        devReconnectDeveloper('devov-reconsent'),
        'oauth_reconsent_required',
        'Reconnect your Gmail',
    );

    expect($html)->toContain('Reconnect your Gmail')
        ->and($html)->toContain(Lang::get('dev::overview.reauth'))
        ->and($html)->toContain(Destination::Email->url());
});

it('offers the re-auth link on the kind the per-user secrets move writes', function (): void {
    $html = devReconnectHtml(
        devReconnectDeveloper('devov-reauth'),
        'oauth.reauth_required',
        'Re-authorize Gmail and Microsoft',
    );

    expect($html)->toContain('Re-authorize Gmail and Microsoft')
        ->and($html)->toContain(Lang::get('dev::overview.reauth'))
        ->and($html)->toContain(Destination::Email->url());
});

it('leaves an alert nobody can act on by re-authorising without the link', function (): void {
    $html = devReconnectHtml(
        devReconnectDeveloper('devov-unrelated'),
        'backup_overdue',
        'Backup is overdue by 50h',
    );

    expect($html)->toContain('Backup is overdue by 50h')
        ->and($html)->not->toContain(Lang::get('dev::overview.reauth'))
        ->and($html)->not->toContain(Destination::Email->url());
});
