<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Logging;

use Modules\DevMode\Internal\Services\OAuthScrubSet;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

final class RedactSecretsProcessor implements ProcessorInterface
{
    private const BEARER_PATTERN = '/Authorization:\s*Bearer\s+\S+/i';

    private const JWT_PATTERN = '/eyJ[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}\.[A-Za-z0-9_-]{20,}/';

    // OAuthScrubSet is nullable so direct instantiation (e.g. unit tests
    // exercising only the Bearer + JWT branches) still works; the
    // container binding always passes the real singleton.
    public function __construct(
        private readonly ?OAuthScrubSet $scrubSet = null,
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

        $text = (string) preg_replace(self::BEARER_PATTERN, 'Authorization: Bearer [REDACTED]', $text);
        $text = (string) preg_replace(self::JWT_PATTERN, '[JWT_REDACTED]', $text);

        return $text;
    }
}
