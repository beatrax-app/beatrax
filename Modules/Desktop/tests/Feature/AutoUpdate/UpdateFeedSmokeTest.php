<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Client\Factory as HttpClient;
use Illuminate\Support\Facades\Http;
use Modules\Core\Internal\AutoUpdate\HttpPublisherManifestFetcher;
use Modules\Core\Models\User;
use Modules\Core\Public\Actions\RecordUpdateAvailableAlert;
use Modules\Core\Public\Services\ElectronUpdateChannel;
use Modules\Core\Public\Services\SystemClock;
use Modules\Core\Public\Services\UpdateChannelPreference;
use Modules\Core\Public\Services\UpdateCheckPreference;
use Modules\Desktop\Internal\Listeners\VerifyAndAnnounceUpdate;
use Modules\Desktop\Internal\Listeners\VerifyAndInstallDownload;
use Native\Desktop\AutoUpdater;
use Native\Desktop\Events\AutoUpdater\UpdateAvailable;
use Native\Desktop\Events\AutoUpdater\UpdateDownloaded;
use Psr\Log\NullLogger;

const SMOKE_FEED_URL = 'https://updates.smoke.test/releases/latest/download';
const SMOKE_RELEASE_DATE = '2026-08-16T00:00:00.000Z';

function smokeManifestName(string $platformFamily): string
{
    return match ($platformFamily) {
        'Darwin' => 'latest-mac.yml',
        'Linux' => 'latest-linux.yml',
        default => 'latest.yml',
    };
}

function smokePublishManifest(
    string $platformFamily,
    string $version,
    string $binaryBytes,
    string $secretKey,
    bool $tamperSignature = false,
    bool $tamperBody = false,
): void {
    $sha512Base64 = base64_encode(hash('sha512', $binaryBytes, true));
    $body = "version: {$version}\nsha512: {$sha512Base64}\nreleaseDate: '".SMOKE_RELEASE_DATE."'\n";

    $signature = sodium_crypto_sign_detached($body, $secretKey);
    if ($tamperSignature) {
        $signature[0] = $signature[0] === "\x00" ? "\x01" : "\x00";
    }
    if ($tamperBody) {
        // Ship a body the signature no longer covers — the manifest was signed,
        // then a later byte was appended in flight.
        $body .= "injected: true\n";
    }

    $name = smokeManifestName($platformFamily);
    Http::fake([
        SMOKE_FEED_URL."/{$name}" => Http::response($body, 200),
        SMOKE_FEED_URL."/{$name}.sig" => Http::response(sodium_bin2hex($signature), 200),
    ]);
}

function smokeFetcher(string $platformFamily): HttpPublisherManifestFetcher
{
    return new HttpPublisherManifestFetcher(
        app(HttpClient::class),
        new Repository(['auto_update' => ['manifest_feed_url' => SMOKE_FEED_URL]]),
        new NullLogger,
        app(UpdateCheckPreference::class),
        $platformFamily,
    );
}

function smokeChannel(string $publicKeyHex): ElectronUpdateChannel
{
    return new ElectronUpdateChannel(
        app(DatabaseManager::class),
        new NullLogger,
        new SystemClock,
        new Repository(['auto_update' => ['publisher_public_key_hex' => $publicKeyHex]]),
        app(UpdateChannelPreference::class),
    );
}

/** @return array{0: string, 1: string} [secretKey, publicKeyHex] */
function smokeKeypair(): array
{
    $keypair = sodium_crypto_sign_keypair();

    return [
        sodium_crypto_sign_secretkey($keypair),
        sodium_bin2hex(sodium_crypto_sign_publickey($keypair)),
    ];
}

function smokeAnnounce(string $platformFamily, string $publicKeyHex, string $offeredVersion): void
{
    (new VerifyAndAnnounceUpdate(
        smokeChannel($publicKeyHex),
        smokeFetcher($platformFamily),
        app(RecordUpdateAvailableAlert::class),
        new NullLogger,
    ))->handle(new UpdateAvailable($offeredVersion, [['url' => 'app.exe', 'sha512' => 'x', 'size' => 1]], SMOKE_RELEASE_DATE));
}

function smokeUpdateAlertCount(): int
{
    return app(DatabaseManager::class)->connection()->table('system_alerts')->where('kind', 'update.available')->count();
}

it('smoke: a validly signed manifest served at the feed reaches the banner', function (): void {
    [$secret, $publicHex] = smokeKeypair();
    smokePublishManifest('Windows', '2.0.0', 'genuine-installer-2.0.0', $secret);

    smokeAnnounce('Windows', $publicHex, '2.0.0');

    $row = app(DatabaseManager::class)->connection()->table('system_alerts')->where('kind', 'update.available')->first();
    expect($row)->not->toBeNull()
        ->and($row->metadata)->toContain('"latestVersion":"2.0.0"');
});

it('smoke: a macOS bundle verifies its own latest-mac.yml end-to-end', function (): void {
    [$secret, $publicHex] = smokeKeypair();
    smokePublishManifest('Darwin', '2.1.0', 'genuine-mac-installer', $secret);

    smokeAnnounce('Darwin', $publicHex, '2.1.0');

    expect(smokeUpdateAlertCount())->toBe(1);
});

it('smoke: a manifest whose signature was tampered never reaches the banner', function (): void {
    [$secret, $publicHex] = smokeKeypair();
    smokePublishManifest('Windows', '2.0.0', 'genuine-installer-2.0.0', $secret, tamperSignature: true);

    smokeAnnounce('Windows', $publicHex, '2.0.0');

    expect(smokeUpdateAlertCount())->toBe(0);
});

it('smoke: a manifest whose body was altered after signing never reaches the banner', function (): void {
    [$secret, $publicHex] = smokeKeypair();
    smokePublishManifest('Windows', '2.0.0', 'genuine-installer-2.0.0', $secret, tamperBody: true);

    smokeAnnounce('Windows', $publicHex, '2.0.0');

    expect(smokeUpdateAlertCount())->toBe(0);
});

it('smoke: a manifest signed by a different key than the pinned one is refused', function (): void {
    [$attackerSecret] = smokeKeypair();
    [, $ourPublicHex] = smokeKeypair();
    // Signed with the attacker's key, verified against ours — the whole point
    // of pinning the publisher key as a literal rather than an env override.
    smokePublishManifest('Windows', '2.0.0', 'genuine-installer-2.0.0', $attackerSecret);

    smokeAnnounce('Windows', $ourPublicHex, '2.0.0');

    expect(smokeUpdateAlertCount())->toBe(0);
});

it('smoke: a downloaded binary matching the signed manifest installs', function (): void {
    [$secret, $publicHex] = smokeKeypair();
    $binaryBytes = 'genuine-installer-2.0.0';
    smokePublishManifest('Windows', '2.0.0', $binaryBytes, $secret);

    $file = (string) tempnam(sys_get_temp_dir(), 'smoke');
    file_put_contents($file, $binaryBytes);

    $updater = Mockery::mock(AutoUpdater::class);
    $updater->shouldReceive('quitAndInstall')->once();

    (new VerifyAndInstallDownload(smokeChannel($publicHex), smokeFetcher('Windows'), $updater, new NullLogger))
        ->handle(new UpdateDownloaded($file, '2.0.0', [], SMOKE_RELEASE_DATE));

    @unlink($file);
});

it('smoke: a downloaded binary whose SHA512 disagrees with the manifest is refused', function (): void {
    [$secret, $publicHex] = smokeKeypair();
    smokePublishManifest('Windows', '2.0.0', 'the-genuine-installer', $secret);

    $file = (string) tempnam(sys_get_temp_dir(), 'smoke');
    file_put_contents($file, 'a-swapped-installer');

    $updater = Mockery::mock(AutoUpdater::class);
    $updater->shouldReceive('quitAndInstall')->never();

    (new VerifyAndInstallDownload(smokeChannel($publicHex), smokeFetcher('Windows'), $updater, new NullLogger))
        ->handle(new UpdateDownloaded($file, '2.0.0', [], SMOKE_RELEASE_DATE));

    @unlink($file);
});

it('smoke: an unreachable feed surfaces no update and installs nothing', function (): void {
    [, $publicHex] = smokeKeypair();
    Http::fake([SMOKE_FEED_URL.'/*' => Http::response('', 404)]);

    smokeAnnounce('Windows', $publicHex, '2.0.0');

    $file = (string) tempnam(sys_get_temp_dir(), 'smoke');
    file_put_contents($file, 'anything');
    $updater = Mockery::mock(AutoUpdater::class);
    $updater->shouldReceive('quitAndInstall')->never();
    (new VerifyAndInstallDownload(smokeChannel($publicHex), smokeFetcher('Windows'), $updater, new NullLogger))
        ->handle(new UpdateDownloaded($file, '2.0.0', [], SMOKE_RELEASE_DATE));

    expect(smokeUpdateAlertCount())->toBe(0);
    @unlink($file);
});

it('smoke: a reader who switched the check off is announced nothing, installs nothing, and sends no request', function (): void {
    // The whole chain, over a feed serving a genuinely signed manifest for a
    // real newer release: with the switch off none of it happens, and the proof
    // is that nothing left the machine to find out.
    [$secret, $publicHex] = smokeKeypair();
    $binaryBytes = 'genuine-installer-2.0.0';
    smokePublishManifest('Windows', '2.0.0', $binaryBytes, $secret);

    User::create([
        'username' => 'smoke-updates-off',
        'password' => 'a-long-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'auto_update_check_enabled' => false,
    ]);

    $file = (string) tempnam(sys_get_temp_dir(), 'smoke');
    file_put_contents($file, $binaryBytes);

    $updater = Mockery::mock(AutoUpdater::class);
    $updater->shouldReceive('quitAndInstall')->never();

    smokeAnnounce('Windows', $publicHex, '2.0.0');
    (new VerifyAndInstallDownload(smokeChannel($publicHex), smokeFetcher('Windows'), $updater, new NullLogger))
        ->handle(new UpdateDownloaded($file, '2.0.0', [], SMOKE_RELEASE_DATE));

    expect(smokeUpdateAlertCount())->toBe(0);
    Http::assertNothingSent();

    @unlink($file);
});
