<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\DevMode\Internal\Services\OAuthScrubSet;
use Modules\EmailScan\Models\OAuthSecret;

uses(RefreshDatabase::class);

/*
 * Proves OAuthScrubSet::load() reveals keychain-SHIELDED secrets before
 * building the redaction set — the guard against the desktop-only regression
 * where the set would otherwise collect safeStorage ciphertext and the REAL
 * plaintext secret (the value that leaks into logs) would never be scrubbed.
 *
 * Uses a marker shield (non-identity) so that deleting the reveal() calls in
 * load() would make this test fail — which the default PassthroughSecretShield
 * cannot detect (identity hides the wiring).
 */

/** A non-identity SecretShield: protect() marks + base64s, reveal() reverses. */
function markerShield(): SecretShield
{
    return new class implements SecretShield
    {
        public function protect(string $plaintext): string
        {
            return 'SHIELDED:'.base64_encode($plaintext);
        }

        public function reveal(string $shielded): string
        {
            if (! str_starts_with($shielded, 'SHIELDED:')) {
                return $shielded; // legacy / unshielded → identity
            }
            $decoded = base64_decode(substr($shielded, 9), strict: true);

            return $decoded === false ? $shielded : $decoded;
        }
    };
}

it('reveals shielded client_secret and tokens_blob into the scrub set as plaintext', function (): void {
    $shield = markerShield();

    $user = User::query()->create([
        'username' => 'scrub-shield',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);

    // Persist SHIELDED values (as the repository would on the desktop bundle).
    // The model's `encrypted` cast additionally APP_KEY-encrypts them at rest.
    $tokensJson = json_encode(['42' => ['id' => 42, 'refresh_token' => 'RT-topsecret']]);
    $row = new OAuthSecret;
    $row->user_id = $user->id;
    $row->provider = 'gmail';
    $row->client_id = 'client-id-123';
    $row->client_secret = $shield->protect('GOCSPX-realsecret');
    $row->redirect_uri = 'http://localhost/callback';
    $row->tokens_blob = $shield->protect($tokensJson);
    $row->save();

    $set = (new OAuthScrubSet($shield))->all();

    // The set holds the revealed PLAINTEXT, not the shielded/ciphertext form.
    expect($set)->toContain('GOCSPX-realsecret')
        ->and($set)->toContain('RT-topsecret')
        ->and($set)->not->toContain($shield->protect('GOCSPX-realsecret'));
});

it('treats an unshielded (legacy) row as plaintext via reveal-is-identity', function (): void {
    $shield = markerShield();

    $user = User::query()->create([
        'username' => 'scrub-legacy',
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);

    // A legacy row written before shielding: the stored value is bare plaintext.
    $row = new OAuthSecret;
    $row->user_id = $user->id;
    $row->provider = 'gmail';
    $row->client_id = 'client-id-123';
    $row->client_secret = 'GOCSPX-legacy-plain';
    $row->redirect_uri = 'http://localhost/callback';
    $row->tokens_blob = null;
    $row->save();

    $set = (new OAuthScrubSet($shield))->all();

    expect($set)->toContain('GOCSPX-legacy-plain');
});
