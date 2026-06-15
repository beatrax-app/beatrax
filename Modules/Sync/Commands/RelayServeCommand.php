<?php

declare(strict_types=1);

namespace Modules\Sync\Commands;

use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Illuminate\Console\Command;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Internal\Transport\Relay\RelayMailbox;
use Psr\Log\LoggerInterface;

/**
 * Zero-knowledge relay HTTP endpoint daemon (XPORT-03, D-01/D-02/D-03/D-04).
 *
 * Exposes the ZK relay mailbox over three HTTP routes:
 *
 *   POST   /relay/deliver                → Accept a Noise ciphertext blob (open submission)
 *   GET    /relay/drain                  → Return pending blobs for authenticated device
 *   DELETE /relay/drain/{id}             → Mark a blob as delivered (confirmed drain)
 *
 * ## Zero-knowledge invariant (D-02 / T-13-06 / T-13-SC2)
 *
 *   This command contains ZERO sodium_* calls. Blobs are stored and forwarded
 *   verbatim — the relay never decrypts, inspects, or JSON-decodes blob content.
 *   All cryptographic operations live end-to-end between devices (inside the Noise
 *   session); the relay operator learns only: sender_did, recipient_did, blob size,
 *   and delivery timestamp.
 *
 * ## Drain authorization (T-13-13 / CR-04)
 *
 *   GET /relay/drain and DELETE /relay/drain/{id} require the caller to present
 *   a bearer token in the Authorization header that matches the PER-DEVICE token
 *   derived for the mailbox being accessed:
 *       expected = HMAC-SHA256(RelayConfig::authToken(), recipient_did)
 *   (RelayConfig::deriveDeviceToken()). A single relay-wide token is NOT
 *   accepted — a token scoped to device A cannot drain or confirm-delete device
 *   B's mailbox. This closes the metadata-isolation hole where any holder of the
 *   shared token could pull or delete an arbitrary recipient's blobs.
 *
 *   ZK is preserved: the relay only HMACs the device_id (routing metadata it
 *   already sees) with the secret it already holds — it never reads blobs, never
 *   learns a user_id, and never has device key material.
 *
 *   Authorization is enforced HERE (at the endpoint), NOT inside RelayMailbox
 *   (which is a pure ZK store). This separation keeps RelayMailbox testable
 *   without auth scaffolding and makes the authorization policy explicit and
 *   auditable in this one file.
 *
 *   Deliver (POST /relay/deliver) is open — anyone can drop a ciphertext blob
 *   into any mailbox. The recipient's per-device drain token is the only thing
 *   that gates retrieval.
 *
 * ## Opt-in deployment (D-01)
 *
 *   The relay is NOT started automatically by the application. Self-hosters run
 *   `php artisan relay:serve` manually or via their own supervised process.
 *   The default out-of-box path is LAN-direct (sync:serve); the relay is the
 *   offline-buffering fallback when the user explicitly opts in by configuring a
 *   relay endpoint URL in RelayConfig.
 *
 * ## Event-loop lifecycle (D-04)
 *
 *   handle() calls \Amp\trapSignal([SIGTERM, SIGINT]) which suspends the current
 *   fiber until one of those signals arrives. On shutdown: stop the HTTP server,
 *   return self::SUCCESS.
 *
 * @internal Plan 05 — registered via SyncServiceProvider.
 */
final class RelayServeCommand extends Command
{
    /** @var string */
    protected $signature = 'relay:serve {--port=51338 : Relay HTTP listen port}';

    /** @var string */
    protected $description = 'Start the ZK relay HTTP endpoint daemon (POST /relay/deliver, GET /relay/drain, DELETE /relay/drain/{id}).';

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly RelayMailbox $mailbox,
        private readonly RelayConfig $config,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $port = (int) $this->option('port');
        if ($port <= 0 || $port > 65535) {
            $this->error("relay:serve: invalid port {$port}.");

            return self::FAILURE;
        }

        $this->logger->info('relay:serve: starting ZK relay HTTP endpoint.', ['port' => $port]);

        try {
            $httpServer = SocketHttpServer::createForDirectAccess($this->logger);
            $httpServer->expose("0.0.0.0:{$port}");

            $requestHandler = new ClosureRequestHandler(
                fn (Request $request): Response => $this->route($request),
            );
            $errorHandler = new DefaultErrorHandler;
            $httpServer->start($requestHandler, $errorHandler);

            $this->logger->info('relay:serve: endpoint started.', ['port' => $port]);
            $this->info("relay:serve: ZK relay listening on 0.0.0.0:{$port} (SIGTERM/SIGINT to stop).");

            // Suspend this fiber until SIGTERM or SIGINT arrives (D-04).
            \Amp\trapSignal([\SIGTERM, \SIGINT]);

            $this->logger->info('relay:serve: shutdown signal received — stopping relay.');
            $httpServer->stop();
        } catch (\Throwable $e) {
            $this->logger->error('relay:serve: fatal error.', ['error' => $e->getMessage()]);
            $this->error("relay:serve: fatal — {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info('relay:serve: stopped cleanly.');
        $this->logger->info('relay:serve: stopped cleanly.');

        return self::SUCCESS;
    }

    /**
     * Route incoming HTTP requests to the appropriate relay handler.
     *
     * Routes:
     *   POST   /relay/deliver         → deliverHandler
     *   GET    /relay/drain           → drainHandler (auth required)
     *   DELETE /relay/drain/{id}      → confirmHandler (auth required)
     *   *      (no match)             → 404 Not Found
     */
    private function route(Request $request): Response
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        if ($method === 'POST' && $path === '/relay/deliver') {
            return $this->handleDeliver($request);
        }

        if ($method === 'GET' && $path === '/relay/drain') {
            return $this->handleDrain($request);
        }

        // DELETE /relay/drain/{id} — matches DELETE method + /relay/drain/ prefix with a non-empty id segment.
        if ($method === 'DELETE' && str_starts_with($path, '/relay/drain/')) {
            $idStr = ltrim(substr($path, strlen('/relay/drain/')), '/');
            if ($idStr !== '' && ctype_digit($idStr)) {
                return $this->handleConfirm($request, (int) $idStr);
            }
        }

        return new Response(HttpStatus::NOT_FOUND, ['content-type' => 'application/json'], '{"error":"not_found"}');
    }

    /**
     * POST /relay/deliver — accept a Noise ciphertext blob into a recipient's mailbox.
     *
     * Required JSON body fields:
     *   - sender_did    string: sending device_id (routing metadata only)
     *   - recipient_did string: receiving device_id (mailbox address)
     *   - blob          string: base64-encoded Noise ciphertext blob
     *
     * Authorization: OPEN — any caller may POST a blob into any mailbox. The
     * recipient's drain token gates retrieval; delivery is always accepted (like
     * an email relay accepting inbound SMTP from anyone).
     *
     * ZK: the blob is stored verbatim — no decryption, no sodium calls, no
     * json_decode of the blob content itself.
     */
    private function handleDeliver(Request $request): Response
    {
        $rawBody = $request->getBody()->buffer();
        $body = json_decode($rawBody, true, 512, 0);

        if (! is_array($body)) {
            return $this->jsonError(HttpStatus::BAD_REQUEST, 'invalid_json');
        }

        $senderDid = isset($body['sender_did']) && is_string($body['sender_did']) ? $body['sender_did'] : '';
        $recipientDid = isset($body['recipient_did']) && is_string($body['recipient_did']) ? $body['recipient_did'] : '';
        $blobB64 = isset($body['blob']) && is_string($body['blob']) ? $body['blob'] : '';

        if ($senderDid === '' || $recipientDid === '' || $blobB64 === '') {
            return $this->jsonError(HttpStatus::BAD_REQUEST, 'missing_fields');
        }

        // Decode base64 blob to raw binary for ZK storage.
        $blob = base64_decode($blobB64, strict: true);
        if ($blob === false || $blob === '') {
            return $this->jsonError(HttpStatus::BAD_REQUEST, 'invalid_blob_encoding');
        }

        try {
            $this->mailbox->deliver($senderDid, $recipientDid, $blob);
        } catch (\Throwable $e) {
            $this->logger->error('relay:serve: deliver failed.', ['error' => $e->getMessage()]);

            return $this->jsonError(HttpStatus::INTERNAL_SERVER_ERROR, 'deliver_failed');
        }

        return new Response(HttpStatus::ACCEPTED, ['content-type' => 'application/json'], '{"status":"accepted"}');
    }

    /**
     * GET /relay/drain — return all pending blobs for the authenticated device.
     *
     * Required query parameter:
     *   - did  string: recipient device_id whose mailbox to drain
     *
     * Authorization: Bearer token in Authorization header must match
     * RelayConfig::authToken() (T-13-13). Returns 401 on mismatch or missing token.
     *
     * Response: JSON array of pending blobs, each with:
     *   { "id": int, "sender_did": string, "blob": string (base64), "created_at": string }
     *
     * ZK: blobs are base64-encoded and returned verbatim — no decryption.
     */
    private function handleDrain(Request $request): Response
    {
        // Extract the recipient device_id from the query string. Authorization is
        // bound to this $did (CR-04), so it must be resolved before the auth check.
        $query = $request->getUri()->getQuery();
        $params = [];
        parse_str($query, $params);
        $did = isset($params['did']) && is_string($params['did']) ? $params['did'] : '';

        if ($did === '') {
            return $this->jsonError(HttpStatus::BAD_REQUEST, 'missing_did');
        }

        if (! $this->isAuthorized($request, $did)) {
            return $this->jsonError(HttpStatus::UNAUTHORIZED, 'unauthorized');
        }

        try {
            $rows = $this->mailbox->drain($did);
        } catch (\Throwable $e) {
            $this->logger->error('relay:serve: drain failed.', ['error' => $e->getMessage()]);

            return $this->jsonError(HttpStatus::INTERNAL_SERVER_ERROR, 'drain_failed');
        }

        $payload = [];
        foreach ($rows as $row) {
            // ZK: blob is stored verbatim binary; base64-encode for JSON transport.
            // No sodium calls, no decryption, no json_decode of blob content.
            $blobB64 = is_string($row->blob) ? base64_encode($row->blob) : '';
            $payload[] = [
                'id' => $row->id,
                'sender_did' => $row->sender_did,
                'blob' => $blobB64,
                'created_at' => $row->created_at,
            ];
        }

        $json = json_encode(['blobs' => $payload], JSON_THROW_ON_ERROR);

        return new Response(HttpStatus::OK, ['content-type' => 'application/json'], $json);
    }

    /**
     * DELETE /relay/drain/{id} — confirm delivery of a blob by id.
     *
     * Authorization: Bearer token required (T-13-13).
     *
     * Marks the blob as delivered (sets delivered_at, resets expires_at to 7d TTL)
     * so it is cleaned up by the GC sweep. The blob is NOT deleted immediately —
     * this gives the draining device a chance to re-confirm if it crashes after
     * draining but before persisting locally.
     *
     * ZK: no blob content is read or touched in this method.
     */
    private function handleConfirm(Request $request, int $id): Response
    {
        // Bind authorization to the mailbox owner of this row (CR-04): only the
        // recipient device that owns the row may confirm-delete it. Resolve the
        // recipient_did (routing metadata only — never the blob) and require a
        // per-device token scoped to that device.
        $recipientDid = $this->mailbox->recipientDidFor($id);
        if ($recipientDid === null) {
            // Unknown row id. Return 401 (not 404) so a caller cannot probe which
            // ids exist without already holding the owning device's token.
            return $this->jsonError(HttpStatus::UNAUTHORIZED, 'unauthorized');
        }

        if (! $this->isAuthorized($request, $recipientDid)) {
            return $this->jsonError(HttpStatus::UNAUTHORIZED, 'unauthorized');
        }

        try {
            $this->mailbox->confirm($id);
        } catch (\Throwable $e) {
            $this->logger->error('relay:serve: confirm failed.', [
                'id' => $id,
                'error' => $e->getMessage(),
            ]);

            return $this->jsonError(HttpStatus::INTERNAL_SERVER_ERROR, 'confirm_failed');
        }

        return new Response(HttpStatus::OK, ['content-type' => 'application/json'], '{"status":"confirmed"}');
    }

    /**
     * Check whether the incoming request is authorized to drain/confirm the
     * mailbox belonging to $did (CR-04).
     *
     * Authorization is BOUND to the specific device whose mailbox is being
     * accessed: the presented bearer token must equal the per-device token
     * derived via RelayConfig::deriveDeviceToken($did)
     * (= HMAC-SHA256(authToken, did)). A single relay-wide token is NOT accepted;
     * a token scoped to device A cannot drain or confirm device B's mailbox.
     * This preserves the ZK threat model (T-13-13): the relay only HMACs the
     * device_id (routing metadata it already sees) with the secret it already
     * holds — it never reads blobs or learns a user_id.
     *
     * Timing-safe comparison via hash_equals() prevents timing oracle attacks.
     *
     * Returns false when:
     *   - No token is configured in RelayConfig (relay was set up without a token)
     *   - $did is empty (no mailbox to bind the token to)
     *   - The Authorization header is absent or malformed
     *   - The presented token does not match the per-device derived token
     *
     * Authorization enforcement lives HERE (at the endpoint), NOT inside
     * RelayMailbox. RelayMailbox is a pure ZK store (T-13-13).
     */
    private function isAuthorized(Request $request, string $did): bool
    {
        // Per-device token derived from the relay secret + the requested mailbox.
        $expectedToken = $this->config->deriveDeviceToken($did);
        if ($expectedToken === null) {
            // No token configured, or empty $did — deny.
            return false;
        }

        $authHeader = $request->getHeader('authorization');
        if ($authHeader === null || $authHeader === '') {
            return false;
        }

        // Expect "Bearer <token>" format.
        if (! str_starts_with($authHeader, 'Bearer ')) {
            return false;
        }

        $presentedToken = substr($authHeader, strlen('Bearer '));

        return hash_equals($expectedToken, $presentedToken);
    }

    /**
     * Return a JSON error response.
     */
    private function jsonError(int $status, string $errorCode): Response
    {
        $body = json_encode(['error' => $errorCode], JSON_THROW_ON_ERROR);

        return new Response($status, ['content-type' => 'application/json'], $body);
    }
}
