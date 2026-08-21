<?php

declare(strict_types=1);

use Modules\DevMode\Internal\Logging\RedactSecretsProcessor;
use Modules\DevMode\Internal\Services\OAuthScrubSet;
use Monolog\Level;
use Monolog\LogRecord;

// A named class rather than an anonymous one: pest runs each `it()` body
// inside a Closure, and an anonymous class declared there is redeclared on
// repeat iterations. Overriding all three public methods sidesteps the
// parent's private cache without touching the DB.
class FixedOAuthScrubSetStub extends OAuthScrubSet
{
    /**
     * @param  list<string>  $preloaded
     */
    public function __construct(private array $preloaded) {}

    public function all(): array
    {
        return $this->preloaded;
    }

    public function compiledPattern(): ?string
    {
        if ($this->preloaded === []) {
            return null;
        }
        $alt = implode('|', array_map(
            static fn (string $s): string => preg_quote($s, '/'),
            $this->preloaded,
        ));

        return '/('.$alt.')/';
    }

    public function bust(): void {}
}

function newProcessorRecord(string $message, array $context = [], array $extra = []): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'testing',
        level: Level::Info,
        message: $message,
        context: $context,
        extra: $extra,
    );
}

it('scrubs an Authorization: Bearer header in the message (Test 1)', function (): void {
    $processor = new RedactSecretsProcessor(new FixedOAuthScrubSetStub([]));

    $out = $processor(newProcessorRecord('Authorization: Bearer abc123def456_xyz'));

    expect($out->message)->toBe('Authorization: Bearer [REDACTED]');
});

it('scrubs a JWT-shape token in the message (Test 2)', function (): void {
    $processor = new RedactSecretsProcessor(new FixedOAuthScrubSetStub([]));

    // 28 / 26 / 43 char segments — over the 20-char per-segment minimum the
    // JWT regex enforces.
    $jwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0AAA.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c';
    $out = $processor(newProcessorRecord($jwt));

    expect($out->message)->toBe('[JWT_REDACTED]');
});

it('scrubs every OAuthScrubSet string from message + context recursively (Test 3)', function (): void {
    $processor = new RedactSecretsProcessor(new FixedOAuthScrubSetStub([
        'SECRET_ALPHA',
        'SECRET_BETA-with-symbols!?',
    ]));

    $record = newProcessorRecord(
        message: 'leaked SECRET_ALPHA in body',
        context: [
            'headers' => ['provider' => 'used SECRET_BETA-with-symbols!? today'],
            'nested' => ['SECRET_ALPHA'],
            'untouched_int' => 42,
        ],
        extra: ['copy' => 'SECRET_ALPHA again'],
    );

    $out = $processor($record);

    expect($out->message)->toBe('leaked [REDACTED] in body');
    expect($out->context['headers']['provider'])->toBe('used [REDACTED] today');
    expect($out->context['nested'][0])->toBe('[REDACTED]');
    expect($out->context['untouched_int'])->toBe(42);
    expect($out->extra['copy'])->toBe('[REDACTED] again');
});

it('runs the OAuth scrub-set BEFORE the JWT pattern so a JWT-shaped real token is [REDACTED] not [JWT_REDACTED]', function (): void {
    // The same string is JWT-shaped and in the scrub set, so the marker in
    // the output is what says which layer ran first.
    $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiI5OTk5OTk5OTk5In0BBB.aaaaaaaaaaaaaaaaaaaaaaaaa';
    $processor = new RedactSecretsProcessor(new FixedOAuthScrubSetStub([$jwt]));

    $out = $processor(newProcessorRecord($jwt));

    expect($out->message)->toBe('[REDACTED]');
    expect($out->message)->not->toBe('[JWT_REDACTED]');
});

it('falls back to Bearer + JWT only when OAuthScrubSet is empty', function (): void {
    $processor = new RedactSecretsProcessor(new FixedOAuthScrubSetStub([]));

    $jwt = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0AAA.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c';
    $out = $processor(newProcessorRecord("auth Authorization: Bearer foo and {$jwt}"));

    expect($out->message)->toContain('Authorization: Bearer [REDACTED]');
    expect($out->message)->toContain('[JWT_REDACTED]');
});

it('skips the scrub-set step gracefully when constructed with null OAuthScrubSet (baseline-test compatibility)', function (): void {
    $processor = new RedactSecretsProcessor(null);

    $out = $processor(newProcessorRecord('Authorization: Bearer xyz'));

    expect($out->message)->toBe('Authorization: Bearer [REDACTED]');
});
