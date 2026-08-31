<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Logging;

use Modules\DevMode\Internal\Services\OAuthScrubSet;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

final readonly class RedactSecretsProcessor implements ProcessorInterface
{
    // Keys whose VALUE is a credential whatever it looks like. Guzzle and
    // Laravel log headers as ['Authorization' => 'Bearer ...'], and
    // scrubArray() visits each leaf on its own, so the header word and the
    // token were never in one string for a value pattern to match.
    /** @var list<string> */
    private const array SECRET_KEYS = [
        'authorization', 'proxy_authorization', 'cookie', 'set_cookie',
        'x_api_key', 'api_key', 'apikey', 'x_auth_token',
        'token', 'access_token', 'refresh_token', 'id_token', 'tokens_blob',
        'password', 'secret', 'client_secret', 'private_key', 'kek', 'dek',
    ];

    // Value shapes, each anchored on a prefix that only a credential carries,
    // so an ordinary log line is never touched.
    /** @var array<string, string> */
    private const array VALUE_PATTERNS = [
        '/(Authorization:\s*)(Bearer|Basic)\s+\S+/i' => '$1$2 [REDACTED]',
        // Without the header word a length floor keeps the English word
        // "bearer" followed by an ordinary word out of it.
        '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{8,}/i' => '$1 [REDACTED]',
        '/\bya29\.[A-Za-z0-9._-]{10,}/' => '[REDACTED]',
        '/\bgh[pousr]_[A-Za-z0-9]{20,}/' => '[REDACTED]',
        '/\bsk_(?:live|test)_[A-Za-z0-9]{10,}/' => '[REDACTED]',
        '/\bxox[baprs]-[A-Za-z0-9-]{10,}/' => '[REDACTED]',
        // A DSN carries its password in the userinfo segment,
        // scheme://user:password@host, which no key-name match reaches.
        '/([a-z][a-z0-9+.-]*:\/\/)[^\/\s:@]+:[^\/\s@]+@/i' => '$1[REDACTED]@',
    ];

    // The signature of an HS256 JWT is 43 characters and its payload can be
    // shorter still, so requiring 20 in every segment let real tokens through.
    private const string JWT_PATTERN = '/eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/';

    // Nullable so the Bearer + JWT branches can be exercised without a
    // container; the binding always passes the real singleton.
    public function __construct(
        private ?OAuthScrubSet $scrubSet = null,
    ) {}

    public function __invoke(LogRecord $record): LogRecord
    {
        $message = $this->scrub($record->message);
        $context = $this->scrubArray($record->context);
        $extra = $this->scrubArray($record->extra);

        return $record->with(
            message: $message,
            context: $context,
            extra: $extra,
        );
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private function scrubArray(array $values): array
    {
        $out = [];
        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $out[$key] = $this->scrubArray($value);
            } elseif ($this->isSecretKey($key)) {
                $out[$key] = '[REDACTED]';
            } elseif (is_string($value)) {
                $out[$key] = $this->scrub($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    // OAuth scrub-set runs first (so JWT-shaped real tokens are
    // recognised as [REDACTED] rather than [JWT_REDACTED]), then the
    // Bearer header pattern, then the JWT shape.
    public function scrub(string $text): string
    {
        if ($this->scrubSet !== null) {
            $pattern = $this->scrubSet->compiledPattern();
            if ($pattern !== null) {
                $replaced = preg_replace($pattern, '[REDACTED]', $text);
                if (is_string($replaced)) {
                    $text = $replaced;
                }
            }
        }

        foreach (self::VALUE_PATTERNS as $pattern => $replacement) {
            $text = (string) preg_replace($pattern, $replacement, $text);
        }

        return (string) preg_replace(self::JWT_PATTERN, '[JWT_REDACTED]', $text);
    }

    private function isSecretKey(int|string $key): bool
    {
        if (! is_string($key)) {
            return false;
        }

        return in_array(strtolower(str_replace(['-', ' '], '_', $key)), self::SECRET_KEYS, true);
    }
}
