<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Actions\RecordUpdateAvailableAlert;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\PublisherManifestFetcher;
use Modules\Core\Public\Enums\SystemAlertSeverity;
use Modules\Core\Public\Enums\UpdateAlertKind;
use Modules\Core\Public\Services\ElectronUpdateChannel;
use Modules\Desktop\Internal\Listeners\VerifyAndAnnounceUpdate;
use Native\Desktop\Events\AutoUpdater\UpdateAvailable;
use Psr\Log\NullLogger;

/**
 * @return array{body: string, signature: string, latest_version: string, sha512_hex: string, published_at: CarbonImmutable}
 */
function staleAgeSignedManifest(string $version, CarbonImmutable $publishedAt, string $secretKey): array
{
    $body = "version: {$version}\nsha512: normalised-elsewhere\nreleaseDate: '".$publishedAt->toIso8601String()."'\n";

    return [
        'body' => $body,
        'signature' => sodium_crypto_sign_detached($body, $secretKey),
        'latest_version' => $version,
        'sha512_hex' => str_repeat('a', 128),
        'published_at' => $publishedAt,
    ];
}

/**
 * @param  array<string, mixed>  $manifest
 */
function staleAgeFetcher(array $manifest): PublisherManifestFetcher
{
    return new class($manifest) implements PublisherManifestFetcher
    {
        /** @param array<string, mixed> $manifest */
        public function __construct(private array $manifest) {}

        public function fetch(string $channel): ?array
        {
            return $this->manifest;
        }
    };
}

function staleAgeChannel(string $publicKeyHex, CarbonImmutable $now, string $installedVersion): ElectronUpdateChannel
{
    $clock = new class($now) implements Clock
    {
        public function __construct(private readonly CarbonImmutable $now) {}

        public function now(): CarbonImmutable
        {
            return $this->now;
        }
    };

    return new ElectronUpdateChannel(
        app(DatabaseManager::class),
        new NullLogger,
        $clock,
        new Repository([
            'auto_update' => ['publisher_public_key_hex' => $publicKeyHex, 'update_channel' => 'stable'],
            'nativephp' => ['version' => $installedVersion],
        ]),
    );
}

function staleAgeAnnounce(CarbonImmutable $publishedAt, string $installedVersion): void
{
    $keypair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($keypair);
    $publicHex = sodium_bin2hex(sodium_crypto_sign_publickey($keypair));
    $now = CarbonImmutable::parse('2026-08-23T00:00:00+00:00');

    (new VerifyAndAnnounceUpdate(
        staleAgeChannel($publicHex, $now, $installedVersion),
        staleAgeFetcher(staleAgeSignedManifest('1.2.3', $publishedAt, $secret)),
        app(RecordUpdateAvailableAlert::class),
        new NullLogger,
    ))->handle(new UpdateAvailable('1.2.3', [['url' => 'app.exe', 'sha512' => 'x', 'size' => 1]], $publishedAt->toIso8601String()));
}

function staleAgeRow(): ?object
{
    return app(DatabaseManager::class)->connection()->table('system_alerts')->first();
}

it('announces a release published beyond the stale threshold as update.stale', function (): void {
    staleAgeAnnounce(CarbonImmutable::parse('2026-06-01T00:00:00+00:00'), '0.1.0');

    $row = staleAgeRow();

    expect($row)->not->toBeNull()
        ->and($row->kind)->toBe(UpdateAlertKind::Stale->value)
        ->and($row->severity)->toBe(SystemAlertSeverity::Warning->value);
});

it('carries the installed version in metadata so the stale banner can name both versions', function (): void {
    staleAgeAnnounce(CarbonImmutable::parse('2026-06-01T00:00:00+00:00'), '0.1.0');

    $row = staleAgeRow();

    expect($row)->not->toBeNull()
        ->and($row->metadata)->toContain('"currentVersion":"0.1.0"')
        ->and($row->metadata)->toContain('"latestVersion":"1.2.3"');
});

it('still announces a freshly published release as update.available', function (): void {
    staleAgeAnnounce(CarbonImmutable::parse('2026-08-20T00:00:00+00:00'), '0.1.0');

    $row = staleAgeRow();

    expect($row)->not->toBeNull()
        ->and($row->kind)->toBe(UpdateAlertKind::Available->value)
        ->and($row->severity)->toBe(SystemAlertSeverity::Info->value);
});

it('does not stack a second row when a stale row for the same version is unacknowledged', function (): void {
    staleAgeAnnounce(CarbonImmutable::parse('2026-06-01T00:00:00+00:00'), '0.1.0');
    staleAgeAnnounce(CarbonImmutable::parse('2026-06-01T00:00:00+00:00'), '0.1.0');

    expect(app(DatabaseManager::class)->connection()->table('system_alerts')->count())->toBe(1);
});
