<?php

declare(strict_types=1);

namespace Modules\Sync\Commands;

use Amp\Http\HttpStatus;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\ClosureRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\BindContext;
use Amp\Socket\Certificate;
use Amp\Socket\InternetAddress;
use Amp\Socket\ServerTlsContext;
use Illuminate\Console\Command;
use Modules\Sync\Internal\Transport\DaemonShutdownSignal;
use Modules\Sync\Internal\Transport\Relay\RelayClient;
use Modules\Sync\Internal\Transport\Relay\RelayDeliverRateLimiter;
use Modules\Sync\Internal\Transport\Relay\RelayDrainRegistry;
use Modules\Sync\Internal\Transport\Relay\RelayMailbox;
use Modules\Sync\Internal\Transport\Relay\RelayTlsMaterial;
use Psr\Log\LoggerInterface;

/**
 * @link ../../../.docs/features/sync/architecture.md
 * @see SyncServiceProvider
 */
final class RelayServeCommand extends Command
{
    private const JSON_CONTENT_TYPE = 'application/json';

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
        private readonly RelayDrainRegistry $drainRegistry,
        private readonly RelayDeliverRateLimiter $rateLimiter,
        private readonly RelayTlsMaterial $tls,
        private readonly DaemonShutdownSignal $shutdown,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // The desktop bundle starts PHP with -d max_execution_time=120, and
        // a listener is by definition longer-lived than any request: the loop
        // fatalled every two minutes, the watchdog respawned it, and a peer
        // dialling during that gap got a refused connection.
        set_time_limit(0);

        $port = (int) $this->option('port');
        if ($port <= 0 || $port > 65535) {
            $this->error("relay:serve: invalid port {$port}.");

            return self::FAILURE;
        }

        $this->logger->info('relay:serve: starting ZK relay HTTP endpoint.', ['port' => $port]);

        try {
            $httpServer = SocketHttpServer::createForDirectAccess($this->logger);

            // TLS whenever material exists. The certificate is self-signed —
            // there is no CA for a LAN address — so peers trust it by pinning
            // the key from the QR rather than by name validation.
            $bindContext = $this->tlsBindContext();
            $httpServer->expose("0.0.0.0:{$port}", $bindContext);

            $requestHandler = new ClosureRequestHandler(
                fn (Request $request): Response => $this->route($request),
            );
            $errorHandler = new DefaultErrorHandler;
            $httpServer->start($requestHandler, $errorHandler);

            $this->logger->info('relay:serve: endpoint started.', ['port' => $port]);
            $stopHint = $this->canTrapSignals() ? 'SIGTERM/SIGINT to stop' : 'no signal handling on this runtime';
            $this->info("relay:serve: ZK relay listening on 0.0.0.0:{$port} ({$stopHint}).");

            // Identical wait to sync:serve's: a signal where ext-pcntl
            // exists, plus the host's stdin pipe closing — the only notice a
            // force-quit gives, and without it this relay outlived the app
            // and kept holding the port.
            $this->shutdown->await($this->canTrapSignals());
            $this->logger->info('relay:serve: shutdown requested — stopping relay.');

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
        $confirmId = $this->confirmIdFrom($method, $path);

        return match (true) {
            $method === 'POST' && $path === '/relay/deliver' => $this->handleDeliver($request),
            $method === 'GET' && $path === '/relay/drain' => $this->handleDrain($request),
            $confirmId !== null => $this->handleConfirm($request, $confirmId),
            default => new Response(HttpStatus::NOT_FOUND, ['content-type' => self::JSON_CONTENT_TYPE], '{"error":"not_found"}'),
        };
    }

    // The row id from a DELETE /relay/drain/{id}, or null when the request is
    // not that shape. A non-numeric or empty id is not-found rather than a bad
    // request: the path simply does not name a row.
    private function confirmIdFrom(string $method, string $path): ?int
    {
        if ($method !== 'DELETE' || ! str_starts_with($path, '/relay/drain/')) {
            return null;
        }

        $idStr = ltrim(substr($path, strlen('/relay/drain/')), '/');

        return $idStr !== '' && ctype_digit($idStr) ? (int) $idStr : null;
    }

    // POST /relay/deliver — open submission; the recipient's drain secret
    // gates retrieval, not this endpoint. Bounded by a per-source-IP rate
    // limit, blob size, DID_PATTERN, and MAX_PENDING_PER_RECIPIENT since the
    // endpoint is intentionally unauthenticated (see class @link).
    private function handleDeliver(Request $request): Response
    {
        // Per-source-IP throttle first, so a flood is refused before any JSON
        // parse or DB work — the cheapest possible rejection path.
        if (! $this->rateLimiter->allow($this->clientKey($request))) {
            return $this->jsonError(HttpStatus::TOO_MANY_REQUESTS, 'rate_limited');
        }

        $body = json_decode($request->getBody()->buffer(), true, 512, 0);
        $senderDid = $this->stringField($body, 'sender_did');
        $recipientDid = $this->stringField($body, 'recipient_did');
        $blobB64 = $this->stringField($body, 'blob');
        $blob = base64_decode($blobB64, strict: true);

        $rejection = $this->deliveryRejection($body, $senderDid, $recipientDid, $blobB64, $blob);
        if ($rejection !== null) {
            return $rejection;
        }

        /** @var string $blob a non-string was rejected above */
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

        return $stored
            ? new Response(HttpStatus::ACCEPTED, ['content-type' => self::JSON_CONTENT_TYPE], '{"status":"accepted"}')
            : $this->jsonError(HttpStatus::TOO_MANY_REQUESTS, 'mailbox_full');
    }

    // Every way a delivery can be refused, in the order they are reported. The
    // arms are evaluated top-down and short-circuit, so the blob-length check
    // never sees the `false` that a bad base64 decode returns.
    private function deliveryRejection(
        mixed $body,
        string $senderDid,
        string $recipientDid,
        string $blobB64,
        string|false $blob,
    ): ?Response {
        return match (true) {
            ! is_array($body) => $this->jsonError(HttpStatus::BAD_REQUEST, 'invalid_json'),
            $senderDid === '' || $recipientDid === '' || $blobB64 === '' => $this->jsonError(HttpStatus::BAD_REQUEST, 'missing_fields'),
            ! $this->isValidDid($senderDid) || ! $this->isValidDid($recipientDid) => $this->jsonError(HttpStatus::UNPROCESSABLE_ENTITY, 'invalid_did'),
            $blob === false || $blob === '' => $this->jsonError(HttpStatus::BAD_REQUEST, 'invalid_blob_encoding'),
            // Mirrors RelayClient's own cap server-side: a caller hitting this
            // open endpoint directly must not smuggle a larger-than-intended
            // blob past the client-only check.
            strlen($blob) > RelayClient::MAX_BLOB_BYTES => $this->jsonError(HttpStatus::PAYLOAD_TOO_LARGE, 'blob_too_large'),
            default => null,
        };
    }

    // A request field that must be a string, degraded to '' when it is absent
    // or some other shape — `blob[]=x` builds an array, and treating that as
    // missing is safer than letting it reach the decoder.
    private function stringField(mixed $body, string $key): string
    {
        return is_array($body) && isset($body[$key]) && is_string($body[$key]) ? $body[$key] : '';
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

        $rejection = $this->drainRejection($request, $did);
        if ($rejection !== null) {
            return $rejection;
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

        return new Response(HttpStatus::OK, ['content-type' => self::JSON_CONTENT_TYPE], $json);
    }

    // A drain is refused for one of two reasons, and the order matters: an
    // empty did is a malformed request, whereas a did that fails the token
    // check is an unauthorized one. Reporting the second for the first would
    // tell a caller a missing parameter looked like a credentials problem.
    private function drainRejection(Request $request, string $did): ?Response
    {
        return match (true) {
            $did === '' => $this->jsonError(HttpStatus::BAD_REQUEST, 'missing_did'),
            ! $this->isAuthorized($request, $did) => $this->jsonError(HttpStatus::UNAUTHORIZED, 'unauthorized'),
            default => null,
        };
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

        // An unknown row id and an unauthorized caller get the same answer on
        // purpose: 401 rather than 404, so a caller cannot probe which ids
        // exist without already holding the owning device's token.
        if ($recipientDid === null || ! $this->isAuthorized($request, $recipientDid)) {
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

        return new Response(HttpStatus::OK, ['content-type' => self::JSON_CONTENT_TYPE], '{"status":"confirmed"}');
    }

    // Authorization is bound to the specific device whose mailbox is being
    // accessed (see class @link): the presented Bearer token is verified
    // against this device's TOFU-registered per-device drain secret. Rejects
    // an empty did, a missing/non-Bearer header, and any non-matching token.
    private function isAuthorized(Request $request, string $did): bool
    {
        if ($did === '') {
            return false;
        }

        $authHeader = $request->getHeader('authorization');

        if ($authHeader === null || $authHeader === '' || ! str_starts_with($authHeader, 'Bearer ')) {
            return false;
        }

        // The registry runs the timing-safe compare and TOFU-registers a did's
        // first token; an empty bearer is rejected there before it registers.
        return $this->drainRegistry->authorizes($did, substr($authHeader, strlen('Bearer ')));
    }

    // The source IP the deliver rate-limit buckets on. amphp always hands a
    // real remote address here; InternetAddress drops the ephemeral port so
    // every connection from one host shares one bucket.
    private function clientKey(Request $request): string
    {
        $address = $request->getClient()->getRemoteAddress();

        return $address instanceof InternetAddress ? $address->getAddress() : $address->toString();
    }

    private function isValidDid(string $did): bool
    {
        return preg_match(self::DID_PATTERN, $did) === 1;
    }

    private function jsonError(int $status, string $errorCode): Response
    {
        $body = json_encode(['error' => $errorCode], JSON_THROW_ON_ERROR);

        return new Response($status, ['content-type' => self::JSON_CONTENT_TYPE], $body);
    }

    // ext-pcntl is absent from the bundled desktop PHP, so the SIGTERM and
    // SIGINT constants do not exist there and referencing them is fatal.
    private function tlsBindContext(): ?BindContext
    {
        if (! $this->tls->exists()) {
            $this->logger->warning('relay:serve: no TLS material; serving plaintext.');

            return null;
        }

        return (new BindContext)->withTlsContext(
            (new ServerTlsContext)->withDefaultCertificate(
                new Certificate($this->tls->certPath(), $this->tls->keyPath()),
            ),
        );
    }

    private function canTrapSignals(): bool
    {
        return \function_exists('pcntl_signal') && \defined('SIGTERM') && \defined('SIGINT');
    }
}
