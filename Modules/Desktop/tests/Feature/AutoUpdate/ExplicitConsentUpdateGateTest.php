<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Actions\RecordUpdateAvailableAlert;
use Modules\Core\Public\Contracts\PublisherManifestFetcher;
use Modules\Core\Public\Enums\UpdateChannel;
use Modules\Core\Public\Services\ElectronUpdateChannel;
use Modules\Core\Public\Services\SystemAlertWriter;
use Modules\Core\Public\Services\SystemClock;
use Modules\Core\Public\Services\UpdateChannelPreference;
use Modules\Desktop\Internal\Listeners\VerifyAndAnnounceUpdate;
use Modules\Desktop\Internal\Listeners\VerifyAndInstallDownload;
use Native\Desktop\AutoUpdater;
use Native\Desktop\Events\AutoUpdater\UpdateAvailable;
use Native\Desktop\Events\AutoUpdater\UpdateDownloaded;
use Psr\Log\NullLogger;

/**
 * @return array{body: string, signature: string, latest_version: string, sha512_hex: string, published_at: CarbonImmutable}
 */
function consentGateSignedManifest(string $version, string $sha512Hex, string $secretKey): array
{
    $body = "version: {$version}\nsha512: normalised-elsewhere\nreleaseDate: '2026-08-16T00:00:00.000Z'\n";

    return [
        'body' => $body,
        'signature' => sodium_crypto_sign_detached($body, $secretKey),
        'latest_version' => $version,
        'sha512_hex' => $sha512Hex,
        'published_at' => CarbonImmutable::parse('2026-08-16T00:00:00.000Z'),
    ];
}

/**
 * @param  array<string, mixed>|null  $manifest
 */
function consentGateFetcher(?array $manifest): PublisherManifestFetcher
{
    return new class($manifest) implements PublisherManifestFetcher
    {
        /** @param array<string, mixed>|null $manifest */
        public function __construct(private ?array $manifest) {}

        public function fetch(UpdateChannel $channel): ?array
        {
            return $this->manifest;
        }
    };
}

function consentGateChannel(string $publicKeyHex): ElectronUpdateChannel
{
    return new ElectronUpdateChannel(
        app(DatabaseManager::class),
        new NullLogger,
        new SystemClock,
        new Repository(['auto_update' => ['publisher_public_key_hex' => $publicKeyHex]]),
        app(UpdateChannelPreference::class),
    );
}

function consentGateAvailableEvent(string $version): UpdateAvailable
{
    return new UpdateAvailable($version, [['url' => 'app.exe', 'sha512' => 'x', 'size' => 1]], '2026-08-16T00:00:00.000Z');
}

function consentGateUpdateAlerts(): int
{
    return app(DatabaseManager::class)->connection()->table('system_alerts')->where('kind', 'update.available')->count();
}

it('announces an update only after the signed manifest verifies', function (): void {
    $keypair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($keypair);
    $publicHex = sodium_bin2hex(sodium_crypto_sign_publickey($keypair));

    (new VerifyAndAnnounceUpdate(
        consentGateChannel($publicHex),
        consentGateFetcher(consentGateSignedManifest('1.2.3', str_repeat('a', 128), $secret)),
        app(RecordUpdateAvailableAlert::class),
        new NullLogger,
    ))->handle(consentGateAvailableEvent('1.2.3'));

    $row = app(DatabaseManager::class)->connection()->table('system_alerts')->where('kind', 'update.available')->first();
    expect($row)->not->toBeNull()
        ->and($row->metadata)->toContain('"latestVersion":"1.2.3"');
});

it('suppresses the banner when the manifest signature is tampered', function (): void {
    $keypair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($keypair);
    $publicHex = sodium_bin2hex(sodium_crypto_sign_publickey($keypair));

    $manifest = consentGateSignedManifest('1.2.3', str_repeat('a', 128), $secret);
    $manifest['signature'][0] = $manifest['signature'][0] === "\x00" ? "\x01" : "\x00";

    (new VerifyAndAnnounceUpdate(
        consentGateChannel($publicHex),
        consentGateFetcher($manifest),
        app(RecordUpdateAvailableAlert::class),
        new NullLogger,
    ))->handle(consentGateAvailableEvent('1.2.3'));

    expect(consentGateUpdateAlerts())->toBe(0);
});

it('suppresses the banner when the offered version disagrees with the signed manifest', function (): void {
    $keypair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($keypair);
    $publicHex = sodium_bin2hex(sodium_crypto_sign_publickey($keypair));

    (new VerifyAndAnnounceUpdate(
        consentGateChannel($publicHex),
        consentGateFetcher(consentGateSignedManifest('1.2.3', str_repeat('a', 128), $secret)),
        app(RecordUpdateAvailableAlert::class),
        new NullLogger,
    ))->handle(consentGateAvailableEvent('9.9.9'));

    expect(consentGateUpdateAlerts())->toBe(0);
});

it('installs a downloaded binary only after signature and SHA512 both verify', function (): void {
    $keypair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($keypair);
    $publicHex = sodium_bin2hex(sodium_crypto_sign_publickey($keypair));

    $file = (string) tempnam(sys_get_temp_dir(), 'upd');
    file_put_contents($file, 'the-real-installer-bytes');

    $updater = Mockery::mock(AutoUpdater::class);
    $updater->shouldReceive('quitAndInstall')->once();

    (new VerifyAndInstallDownload(
        consentGateChannel($publicHex),
        consentGateFetcher(consentGateSignedManifest('1.2.3', hash('sha512', 'the-real-installer-bytes'), $secret)),
        $updater,
        new NullLogger,
        app(SystemAlertWriter::class),
    ))->handle(new UpdateDownloaded($file, '1.2.3', [], '2026-08-16T00:00:00.000Z'));

    @unlink($file);
});

it('refuses to install a binary whose hash does not match the signed manifest', function (): void {
    $keypair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($keypair);
    $publicHex = sodium_bin2hex(sodium_crypto_sign_publickey($keypair));

    $file = (string) tempnam(sys_get_temp_dir(), 'upd');
    file_put_contents($file, 'a-tampered-installer');

    $updater = Mockery::mock(AutoUpdater::class);
    $updater->shouldReceive('quitAndInstall')->never();

    (new VerifyAndInstallDownload(
        consentGateChannel($publicHex),
        consentGateFetcher(consentGateSignedManifest('1.2.3', hash('sha512', 'the-original-installer'), $secret)),
        $updater,
        new NullLogger,
        app(SystemAlertWriter::class),
    ))->handle(new UpdateDownloaded($file, '1.2.3', [], '2026-08-16T00:00:00.000Z'));

    @unlink($file);
});
