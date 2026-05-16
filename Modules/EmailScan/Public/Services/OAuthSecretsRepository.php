<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Services;

use DateTimeImmutable;
use Illuminate\Filesystem\Filesystem;
use InvalidArgumentException;
use JsonException;
use Modules\EmailScan\Public\Dto\InboxCredentials;
use RuntimeException;
use Throwable;

/**
 * The single dependency-injected touchpoint to the chmod-600 JSON
 * file that carries per-provider OAuth client credentials and the
 * per-inbox rotation tokens.
 *
 * The file lives at storage/app/secrets/email-oauth.json. The parent
 * directory is created on first write with mode 0700; the file
 * itself is always chmod 0600 before the atomic rename so callers
 * cannot accidentally publish credentials by inheriting umask.
 *
 * Writes are atomic via the tmp+fsync+rename sequence: open a
 * sibling `.tmp` file, write the encoded payload, fflush, fsync
 * (where available), chmod to 0600, then rename over the canonical
 * path. A POSIX rename is atomic, so a crash mid-write leaves the
 * prior file content intact and never produces a half-written JSON
 * the next reader would choke on. The performRename hook is
 * extracted so tests can simulate a failed rename without touching
 * the filesystem in destructive ways.
 *
 * Failures emit a typed SecretsWriteFailed exception whose message
 * never contains the JSON payload — only the absolute path and a
 * generic description — so any logging surface above this class can
 * never accidentally leak credential material.
 */
class OAuthSecretsRepository
{
    private const PATH_RELATIVE = 'app/secrets/email-oauth.json';

    private const DIR_MODE = 0700;

    private const FILE_MODE = 0600;

    private const ALLOWED_PROVIDERS = ['gmail', 'microsoft'];

    public function __construct(private readonly Filesystem $files) {}

    public function hasProviderClient(string $provider): bool
    {
        $data = $this->readAll();
        if (! isset($data['providers']) || ! is_array($data['providers'])) {
            return false;
        }
        $entry = $data['providers'][$provider] ?? null;
        if (! is_array($entry)) {
            return false;
        }

        return isset($entry['client_id']) && isset($entry['client_secret']);
    }

    public function saveProviderClient(
        string $provider,
        string $clientId,
        string $clientSecret,
        string $redirectUri,
    ): void {
        $this->assertProvider($provider);

        $data = $this->readAll();
        if (! isset($data['providers']) || ! is_array($data['providers'])) {
            $data['providers'] = [];
        }
        $data['providers'][$provider] = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
        ];
        $this->writeAtomic($data);
    }

    /**
     * @return array{client_id: string, client_secret: string, redirect_uri: string}|null
     */
    public function loadProviderClient(string $provider): ?array
    {
        $data = $this->readAll();
        if (! isset($data['providers']) || ! is_array($data['providers'])) {
            return null;
        }
        $entry = $data['providers'][$provider] ?? null;
        if (! is_array($entry)) {
            return null;
        }
        if (! isset($entry['client_id'], $entry['client_secret'], $entry['redirect_uri'])) {
            return null;
        }

        return [
            'client_id' => self::toString($entry['client_id']),
            'client_secret' => self::toString($entry['client_secret']),
            'redirect_uri' => self::toString($entry['redirect_uri']),
        ];
    }

    public function loadInbox(int $inboxId): ?InboxCredentials
    {
        $entry = $this->findInboxEntry($inboxId);
        if ($entry === null) {
            return null;
        }

        return new InboxCredentials(
            inboxId: $inboxId,
            provider: self::toString($entry['provider'] ?? ''),
            refreshToken: self::toString($entry['refresh_token'] ?? ''),
            scope: self::toString($entry['scope'] ?? ''),
            expiresAt: self::toDateTime($entry['expires_at'] ?? null),
            accessToken: isset($entry['access_token']) ? self::toString($entry['access_token']) : null,
        );
    }

    public function saveInboxRefreshToken(
        int $inboxId,
        string $provider,
        string $email,
        string $refreshToken,
        string $scope,
        ?DateTimeImmutable $expiresAt,
    ): void {
        $this->assertProvider($provider);

        $data = $this->readAll();
        $inboxes = self::asInboxList($data['inboxes'] ?? null);

        $newEntry = [
            'id' => $inboxId,
            'provider' => $provider,
            'email' => $email,
            'refresh_token' => $refreshToken,
            'scope' => $scope,
            'expires_at' => $expiresAt?->format(\DateTimeInterface::ATOM),
        ];

        $found = false;
        foreach ($inboxes as $i => $entry) {
            if (self::toInt($entry['id'] ?? 0) === $inboxId) {
                $inboxes[$i] = $newEntry;
                $found = true;
                break;
            }
        }
        if (! $found) {
            $inboxes[] = $newEntry;
        }
        $data['inboxes'] = $inboxes;
        $this->writeAtomic($data);
    }

    public function rotateRefreshToken(
        int $inboxId,
        string $newRefreshToken,
        ?string $newAccessToken,
        ?DateTimeImmutable $expiresAt,
    ): void {
        $data = $this->readAll();
        $inboxes = self::asInboxList($data['inboxes'] ?? null);

        $found = false;
        foreach ($inboxes as $i => $entry) {
            if (self::toInt($entry['id'] ?? 0) === $inboxId) {
                $entry['refresh_token'] = $newRefreshToken;
                if ($newAccessToken !== null) {
                    $entry['access_token'] = $newAccessToken;
                } else {
                    unset($entry['access_token']);
                }
                $entry['expires_at'] = $expiresAt?->format(\DateTimeInterface::ATOM);
                $inboxes[$i] = $entry;
                $found = true;
                break;
            }
        }
        if (! $found) {
            throw new RuntimeException(
                "OAuthSecretsRepository::rotateRefreshToken inbox id {$inboxId} not found."
            );
        }
        $data['inboxes'] = $inboxes;
        $this->writeAtomic($data);
    }

    public function removeInbox(int $inboxId): void
    {
        $data = $this->readAll();
        $inboxes = self::asInboxList($data['inboxes'] ?? null);
        $filtered = array_values(array_filter(
            $inboxes,
            static fn (array $entry): bool => self::toInt($entry['id'] ?? 0) !== $inboxId,
        ));
        $data['inboxes'] = $filtered;
        $this->writeAtomic($data);
    }

    /**
     * Hook extracted so tests can simulate a rename failure without
     * touching the filesystem in destructive ways. Override in a test
     * subclass to return false; the caller treats the failure
     * identically to a native rename() returning false.
     */
    protected function performRename(string $tmp, string $final): bool
    {
        return @rename($tmp, $final);
    }

    /**
     * @return array<string, mixed>
     */
    private function readAll(): array
    {
        $absolute = $this->absolutePath();
        if (! $this->files->exists($absolute)) {
            return ['providers' => [], 'inboxes' => []];
        }
        $raw = $this->files->get($absolute);
        if ($raw === '') {
            return ['providers' => [], 'inboxes' => []];
        }
        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            // Never include $raw in the message — it would leak
            // credential material into any logging surface above.
            throw new RuntimeException(
                "OAuthSecretsRepository: failed to parse {$absolute} ({$e->getMessage()})."
            );
        }
        if (! is_array($decoded)) {
            return ['providers' => [], 'inboxes' => []];
        }
        // Narrow array<mixed, mixed> -> array<string, mixed>: the
        // top-level shape is a JSON object so every key is a string,
        // but PHPStan's strict mode can't infer that from json_decode.
        $out = [];
        foreach ($decoded as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function writeAtomic(array $data): void
    {
        $absolute = $this->absolutePath();
        $dir = dirname($absolute);
        if (! is_dir($dir)) {
            if (! @mkdir($dir, self::DIR_MODE, recursive: true) && ! is_dir($dir)) {
                throw new SecretsWriteFailed(
                    "OAuthSecretsRepository: could not create parent directory {$dir}."
                );
            }
            @chmod($dir, self::DIR_MODE);
        }

        try {
            $bytes = json_encode(
                $data,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
        } catch (JsonException $e) {
            throw new SecretsWriteFailed(
                "OAuthSecretsRepository: failed to encode payload for {$absolute} ({$e->getMessage()})."
            );
        }

        $tmp = $absolute.'.tmp';
        $fp = @fopen($tmp, 'wb');
        if ($fp === false) {
            throw new SecretsWriteFailed(
                "OAuthSecretsRepository: could not open temp file at {$tmp}."
            );
        }

        try {
            @flock($fp, LOCK_EX);
            $written = @fwrite($fp, $bytes);
            if ($written === false || $written !== strlen($bytes)) {
                throw new SecretsWriteFailed(
                    "OAuthSecretsRepository: short write to temp file at {$tmp}."
                );
            }
            @fflush($fp);
            if (function_exists('fsync')) {
                @fsync($fp);
            }
            @flock($fp, LOCK_UN);
            @fclose($fp);
            $fp = null;

            if (! @chmod($tmp, self::FILE_MODE)) {
                throw new SecretsWriteFailed(
                    "OAuthSecretsRepository: failed to chmod temp file at {$tmp}."
                );
            }

            if (! $this->performRename($tmp, $absolute)) {
                throw new SecretsWriteFailed(
                    "OAuthSecretsRepository: atomic rename failed from {$tmp} to {$absolute}."
                );
            }
        } catch (Throwable $e) {
            if (is_resource($fp)) {
                @flock($fp, LOCK_UN);
                @fclose($fp);
            }
            @unlink($tmp);
            if ($e instanceof SecretsWriteFailed) {
                throw $e;
            }
            throw new SecretsWriteFailed(
                "OAuthSecretsRepository: unexpected failure writing {$absolute}."
            );
        }
    }

    private function absolutePath(): string
    {
        return storage_path(self::PATH_RELATIVE);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findInboxEntry(int $inboxId): ?array
    {
        $data = $this->readAll();
        $inboxes = self::asInboxList($data['inboxes'] ?? null);
        foreach ($inboxes as $entry) {
            if (self::toInt($entry['id'] ?? 0) === $inboxId) {
                return $entry;
            }
        }

        return null;
    }

    private function assertProvider(string $provider): void
    {
        if (! in_array($provider, self::ALLOWED_PROVIDERS, strict: true)) {
            throw new InvalidArgumentException(
                "OAuthSecretsRepository: provider must be 'gmail' or 'microsoft', got '{$provider}'."
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function asInboxList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $narrowed = [];
            foreach ($entry as $k => $v) {
                $narrowed[(string) $k] = $v;
            }
            $out[] = $narrowed;
        }

        return $out;
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toString(mixed $value): string
    {
        return is_string($value) ? $value : (string) (is_scalar($value) ? $value : '');
    }

    private static function toDateTime(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value);
        } catch (Throwable) {
            return null;
        }
    }
}
