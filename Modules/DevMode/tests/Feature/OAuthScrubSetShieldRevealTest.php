<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\DevMode\Internal\Services\OAuthScrubSet;
use Modules\EmailScan\Models\OAuthSecret;

uses(RefreshDatabase::class);

// Without reveal(), the desktop build's scrub set collects safeStorage
// ciphertext while the plaintext — the form that reaches the log — goes
// unscrubbed. The shield below is deliberately non-identity: the default
// PassthroughSecretShield would pass whether or not reveal() is called.
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

    // Shielded on the way in, as the repository writes them on the desktop
    // bundle; the model's `encrypted` cast layers APP_KEY on top of that.
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

    // Bare plaintext, the shape rows written before shielding still have.
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
