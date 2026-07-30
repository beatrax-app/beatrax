<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Relay;

use Illuminate\Http\Client\Factory as HttpFactory;
use Modules\Sync\Internal\Exceptions\RelayRefusedException;
use Modules\Sync\Internal\Exceptions\RelayUnavailableException;

/**
 * @link ../../../../../.docs/features/sync/architecture.md
 */
final readonly class RelayClient
{
    private const TIMEOUT_SECONDS = 10;

    // 64 KB TransportFramer ceiling + 1 KB headroom for AEAD/Noise overhead.
    // RelayServeCommand mirrors this exact constant server-side so a caller
    // bypassing this client can't smuggle an oversized blob past it.
    public const MAX_BLOB_BYTES = 65536 + 1024;

    public function __construct(
        private HttpFactory $http,
        private RelayConfig $config,
    ) {}

    // ZK: $blob is forwarded verbatim — no inspection, no decryption. It is
    // base64-encoded inside a JSON envelope so it survives JSON transport;
    // the relay base64-decodes it back to raw bytes and never inspects it.
    /**
     * @param  string  $senderDid  Sending device_id (routing metadata only)
     * @param  string  $recipientDid  Recipient device_id (relay routing key)
     * @param  string  $blob  Opaque Noise ciphertext
     *
     * @throws RelayRefusedException when the blob exceeds the relay's limit, no
     *                               endpoint is configured, or it is not HTTPS
     * @throws RelayUnavailableException when the relay answers with a non-2xx
     */
    public function deliver(string $senderDid, string $recipientDid, string $blob): void
    {
        $endpoint = $this->resolvedEndpoint();

        if (strlen($blob) > self::MAX_BLOB_BYTES) {
            throw RelayRefusedException::blobTooLarge(strlen($blob), self::MAX_BLOB_BYTES);
        }

        $response = $this->http
            ->createPendingRequest()
            ->withHeaders($this->authHeaders())
            ->timeout(self::TIMEOUT_SECONDS)
            ->post("{$endpoint}/relay/deliver", [
                'sender_did' => $senderDid,
                'recipient_did' => $recipientDid,
                'blob' => base64_encode($blob),
            ]);

        if (! $response->successful()) {
            throw RelayUnavailableException::requestFailed('deliver', $response->status(), $endpoint);
        }
    }

    /**
     * @param  string  $deviceId  Authenticated device draining its mailbox
     * @param  string  $authToken  Per-device bearer token bound to $deviceId
     *                             (RelayConfig::deriveDeviceToken($deviceId)); a
     *                             relay-wide token is rejected by the server.
     * @return list<array<string, mixed>>
     *
     * @throws RelayRefusedException when no endpoint is configured or it is not HTTPS
     * @throws RelayUnavailableException when the relay answers with a non-2xx
     */
    public function drain(string $deviceId, string $authToken): array
    {
        $endpoint = $this->resolvedEndpoint();

        $response = $this->http
            ->createPendingRequest()
            ->withToken($authToken)
            ->timeout(self::TIMEOUT_SECONDS)
            ->get("{$endpoint}/relay/drain", ['did' => $deviceId]);

        if (! $response->successful()) {
            throw RelayUnavailableException::requestFailed('drain', $response->status(), $endpoint);
        }

        // The server wraps the rows in a {"blobs": [...]} envelope; unwrap
        // to the documented list<array<string,mixed>> return shape.
        $decoded = $response->json();
        /** @var list<array<string, mixed>> $rows */
        $rows = is_array($decoded) && isset($decoded['blobs']) && is_array($decoded['blobs'])
            ? array_values($decoded['blobs'])
            : [];

        return $rows;
    }

    /**
     * @param  int  $id  relay_mailbox.id from a drain() response row
     * @param  string  $authToken  Per-device bearer token bound to the recipient
     *                             device that owns the row
     *                             (RelayConfig::deriveDeviceToken($recipientDid)); a
     *                             relay-wide token is rejected by the server.
     *
     * @throws RelayRefusedException when no endpoint is configured or it is not HTTPS
     * @throws RelayUnavailableException when the relay answers with a non-2xx
     */
    public function confirm(int $id, string $authToken): void
    {
        $endpoint = $this->resolvedEndpoint();

        $response = $this->http
            ->createPendingRequest()
            ->withToken($authToken)
            ->timeout(self::TIMEOUT_SECONDS)
            ->delete("{$endpoint}/relay/drain/{$id}");

        if (! $response->successful()) {
            throw RelayUnavailableException::requestFailed('confirm', $response->status(), $endpoint);
        }
    }

    /**
     * @throws RelayRefusedException when no endpoint is configured or the
     *                               endpoint is insecure (http://)
     */
    private function resolvedEndpoint(): string
    {
        if (! $this->config->isConfigured()) {
            throw RelayRefusedException::notConfigured();
        }

        if ($this->config->isInsecure()) {
            throw RelayRefusedException::endpointNotHttps();
        }

        $endpoint = $this->config->endpointUrl();

        // isConfigured() above guarantees this is non-null; PHPStan sees
        // ?string, so assert it explicitly.
        if ($endpoint === null) {
            throw RelayRefusedException::endpointVanished();
        }

        return rtrim($endpoint, '/');
    }

    // Empty array when no token is configured — some relay deployments may
    // be token-optional (e.g. on a private LAN).
    /**
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        $token = $this->config->authToken();
        if ($token === null) {
            return [];
        }

        return ['Authorization' => "Bearer {$token}"];
    }
}
