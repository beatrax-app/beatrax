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
use Modules\Sync\Internal\Transport\Relay\RelayClient;
use Modules\Sync\Internal\Transport\Relay\RelayConfig;
use Modules\Sync\Internal\Transport\Relay\RelayMailbox;
use Psr\Log\LoggerInterface;

/**
 * @link ../../../.docs/features/sync/architecture.md
 * @see SyncServiceProvider
 */
final class RelayServeCommand extends Command
{
    /** @var string */
    protected $signature = 'relay:serve {--port=51338 : Relay HTTP listen port}';

    /** @var string */
    protected $description = 'Start the ZK relay HTTP endpoint daemon (POST /relay/deliver, GET /relay/drain, DELETE /relay/drain/{id}).';

    // Resource-exhaustion guard: deliver is open-submission by design, so a flood
    // of deliveries into one recipient could grow the SQLite file unbounded for
    // the 30-day undelivered TTL. 1000 is generous headroom above a realistic
    // personal multi-device backlog.
    private const MAX_PENDING_PER_RECIPIENT = 1000;

    // Resource-exhaustion guard: an unbounded drain would force the server to
    // serialize (and the draining device to buffer) an entire mailbox backlog in
    // one JSON response. Callers loop drain -> confirm -> drain again until
    // fewer than this many rows come back.
    private const DRAIN_PAGE_SIZE = 100;

    // Device ids are UUID v4 strings, but this pattern is deliberately
    // format-agnostic beyond a safe, bounded character class (letters, digits,
    // -, _, :, .) capped at 128 bytes, rejecting control characters and
    // unbounded-length strings without coupling to one specific id scheme.
    private const DID_PATTERN = '/^[A-Za-z0-9_:.-]{1,128}$/';

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

        if ($method === 'DELETE' && str_starts_with($path, '/relay/drain/')) {
            $idStr = ltrim(substr($path, strlen('/relay/drain/')), '/');
            if ($idStr !== '' && ctype_digit($idStr)) {
                return $this->handleConfirm($request, (int) $idStr);
            }
        }

        return new Response(HttpStatus::NOT_FOUND, ['content-type' => 'application/json'], '{"error":"not_found"}');
    }

    // POST /relay/deliver — open submission; the recipient's drain token gates
    // retrieval, not this endpoint. Bounded by blob size, DID_PATTERN, and
    // MAX_PENDING_PER_RECIPIENT since the endpoint is intentionally
    // unauthenticated (see class @link).
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

        if (! $this->isValidDid($senderDid) || ! $this->isValidDid($recipientDid)) {
            return $this->jsonError(HttpStatus::UNPROCESSABLE_ENTITY, 'invalid_did');
        }

        $blob = base64_decode($blobB64, strict: true);
        if ($blob === false || $blob === '') {
            return $this->jsonError(HttpStatus::BAD_REQUEST, 'invalid_blob_encoding');
        }

        // Mirror RelayClient's own cap server-side: a caller that hits this open
        // endpoint directly (bypassing RelayClient) must not be able to smuggle a
        // larger-than-intended blob past the client-only check.
        if (strlen($blob) > RelayClient::MAX_BLOB_BYTES) {
            return $this->jsonError(HttpStatus::PAYLOAD_TOO_LARGE, 'blob_too_large');
        }

        try {
            $stored = $this->mailbox->deliverIfUnderQuota(
                $senderDid,
                $recipientDid,
                $blob,
                self::MAX_PENDING_PER_RECIPIENT,
            );
        } catch (\Throwable $e) {
            $this->logger->error('relay:serve: deliver failed.', ['error' => $e->getMessage()]);

            return $this->jsonError(HttpStatus::INTERNAL_SERVER_ERROR, 'deliver_failed');
        }

        if (! $stored) {
            return $this->jsonError(HttpStatus::TOO_MANY_REQUESTS, 'mailbox_full');
        }

        return new Response(HttpStatus::ACCEPTED, ['content-type' => 'application/json'], '{"status":"accepted"}');
    }

    // GET /relay/drain — bearer-token authorized (see class @link); returns up
    // to DRAIN_PAGE_SIZE pending blobs, oldest first, base64-encoded and
    // unmodified (ZK).
    private function handleDrain(Request $request): Response
    {
        // Authorization is bound to $did, so it must be resolved before the auth
        // check runs.
        $query = $request->getUri()->getQuery();

        // parse_str() can build nested arrays from `did[]=x` syntax; the
        // is_string() guard below degrades those to '' (safe), and the explicit
        // init + @var annotation below keeps this PHPStan-strict clean.
        /** @var array<string, mixed> $params */
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
            $rows = $this->mailbox->drain($did, self::DRAIN_PAGE_SIZE);
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

    // DELETE /relay/drain/{id} — bearer-token authorized against the row's own
    // recipient (see class @link). Marks the blob delivered (7d TTL) rather than
    // deleting it immediately, so a draining device that crashes before
    // persisting locally can re-confirm.
    private function handleConfirm(Request $request, int $id): Response
    {
        // Resolve the recipient_did (routing metadata only — never the blob) and
        // require a per-device token scoped to that device before it is exposed.
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

    // Authorization is bound to the specific device whose mailbox is being
    // accessed (see class @link): timing-safe compare against the per-device
    // token derived from RelayConfig::deriveDeviceToken($did). Returns false
    // when no token is configured, $did is empty, or the header is missing/mismatched.
    private function isAuthorized(Request $request, string $did): bool
    {
        $expectedToken = $this->config->deriveDeviceToken($did);
        if ($expectedToken === null) {
            return false;
        }

        $authHeader = $request->getHeader('authorization');
        if ($authHeader === null || $authHeader === '') {
            return false;
        }

        if (! str_starts_with($authHeader, 'Bearer ')) {
            return false;
        }

        $presentedToken = substr($authHeader, strlen('Bearer '));

        return hash_equals($expectedToken, $presentedToken);
    }

    private function isValidDid(string $did): bool
    {
        return preg_match(self::DID_PATTERN, $did) === 1;
    }

    private function jsonError(int $status, string $errorCode): Response
    {
        $body = json_encode(['error' => $errorCode], JSON_THROW_ON_ERROR);

        return new Response($status, ['content-type' => 'application/json'], $body);
    }
}
