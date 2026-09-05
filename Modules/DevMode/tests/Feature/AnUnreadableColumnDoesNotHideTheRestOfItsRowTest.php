<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\Core\Public\Enums\OAuthAlertKind;
use Modules\Core\Public\Services\SystemAlertWriter;
use Modules\DevMode\Internal\Services\OAuthScrubSet;
use Modules\EmailScan\Models\OAuthSecret;

uses(RefreshDatabase::class);

// client_secret and tokens_blob are encrypted independently, so one of them
// being unreadable says nothing about the other. Collecting them under a
// single try meant a stale client_secret kept that account's LIVE
// access_token out of the redaction set, and it was written to the log in
// the clear by the very processor that holds this set.

function unreadableColumnUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);
}

/**
 * A ciphertext no key in this build can open — what a column encrypted under
 * a superseded APP_KEY looks like from here. Written past the model so the
 * `encrypted` cast throws on the way back out, exactly as it does on disk.
 */
function unreadableColumnSecretRow(User $user, ?string $tokensJson, string $unreadable): OAuthSecret
{
    $row = new OAuthSecret;
    $row->user_id = $user->id;
    $row->provider = 'gmail';
    $row->client_id = 'client-id-123';
    $row->client_secret = 'GOCSPX-readable-client-secret';
    $row->redirect_uri = 'http://localhost/callback';
    $row->tokens_blob = $tokensJson;
    $row->save();

    DB::table('oauth_secrets')->where('id', $row->id)->update([
        $unreadable => 'this-payload-no-key-in-this-build-can-open',
    ]);

    return $row;
}

/** @return list<string> */
function unreadableColumnScrubSet(): array
{
    /** @var SecretShield $shield */
    $shield = app(SecretShield::class);

    return (new OAuthScrubSet($shield, app(SystemAlertWriter::class)))->all();
}

it('still collects the live tokens of a row whose client_secret will not decrypt', function (): void {
    $user = unreadableColumnUser('scrubset-keyless-client-secret');
    $this->actingAs($user);

    $tokens = json_encode(['7' => [
        'id' => 7,
        'access_token' => 'AT-live-and-in-use',
        'refresh_token' => 'RT-live-and-in-use',
    ]], JSON_THROW_ON_ERROR);

    unreadableColumnSecretRow($user, $tokens, 'client_secret');

    $set = unreadableColumnScrubSet();

    expect($set)->toContain('AT-live-and-in-use')
        ->and($set)->toContain('RT-live-and-in-use');
});

it('still collects the client_secret of a row whose tokens_blob will not decrypt', function (): void {
    $user = unreadableColumnUser('scrubset-keyless-tokens-blob');
    $this->actingAs($user);

    unreadableColumnSecretRow($user, 'anything-the-cast-will-encrypt', 'tokens_blob');

    expect(unreadableColumnScrubSet())->toContain('GOCSPX-readable-client-secret');
});

it('names the keyless credential once instead of skipping it in silence', function (): void {
    $user = unreadableColumnUser('scrubset-keyless-alert');
    $this->actingAs($user);

    unreadableColumnSecretRow($user, null, 'client_secret');

    /** @var SecretShield $shield */
    $shield = app(SecretShield::class);
    $scrubSet = new OAuthScrubSet($shield, app(SystemAlertWriter::class));

    $scrubSet->all();
    $scrubSet->bust();
    $scrubSet->all();

    // A second instance is the next request: the phone and the desktop both
    // rebuild the container per request, so an in-process flag cannot be what
    // keeps a standing keyless credential from filing an alert every time.
    (new OAuthScrubSet($shield, app(SystemAlertWriter::class)))->all();

    $alerts = DB::table('system_alerts')
        ->where('kind', OAuthAlertKind::ScrubSetFailed->value)
        ->get();

    expect($alerts)->toHaveCount(1);

    $metadata = json_decode((string) $alerts[0]->metadata, true, 512, JSON_THROW_ON_ERROR);

    expect($metadata)->toHaveKey('provider')
        ->and($metadata['provider'])->toBe('gmail');
});

it('leaves no alert behind when every column reads', function (): void {
    $user = unreadableColumnUser('scrubset-all-readable');
    $this->actingAs($user);

    $row = new OAuthSecret;
    $row->user_id = $user->id;
    $row->provider = 'gmail';
    $row->client_id = 'client-id-123';
    $row->client_secret = 'GOCSPX-readable-client-secret';
    $row->redirect_uri = 'http://localhost/callback';
    $row->tokens_blob = json_encode(['9' => ['access_token' => 'AT-ok']], JSON_THROW_ON_ERROR);
    $row->save();

    expect(unreadableColumnScrubSet())->toContain('AT-ok');
    expect(DB::table('system_alerts')->count())->toBe(0);
});
