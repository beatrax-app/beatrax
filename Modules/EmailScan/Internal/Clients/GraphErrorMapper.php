<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\Clients;

use JsonException;
use Modules\Core\Public\Contracts\Clock;
use Modules\EmailScan\Internal\SafeMessage;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

final class GraphErrorMapper
{
    private const UNRECOGNISED_ERROR_BODY = 'unrecognised error body';

    public function __construct(private readonly Clock $clock) {}

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

    // Caps and strips newlines so a verbose provider error cannot
    // contaminate a flash payload or a log line.
    public function safeMessage(string $raw): string
    {
        return SafeMessage::cap($raw);
    }

    // Graph documents Retry-After as delta-seconds, but HTTP also permits
    // an HTTP-date; 60s stands in when it is missing or unparseable.
    private function parseRetryAfter(string $header): int
    {
        $trimmed = trim($header);

        if (preg_match('/^\d+$/', $trimmed) === 1) {
            $seconds = (int) $trimmed;

            return $seconds > 0 ? $seconds : 60;
        }

        $when = $trimmed === '' ? false : strtotime($trimmed);
        $delta = $when === false ? 0 : $when - $this->clock->now()->getTimestamp();

        return $delta > 0 ? $delta : 60;
    }

    // Never includes request headers or the bearer token.
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
        // Graph puts human-readable text under error.message and a machine
        // code under error.code; prefer the former.
        $message = is_array($err)
            ? self::firstNonEmptyString($err['message'] ?? null, $err['code'] ?? null)
            : null;

        return $message === null ? self::UNRECOGNISED_ERROR_BODY : $this->safeMessage($message);
    }

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
