<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Services;

use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\EmailScan\Models\OAuthSecret;
use Modules\EmailScan\Public\Dto\InboxCredentials;
use RuntimeException;
use Throwable;

/**
 * The single dependency-injected touchpoint to the per-user OAuth
 * credentials store backed by the oauth_secrets SQLite table.
 *
 * Each authenticated user owns at most one row per provider ('gmail'
 * or 'microsoft'). A row carries the provider client credentials
 * (client_id, client_secret, redirect_uri) plus a tokens_blob holding
 * the rotation tokens for every inbox connected through that provider,
 * keyed by inbox id. The client_secret and tokens_blob columns are
 * AES-256-CBC encrypted at rest via the OAuthSecret model's `encrypted`
 * cast keyed on APP_KEY — a raw column read returns ciphertext only.
 *
 * Every read filters by the current user's id and every write stamps
 * it, so two users sharing the SQLite file never see each other's
 * credentials. The current user's id is resolved fresh on every call
 * (never cached) so a guard swap is honoured immediately.
 *
 * Writes go through Eloquent saves, which are transactional and replace
 * the encrypted blob in a single statement.
 */
class OAuthSecretsRepository
{
    private const ALLOWED_PROVIDERS = ['gmail', 'microsoft'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly CurrentUser $currentUser,
        private readonly SecretShield $shield,
    ) {}

    public function hasProviderClient(string $provider): bool
    {
        $row = $this->providerRow($provider);

        return $row !== null
            && $row->client_id !== ''
            && $row->client_secret !== '';
    }

    public function saveProviderClient(
        string $provider,
        string $clientId,
        string $clientSecret,
        string $redirectUri,
    ): void {
        $this->assertProvider($provider);

        $row = $this->providerRow($provider) ?? $this->newProviderRow($provider);
        $row->client_id = $clientId;
        // Keychain-shield under the model's own APP_KEY encryption on the
        // desktop bundle (identity elsewhere); revealed in loadProviderClient.
        $row->client_secret = $this->shield->protect($clientSecret);
        $row->redirect_uri = $redirectUri;
        $this->persist($row);
    }

    /**
     * @return array{client_id: string, client_secret: string, redirect_uri: string}|null
     */
    public function loadProviderClient(string $provider): ?array
    {
        $row = $this->providerRow($provider);
        if ($row === null || $row->client_id === '' || $row->client_secret === '') {
            return null;
        }

        return [
            'client_id' => $row->client_id,
            'client_secret' => $this->shield->reveal($row->client_secret),
            'redirect_uri' => $row->redirect_uri,
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

        // The inbox token list lives in the tokens_blob of its
        // provider's row. Removing any stale copy under a different
        // provider and writing the fresh entry happen inside one
        // transaction so a re-provider'd inbox can never momentarily
        // exist under two providers or vanish entirely.
        $this->db->connection()->transaction(function () use (
            $inboxId,
            $provider,
            $email,
            $refreshToken,
            $scope,
            $expiresAt,
        ): void {
            $this->removeInbox($inboxId);

            $row = $this->providerRow($provider) ?? $this->newProviderRow($provider);
            $inboxes = $this->decodeInboxes($row->tokens_blob);
            $inboxes[(string) $inboxId] = [
                'id' => $inboxId,
                'provider' => $provider,
                'email' => $email,
                'refresh_token' => $refreshToken,
                'scope' => $scope,
                'expires_at' => $expiresAt?->format(\DateTimeInterface::ATOM),
            ];
            $row->tokens_blob = $this->encodeInboxes($inboxes);
            $this->persist($row);
        });
    }

    public function rotateRefreshToken(
        int $inboxId,
        string $newRefreshToken,
        ?string $newAccessToken,
        ?DateTimeImmutable $expiresAt,
    ): void {
        foreach ($this->providerRows() as $row) {
            $inboxes = $this->decodeInboxes($row->tokens_blob);
            $key = (string) $inboxId;
            if (! isset($inboxes[$key])) {
                continue;
            }
            $entry = $inboxes[$key];
            $entry['refresh_token'] = $newRefreshToken;
            if ($newAccessToken !== null) {
                $entry['access_token'] = $newAccessToken;
            } else {
                unset($entry['access_token']);
            }
            $entry['expires_at'] = $expiresAt?->format(\DateTimeInterface::ATOM);
            $inboxes[$key] = $entry;
            $row->tokens_blob = $this->encodeInboxes($inboxes);
            $this->persist($row);

            return;
        }

        throw new RuntimeException(
            "OAuthSecretsRepository::rotateRefreshToken inbox id {$inboxId} not found."
        );
    }

    public function removeInbox(int $inboxId): void
    {
        foreach ($this->providerRows() as $row) {
            $inboxes = $this->decodeInboxes($row->tokens_blob);
            $key = (string) $inboxId;
            if (! isset($inboxes[$key])) {
                continue;
            }
            unset($inboxes[$key]);
            $row->tokens_blob = $inboxes === [] ? null : $this->encodeInboxes($inboxes);
            $this->persist($row);
        }
    }

    /**
     * Persist a row through Eloquent. A DB-layer failure surfaces as a
     * SecretsWriteFailed so callers keep a single typed write-failure
     * contract; the message never carries the credential payload.
     */
    private function persist(OAuthSecret $row): void
    {
        try {
            $row->save();
        } catch (Throwable $e) {
            throw new SecretsWriteFailed(
                "OAuthSecretsRepository: failed to persist the {$row->provider} credential row ({$e->getMessage()})."
            );
        }
    }

    private function providerRow(string $provider): ?OAuthSecret
    {
        return OAuthSecret::query()
            ->where('user_id', $this->currentUser->id())
            ->where('provider', $provider)
            ->first();
    }

    /**
     * @return list<OAuthSecret>
     */
    private function providerRows(): array
    {
        return array_values(
            OAuthSecret::query()
                ->where('user_id', $this->currentUser->id())
                ->get()
                ->all()
        );
    }

    private function newProviderRow(string $provider): OAuthSecret
    {
        $row = new OAuthSecret;
        $row->user_id = $this->currentUser->id();
        $row->provider = $provider;
        $row->client_id = '';
        $row->client_secret = '';
        $row->redirect_uri = '';
        $row->tokens_blob = null;

        return $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findInboxEntry(int $inboxId): ?array
    {
        foreach ($this->providerRows() as $row) {
            $inboxes = $this->decodeInboxes($row->tokens_blob);
            $entry = $inboxes[(string) $inboxId] ?? null;
            if ($entry !== null) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Decode the per-inbox token map from a row's decrypted
     * tokens_blob. The blob is a JSON object keyed by inbox id; PHP
     * coerces those numeric-string keys to int array keys, so the key
     * type is array-key (int|string), matching encodeInboxes().
     *
     * @return array<int|string, array<string, mixed>>
     */
    private function decodeInboxes(?string $blob): array
    {
        if ($blob === null || $blob === '') {
            return [];
        }
        // Reveal the keychain-shielded blob before decoding (identity on
        // web / mobile, and on desktop for legacy unshielded rows —
        // reveal() returns the input unchanged when it is not ciphertext).
        $blob = $this->shield->reveal($blob);
        $decoded = json_decode($blob, true);
        if (! is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $key => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $narrowed = [];
            foreach ($entry as $k => $v) {
                $narrowed[(string) $k] = $v;
            }
            $out[(string) $key] = $narrowed;
        }

        return $out;
    }

    /**
     * @param  array<int|string, array<string, mixed>>  $inboxes
     */
    private function encodeInboxes(array $inboxes): string
    {
        $encoded = json_encode($inboxes, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new SecretsWriteFailed(
                'OAuthSecretsRepository: failed to encode the inbox token map.'
            );
        }

        // Keychain-shield the whole token blob on the desktop bundle (identity
        // elsewhere), layered under the model's APP_KEY column encryption.
        // Applied here so all three write paths (saveInboxRefreshToken,
        // rotateRefreshToken, removeInbox) shield uniformly; decodeInboxes
        // reveals on the way back.
        return $this->shield->protect($encoded);
    }

    private function assertProvider(string $provider): void
    {
        if (! in_array($provider, self::ALLOWED_PROVIDERS, strict: true)) {
            throw new InvalidArgumentException(
                "OAuthSecretsRepository: provider must be 'gmail' or 'microsoft', got '{$provider}'."
            );
        }
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
