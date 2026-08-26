<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Core\Public\Enums\OAuthAlertKind;
use Modules\Core\Public\Enums\SystemAlertSeverity;
use Modules\Core\Public\Http\Livewire\SystemAlertsBanner;

// system_alerts.kind carries no CHECK trigger, so a kind is a private contract
// between one raiser and the surfaces that read the row back. Rows already on
// disk spell these, so the enum is pinned to the literals rather than only to
// itself.
it('spells the three OAuth kinds the way the rows on disk do', function (): void {
    expect(OAuthAlertKind::ReconsentRequired->value)->toBe('oauth_reconsent_required')
        ->and(OAuthAlertKind::ReauthRequired->value)->toBe('oauth.reauth_required')
        ->and(OAuthAlertKind::ScrubSetFailed->value)->toBe('oauth_scrub_set_failed');
});

it('offers re-authorisation only for the kinds a reader can clear that way', function (): void {
    expect(OAuthAlertKind::promptsReauthorisation('oauth_reconsent_required'))->toBeTrue()
        ->and(OAuthAlertKind::promptsReauthorisation('oauth.reauth_required'))->toBeTrue()
        ->and(OAuthAlertKind::promptsReauthorisation('oauth_scrub_set_failed'))->toBeFalse()
        ->and(OAuthAlertKind::promptsReauthorisation('oauth_reauth_required'))->toBeFalse()
        ->and(OAuthAlertKind::promptsReauthorisation('backup_overdue'))->toBeFalse();
});

// An unknown kind falls through to the row's own `message` column, so a
// drifted spelling shows the raw text and no Reconnect link, and nothing
// errors to say so.
it('renders the re-consent template rather than falling through to the raw message', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $user = User::query()->create([
        'username' => 'oauth-kind-vocabulary',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    $db->connection()->table('system_alerts')->insert([
        'user_id' => $user->id,
        'kind' => OAuthAlertKind::ReconsentRequired->value,
        'severity' => SystemAlertSeverity::Warning->value,
        'message' => 'Reconnect your Gmail',
        'metadata' => json_encode(['inbox_id' => 12, 'provider' => 'gmail']),
        'created_at' => '2026-05-20 01:00:00',
        'acknowledged_at' => null,
    ]);

    Livewire::actingAs($user)->test(SystemAlertsBanner::class)
        ->assertSee('Reconnect your Gmail')
        ->assertSeeHtml('/inboxes?reconnect=12');
});
