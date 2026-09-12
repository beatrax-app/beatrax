<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Modules\Core\Public\Support\MessageNamesNoUserData;
use Modules\DevMode\Internal\Logging\RedactSecretsProcessor;
use Monolog\Formatter\LineFormatter;
use Monolog\Level;
use Monolog\LogRecord;

// The counterparty and the IBAN off one statement row. QueryException folds
// its bindings INTO its message, so these two are what a failed write puts in
// front of anything that renders the exception.
const THROWABLE_CONTEXT_COUNTERPARTY = 'Albert Heijn 1042';

const THROWABLE_CONTEXT_IBAN = 'NL91ABNA0417164300';

/** The exception a failed write against a sealed table raises. */
function throwableContextQueryFailure(): QueryException
{
    return new QueryException(
        'sqlite',
        'insert into "transactions" ("counterparty_name", "counterparty_iban") values (?, ?)',
        [THROWABLE_CONTEXT_COUNTERPARTY, THROWABLE_CONTEXT_IBAN],
        new PDOException('SQLSTATE[23000]: Integrity constraint violation'),
    );
}

/**
 * @param  array<string, mixed>  $context
 */
function throwableContextRecord(array $context): LogRecord
{
    return new LogRecord(
        datetime: new DateTimeImmutable,
        channel: 'testing',
        level: Level::Error,
        message: 'InitialSyncPuller: history re-projection failed; will retry on the next pull.',
        context: $context,
    );
}

/**
 * The formatter LogManager builds for every file channel:
 * `new LineFormatter(null, $dateFormat, true, true, true)`. That last `true`
 * is includeStacktraces, which is why the object form also wrote a raw trace.
 */
function throwableContextFormatter(): LineFormatter
{
    return new LineFormatter(null, null, true, true, true);
}

/**
 * @return array<array-key, mixed>
 */
function throwableContextArray(mixed $value): array
{
    return is_array($value) ? $value : [];
}

function throwableContextLine(LogRecord $record): string
{
    return throwableContextFormatter()->format((new RedactSecretsProcessor)($record));
}

it('replaces a throwable handed to a log call as a context value', function (): void {
    $out = (new RedactSecretsProcessor)(throwableContextRecord([
        'user_id' => 1,
        'exception' => throwableContextQueryFailure(),
    ]));

    $described = throwableContextArray($out->context['exception'] ?? null);

    expect($described)->toHaveKey('sqlstate')
        ->and($described['reason'])->toBe(QueryException::class)
        ->and($described['message'])->toBe('QueryException')
        ->and($described['trace'])->toBeString()
        ->and($out->context['user_id'])->toBe(1);
});

// The processor runs before the formatter, so what the formatter is handed has
// to carry no row already. Without the replacement this line is the statement.
it('keeps the bindings out of what the shipped formatter writes', function (): void {
    $line = throwableContextLine(throwableContextRecord([
        'exception' => throwableContextQueryFailure(),
    ]));

    expect($line)->not->toContain(THROWABLE_CONTEXT_COUNTERPARTY)
        ->and($line)->not->toContain(THROWABLE_CONTEXT_IBAN)
        ->and($line)->not->toContain('insert into')
        ->and($line)->toContain('QueryException');
});

// LineFormatter walks getPrevious() and formats every link the same way, so a
// wrapper whose own message is harmless still published the one beneath it.
it('follows the previous chain the formatter would have followed', function (): void {
    $wrapped = new RuntimeException('That name is already taken.', 0, throwableContextQueryFailure());

    $described = throwableContextArray(
        (new RedactSecretsProcessor)(throwableContextRecord(['exception' => $wrapped]))->context['exception'] ?? null
    );
    $previous = throwableContextArray($described['previous'] ?? null);

    expect($previous['reason'])->toBe(QueryException::class)
        ->and(throwableContextLine(throwableContextRecord(['exception' => $wrapped])))
        ->not->toContain(THROWABLE_CONTEXT_IBAN);
});

// A class that promised its message names no value out of a row keeps it. The
// replacement applies the rule the call sites already follow, not a blanket
// strip that would leave a reader with a class name and nothing else.
it('keeps the message of a class that promised it names no row', function (): void {
    $promised = new class('Unrecognised CSV layout for this adapter.') extends RuntimeException implements MessageNamesNoUserData {};

    $described = throwableContextArray(
        (new RedactSecretsProcessor)(throwableContextRecord(['exception' => $promised]))->context['exception'] ?? null
    );

    expect($described['message'])->toBe('Unrecognised CSV layout for this adapter.');
});

it('leaves a context value that is not a throwable alone', function (): void {
    $out = (new RedactSecretsProcessor)(throwableContextRecord([
        'import_run_id' => 42,
        'reason' => 'RuntimeException',
        'nested' => ['exception' => throwableContextQueryFailure()],
    ]));

    $nested = throwableContextArray($out->context['nested'] ?? null);

    expect($out->context['import_run_id'])->toBe(42)
        ->and($out->context['reason'])->toBe('RuntimeException')
        ->and(throwableContextArray($nested['exception'] ?? null))->toHaveKey('reason');
});

// The chain is whatever the throwing code built, so the replacement stops at a
// fixed depth. That boundary is the one place an off-by-one would be silent:
// too shallow and a wrapped QueryException goes unreplaced, too deep and a
// pathological chain is unbounded work on every log line.
it('replaces four links and stops', function (): void {
    // Four deep exactly, so the QueryException sits on the last link the cap
    // still reaches and is replaced rather than dropped.
    $chain = new RuntimeException('D', 0,
        new RuntimeException('C', 0,
            new RuntimeException('B', 0, throwableContextQueryFailure())
        )
    );

    $described = throwableContextArray(
        (new RedactSecretsProcessor)(throwableContextRecord(['exception' => $chain]))->context['exception'] ?? null
    );

    $links = 0;
    $cursor = $described;
    $deepest = [];
    while ($cursor !== []) {
        $links++;
        $deepest = $cursor;
        $cursor = throwableContextArray($cursor['previous'] ?? null);
    }

    // Four: the throwable itself plus three `previous` links, which is
    // MAX_PREVIOUS_DEPTH. A fifth would mean the cap moved.
    expect($links)->toBe(4)
        ->and($deepest['reason'])->toBe(QueryException::class);
});

it('drops rather than publishes the link past the depth limit', function (): void {
    // One link deeper, so the QueryException — and the PDOException under it —
    // fall past the cap. They must be absent, not handed to the formatter as
    // throwables, which would render them and everything beneath.
    $past = new RuntimeException('A', 0,
        new RuntimeException('B', 0,
            new RuntimeException('C', 0,
                new RuntimeException('D', 0, throwableContextQueryFailure())
            )
        )
    );

    $line = throwableContextLine(throwableContextRecord(['exception' => $past]));

    expect(substr_count($line, '"previous"'))->toBe(3)
        ->and($line)->not->toContain(THROWABLE_CONTEXT_IBAN)
        ->and($line)->not->toContain(THROWABLE_CONTEXT_COUNTERPARTY)
        ->and($line)->not->toContain('insert into')
        ->and($line)->not->toContain('QueryException')
        ->and($line)->not->toContain('[object]');
});
