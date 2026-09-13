<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Logging;

use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Core\Public\Support\SafeTrace;
use Modules\DevMode\Internal\Services\OAuthScrubSet;
use Modules\DevMode\Internal\Support\RedactedText;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Throwable;

final readonly class RedactSecretsProcessor implements ProcessorInterface
{
    // What every rule here writes in place of what it matched. One spelling,
    // because a reader greps for this string to check a log was scrubbed.
    private const string REDACTED = '[REDACTED]';

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

    // Keys whose VALUE is private user content rather than a credential, kept
    // apart from SECRET_KEYS so neither list has to lie about what it holds. An
    // uploaded statement is named by the bank for the account it covers, so the
    // filename routinely spells an IBAN, a card number or the holder's name.
    /** @var list<string> */
    private const array PRIVATE_CONTENT_KEYS = [
        'filename', 'file_name', 'original_filename', 'source_filename',
    ];

    // Value shapes, each anchored on a prefix that only a credential carries,
    // so an ordinary log line is never touched.
    /** @var array<string, string> */
    private const array VALUE_PATTERNS = [
        '/(Authorization:\s*)(Bearer|Basic)\s+\S+/i' => '$1$2 '.self::REDACTED,
        // Without the header word a length floor keeps the English word
        // "bearer" followed by an ordinary word out of it.
        '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{8,}/i' => '$1 '.self::REDACTED,
        '/\bya29\.[A-Za-z0-9._-]{10,}/' => self::REDACTED,
        '/\bgh[pousr]_[A-Za-z0-9]{20,}/' => self::REDACTED,
        '/\bsk_(?:live|test)_[A-Za-z0-9]{10,}/' => self::REDACTED,
        '/\bxox[baprs]-[A-Za-z0-9-]{10,}/' => self::REDACTED,
        // A DSN carries its password in the userinfo segment,
        // scheme://user:password@host, which no key-name match reaches.
        '/([a-z][a-z0-9+.-]*:\/\/)[^\/\s:@]+:[^\/\s@]+@/i' => '$1'.self::REDACTED.'@',
    ];

    // The signature of an HS256 JWT is 43 characters and its payload can be
    // shorter still, so requiring 20 in every segment let real tokens through.
    private const string JWT_PATTERN = '/eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/';

    // How far down a `previous` chain the replacement below follows. Deep
    // enough for the wrap-and-rethrow shapes this tree has, bounded because
    // the chain is whatever the throwing code built.
    private const int MAX_PREVIOUS_DEPTH = 3;

    // Nullable so the Bearer + JWT branches can be exercised without a
    // container; the binding always passes the real singleton. The base path
    // is the prefix SafeTrace strips off each frame; blank means strip none.
    public function __construct(
        private ?OAuthScrubSet $scrubSet = null,
        private string $basePath = '',
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
            } elseif ($this->isRedactedKey($key)) {
                $out[$key] = self::REDACTED;
            } elseif ($value instanceof Throwable) {
                $out[$key] = $this->describeThrowable($value, 0);
            } elseif (is_string($value)) {
                $out[$key] = $this->scrub($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    // Every rule above reads a string or walks an array, so a throwable left
    // here untouched and the FORMATTER rendered it: the message, the raw
    // trace, and every `previous` beneath it. A QueryException's message is
    // the statement with its bindings, so this one is replaced, not scrubbed.
    /**
     * @return array<string, mixed>
     */
    private function describeThrowable(Throwable $e, int $depth): array
    {
        $described = SafeExceptionContext::describe($e) + [
            'at' => $e->getFile().':'.$e->getLine(),
            // Only a class that promised its message names no row keeps it.
            'message' => SafeExceptionContext::reason($e),
            // SafeTrace and not the formatter's getTraceAsString(): that one
            // renders the first fifteen characters of every string argument.
            'trace' => SafeTrace::cap($e, $this->basePath),
        ];

        $previous = $e->getPrevious();

        if ($previous instanceof Throwable && $depth < self::MAX_PREVIOUS_DEPTH) {
            $described['previous'] = $this->describeThrowable($previous, $depth + 1);
        }

        return $described;
    }

    // OAuth scrub-set runs first (so JWT-shaped real tokens are
    // recognised as [REDACTED] rather than [JWT_REDACTED]), then the
    // Bearer header pattern, then the JWT shape.
    public function scrub(string $text): string
    {
        if ($this->scrubSet !== null) {
            $pattern = $this->scrubSet->compiledPattern();
            if ($pattern !== null) {
                $text = RedactedText::orEmpty($pattern, '[REDACTED]', $text);
            }
        }

        foreach (self::VALUE_PATTERNS as $pattern => $replacement) {
            $text = RedactedText::orEmpty($pattern, $replacement, $text);
        }

        return RedactedText::orEmpty(self::JWT_PATTERN, '[JWT_REDACTED]', $text);
    }

    private function isRedactedKey(int|string $key): bool
    {
        if (! is_string($key)) {
            return false;
        }

        $normalized = strtolower(str_replace(['-', ' '], '_', $key));

        return in_array($normalized, self::SECRET_KEYS, true)
            || in_array($normalized, self::PRIVATE_CONTENT_KEYS, true);
    }
}
