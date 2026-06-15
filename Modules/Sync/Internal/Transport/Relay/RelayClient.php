<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Relay;

use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

/**
 * HTTP client for the zero-knowledge ciphertext relay (XPORT-03, D-01/D-02).
 *
 * Moves opaque Noise ciphertext between this device and a configured relay endpoint
 * via the relay's HTTP surface:
 *   POST   {endpoint}/relay/deliver         — submit ciphertext blob for a recipient
 *   GET    {endpoint}/relay/drain           — retrieve pending blobs (auth required)
 *   DELETE {endpoint}/relay/drain/{id}      — confirm delivery of a drained blob
 *
 * ZK constraint: this client NEVER has access to plaintext or session keys.
 * It receives already-encrypted Noise ciphertext blobs from the transport layer
 * and forwards them verbatim to the relay. The relay operator sees only routing
 * metadata + opaque ciphertext (T-13-06 / T-13-08).
 *
 * HTTPS enforcement: deliver() / drain() throw RuntimeException when the
 * configured endpoint uses plain HTTP (T-13-08 / Pitfall 6). The relay remains
 * ZK from the operator's perspective regardless of TLS, but network observers
 * can intercept metadata on an HTTP endpoint.
 *
 * Auth: the auth token (from RelayConfig::authToken()) is sent as a Bearer token
 * header. It is never stored in .env — RelayConfig reads it from
 * secretsPath()/sync-relay-token.json (chmod 600).
 *
 * Http\Factory is constructor-injected (never the Http facade) to satisfy the
 * BoundaryArchTest "no Illuminate\Support\Facades inside Modules\" rule — mirrors
 * the EcbRateProvider pattern (Pitfall T-02-04).
 */
final readonly class RelayClient
{
    /** HTTP timeout for relay requests in seconds. */
    private const TIMEOUT_SECONDS = 10;

    public function __construct(
        private HttpFactory $http,
        private RelayConfig $config,
    ) {}

    /**
     * POST an opaque ciphertext blob to the relay for the given recipient device.
     *
     * ZK: $blob is forwarded verbatim — no inspection, no decryption. The blob is
     * base64-encoded inside a JSON envelope so it survives JSON transport; the
     * relay base64-decodes it back to raw bytes for storage and never inspects it.
     *
     * Wire contract (must match RelayServeCommand::handleDeliver):
     *   POST {endpoint}/relay/deliver
     *   JSON body: { "sender_did": string, "recipient_did": string, "blob": base64 }
     *
     * @param  string  $senderDid  Sending device_id (routing metadata only)
     * @param  string  $recipientDid  Recipient device_id (relay routing key)
     * @param  string  $blob  Opaque Noise ciphertext
     *
     * @throws RuntimeException when no endpoint is configured, the endpoint is
     *                          insecure (http://), or the HTTP request fails.
     */
    public function deliver(string $senderDid, string $recipientDid, string $blob): void
    {
        $endpoint = $this->resolvedEndpoint();

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
            throw new RuntimeException(
                "RelayClient::deliver failed: HTTP {$response->status()} from {$endpoint}"
            );
        }
    }

    /**
     * GET all pending blobs from the relay for the given device.
     *
     * Returns an array of relay rows, each containing at minimum:
     *   - id: int  (relay_mailbox row id for confirm())
     *   - blob: string  (opaque Noise ciphertext)
     *   - sender_did: string
     *
     * ZK: blobs are returned opaque — never decoded here.
     *
     * @param  string  $deviceId  Authenticated device draining its mailbox
     * @param  string  $authToken  Per-device bearer token bound to $deviceId
     *                             (RelayConfig::deriveDeviceToken($deviceId)); a
     *                             relay-wide token is rejected by the server (CR-04).
     * @return list<array<string, mixed>>
     *
     * @throws RuntimeException when the request fails.
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
            throw new RuntimeException(
                "RelayClient::drain failed: HTTP {$response->status()} from {$endpoint}"
            );
        }

        // The server wraps the rows in a {"blobs": [...]} envelope
        // (RelayServeCommand::handleDrain). Unwrap it to the documented
        // list<array<string,mixed>> return shape.
        $decoded = $response->json();
        /** @var list<array<string, mixed>> $rows */
        $rows = is_array($decoded) && isset($decoded['blobs']) && is_array($decoded['blobs'])
            ? array_values($decoded['blobs'])
            : [];

        return $rows;
    }

    /**
     * DELETE (confirm delivery of) a drained blob by relay row ID.
     *
     * Called after the receiver has successfully decrypted and replayed the blob.
     * The relay marks the row as delivered (sets delivered_at) and it will be
     * GC'd after the delivered TTL.
     *
     * @param  int  $id  relay_mailbox.id from a drain() response row
     * @param  string  $authToken  Per-device bearer token bound to the recipient
     *                             device that owns the row
     *                             (RelayConfig::deriveDeviceToken($recipientDid)); a
     *                             relay-wide token is rejected by the server (CR-04).
     *
     * @throws RuntimeException when the request fails.
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
            throw new RuntimeException(
                "RelayClient::confirm failed: HTTP {$response->status()} from {$endpoint}"
            );
        }
    }

    /**
     * Resolve and validate the configured endpoint URL.
     *
     * @throws RuntimeException when no endpoint is configured (D-01 default-none
     *                          posture) or the endpoint is insecure (http://).
     */
    private function resolvedEndpoint(): string
    {
        if (! $this->config->isConfigured()) {
            throw new RuntimeException(
                'RelayClient: no relay endpoint configured. '
                .'Set an endpoint URL via RelayConfig::setEndpointUrl() before using the relay.'
            );
        }

        if ($this->config->isInsecure()) {
            // T-13-08 / Pitfall 6: warn on non-HTTPS.
            // Still throw — the relay endpoint MUST be HTTPS to protect routing metadata.
            throw new RuntimeException(
                'RelayClient: relay endpoint must use HTTPS to protect routing metadata. '
                .'The configured endpoint appears to use plain HTTP.'
            );
        }

        $endpoint = $this->config->endpointUrl();

        // isConfigured() above guarantees endpointUrl() is non-null here.
        // PHPStan sees the return type as ?string; we assert non-null via the guard.
        if ($endpoint === null) {
            throw new RuntimeException('RelayClient: relay endpoint unexpectedly null after isConfigured() check.');
        }

        return rtrim($endpoint, '/');
    }

    /**
     * Build the Authorization header from RelayConfig's stored token.
     *
     * Returns an empty array when no token is configured — some relay deployments
     * may be token-optional (e.g. on a private LAN).
     *
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
