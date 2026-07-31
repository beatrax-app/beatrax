<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Clients;

use JsonException;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Internal\SafeMessage;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

// Owns the Microsoft Graph error surface: turns a non-2xx response into
// the module's typed sentinels, parses the Retry-After hint against the
// injected clock, and caps provider error text so a verbose IdP body
// cannot contaminate a log line or flash payload.
/**
 * @link ../../../../.docs/features/email-scan/architecture.md
 */
final class GraphErrorMapper
{
    private const UNRECOGNISED_ERROR_BODY = 'unrecognised error body';

    public function __construct(private readonly Clock $clock) {}

    // Translates a non-2xx response into the right typed sentinel: 429
    // becomes RateLimitedException, 410/syncStateNotFound on a delta
    // call becomes CursorExpiredException, everything else becomes a
    // RuntimeException carrying the safe error message.
    public function mapErrorResponse(
        ?ResponseInterface $response,
        string $context,
        bool $expectsDelta = false,
    ): RuntimeException {
        if ($response === null) {
            return new RuntimeException(
                'GraphApiClient: '.$context.' — provider returned no response.',
            );
        }

        $status = $response->getStatusCode();
        $safeBodyMessage = $this->extractErrorMessage((string) $response->getBody());

        return match (true) {
            $status === Response::HTTP_TOO_MANY_REQUESTS => new RateLimitedException(
                retryAfterSeconds: $this->parseRetryAfter($response->getHeaderLine('Retry-After')),
                message: 'Microsoft Graph rate limit exceeded: '.$safeBodyMessage,
            ),
            $expectsDelta && $status === Response::HTTP_GONE => CursorExpiredException::graph($safeBodyMessage),
            default => new RuntimeException(
                'GraphApiClient: '.$context.' returned HTTP '.$status.' — '.$safeBodyMessage,
            ),
        };
    }

    // Caps the surfaced message and strips newlines so a verbose
    // provider error cannot contaminate a flash payload or log line;
    // delegates to the shared utility so the cap stays consistent
    // across the module's error-forwarding surfaces.
    public function safeMessage(string $raw): string
    {
        return SafeMessage::cap($raw);
    }

    // Parses the Retry-After header into a seconds value. Graph
    // documents it as delta-seconds, but the broader HTTP spec also
    // allows an HTTP-date, converted against the injected Clock; falls
    // back to a 60-second default when missing or unparseable.
    private function parseRetryAfter(string $header): int
    {
        $trimmed = trim($header);

        if (preg_match('/^\d+$/', $trimmed) === 1) {
            $seconds = (int) $trimmed;

            return $seconds > 0 ? $seconds : 60;
        }

        // Non-numeric (or empty): read it as an HTTP-date against the clock,
        // and fall back to 60s whenever that is absent, unparseable, or past.
        $when = $trimmed === '' ? false : strtotime($trimmed);
        $delta = $when === false ? 0 : $when - $this->clock->now()->getTimestamp();

        return $delta > 0 ? $delta : 60;
    }

    // Extracts error.message from a Graph error body without ever
    // including request headers or the bearer token; caps the message
    // so a verbose IdP error cannot contaminate logging above.
    private function extractErrorMessage(string $rawBody): string
    {
        if ($rawBody === '') {
            return 'no body returned';
        }
        try {
            /** @var mixed $decoded */
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->safeMessage($rawBody);
        }
        $err = is_array($decoded) ? ($decoded['error'] ?? null) : null;
        // Graph puts the human-readable text under error.message, and a
        // machine code under error.code; prefer the former, accept the
        // latter, and treat anything else as an unrecognised body.
        $message = is_array($err)
            ? self::firstNonEmptyString($err['message'] ?? null, $err['code'] ?? null)
            : null;

        return $message === null ? self::UNRECOGNISED_ERROR_BODY : $this->safeMessage($message);
    }

    // Returns the first argument that is a non-empty string, or null when
    // none is — used to prefer a provider's message over its bare code.
    private static function firstNonEmptyString(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
