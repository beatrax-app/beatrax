<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Services;

use DateTimeImmutable;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Contracts\SecretShield;
use Modules\EmailScan\Internal\Exceptions\ScanStateNotFoundException;
use Modules\EmailScan\Models\OAuthSecret;
use Modules\EmailScan\Public\Dto\InboxCredentials;
use Modules\EmailScan\Public\Enums\MailProvider;
use Throwable;

class OAuthSecretsRepository
{
    use CoercesScalars;

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
        // Keychain-shielded under the model's own APP_KEY encryption
        // on desktop (identity elsewhere); revealed in loadProviderClient.
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

        // One transaction, so a re-provider'd inbox can never momentarily
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

        throw new ScanStateNotFoundException(
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

    // A DB failure surfaces as SecretsWriteFailed, whose message never
    // carries the credential payload.
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
     * @return array<int|string, array<string, mixed>>
     */
    private function decodeInboxes(?string $blob): array
    {
        if ($blob === null || $blob === '') {
            return [];
        }
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

        // Shielded uniformly on all three write paths; decodeInboxes
        // reveals on the way back.
        return $this->shield->protect($encoded);
    }

    private function assertProvider(string $provider): void
    {
        if (MailProvider::tryFrom($provider) === null) {
            throw new InvalidArgumentException(
                "OAuthSecretsRepository: provider must be 'gmail' or 'microsoft', got '{$provider}'."
            );
        }
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
