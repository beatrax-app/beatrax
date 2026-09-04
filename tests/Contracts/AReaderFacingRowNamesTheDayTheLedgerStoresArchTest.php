<?php

declare(strict_types=1);
use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\BackendSourceFiles;

// `transactions` carries two days. `posted_at` is a DATE, the column
// TransactionCursor sorts and pages on and the one every list, the detail page
// and the chains drawer print. `booked_at` is a DATETIME, the issuer's own
// booking stamp; every adapter but IcsPdfAdapter writes it equal to posted_at
// plus a synthetic time-of-day, so on all but one shipped fixture the two are
// the same day and nothing catches a row that names the wrong one.
//
// Six surfaces printed `booked_at` and were converted one at a time. The
// seventh was the import preview, which dated 36 of the 38 rows of the shipped
// ICS statement a day the commit does not write, and one of them a month:
// "Prime Video" previewed 01/02/2026 and landed on 2026-01-31. Three of the six
// converted files carried a comment explaining the rule the preview broke.
// This is that comment, once, with a walk under it.
// @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-list-sorted-by-a-column-it-does-not-show

// The seam every reader-facing day is formatted through. Named here so a walk
// that is looking for it fails loudly if the file is renamed or deleted rather
// than quietly finding nothing to enforce.
const LEDGER_DAY_SEAM = 'Modules/Ledger/Public/Support/LedgerDay.php';

// Types that carry the booking stamp because they WRITE it or because they are
// what an adapter read off a file, not because a reader is shown one. Each
// entry says which, and `proves` is re-run against the file so a type that
// stops being either fails here rather than keeping its exemption.
/** @var array<string, array{reason: string, sites: int, proves: list<string>}> */
const LEDGER_DAY_CARRIER_PINS = [
    'Modules/Ingestion/Public/Dto/SourceTransactionDto.php' => [
        'reason' => 'What an adapter read off the file, before anything has decided what to store. An ICS statement prints both days and means them, so the type that carries a source row carries both; the reader is shown a row built downstream from postedAt.',
        'sites' => 1,
        'proves' => [
            '/public readonly CarbonImmutable \$bookedAt,/',
            '/public readonly CarbonImmutable \$postedAt,/',
        ],
    ],
    'Modules/Ledger/Public/Dto/CanonicalTransaction.php' => [
        'reason' => 'The row about to be written. booked_at is a real column with real readers -- the fingerprint is composed over it, the transfer pairer matches on it -- so the type that answers with the columns names it.',
        'sites' => 1,
        'proves' => [
            '/public readonly CarbonImmutable \$bookedAt,/',
            "/'booked_at' => \\\$this->bookedAt->toDateTimeString\\(\\),/",
        ],
    ],
    'Modules/Receipts/Public/Dto/ParsedReceiptDto.php' => [
        'reason' => 'A receipt names one day and has no booked-versus-posted lag at all; the adapter below maps that single day onto all three canonical fields. Nothing renders this type as a row.',
        'sites' => 1,
        'proves' => ['/public readonly CarbonImmutable \$bookedAt,/'],
    ],
];

// The one view allowed to print the booking stamp, and the conditions under
// which it may. `proves` pins the condition, not just the read: a line that
// stopped comparing the two days would print a second date on every row of
// every other source, which is the noise the rule exists to prevent.
/** @var array<string, array{reason: string, sites: int, proves: list<string>}> */
const LEDGER_DAY_VIEW_PINS = [
    'Modules/Ledger/Resources/views/livewire/transaction-detail.blade.php' => [
        'reason' => 'The detail page draws a second, labelled "Booked" line under the posted date, and only when the two days actually differ. It is the one place where the second day is worth saying, because there is room to say which day it is.',
        'sites' => 1,
        'proves' => [
            '/\$bookedDay = SafeDate::/',
            '/ledger::detail\.booked_on/',
            '/! \$bookedDay->isSameDay\(\$postedDay\)/',
        ],
    ],
];

// Sources that carry exactly one day, where feeding postedAt from the variable
// holding it is not a choice between two days but the absence of a second one.
// Each names the same value into bookedAt on the line above or below.
/** @var array<string, array{reason: string, sites: int, proves: list<string>}> */
const LEDGER_DAY_SINGLE_DAY_SOURCE_PINS = [
    'Modules/Ingestion/Internal/Adapters/Paypal/PaypalTransactionRollup.php' => [
        'reason' => 'A PayPal export has one date column. All three canonical days collapse onto it.',
        'sites' => 1,
        'proves' => ['/bookedAt: \$bookedAt,\n\s+postedAt: \$bookedAt,\n\s+valueDate: \$bookedAt,/'],
    ],
    'Modules/OpenBanking/Internal/Adapters/EnableBanking/EnableBankingSourceAdapter.php' => [
        'reason' => 'booking_date drives both days, zeroed to midnight, to match the CAMT adapter. value_date reaches valueDate only, outside the fingerprint tuple.',
        'sites' => 1,
        'proves' => [
            '/bookedAt: \$bookedAt,\n\s+postedAt: \$bookedAt,/',
            '/booking_date drives both bookedAt and postedAt/',
        ],
    ],
    'Modules/Receipts/Public/Pipeline/ReceiptSourceAdapter.php' => [
        'reason' => 'A receipt has no booked-versus-posted lag, so the matcher\'s single day becomes all three.',
        'sites' => 1,
        'proves' => ['/bookedAt: \$parsed->bookedAt,\n\s+postedAt: \$parsed->bookedAt,\n\s+valueDate: \$parsed->bookedAt,/'],
    ],
];

// Not exemptions. Deliberately empty, and it has to stay spelled as a debt
// rather than folded into the pins above: an entry here would be a surface that
// still prints the wrong day and could not be reached because another owner
// held the file, carried with a count so that converting it turns this red and
// empties the table. The sweep that wrote this rule reached every surface, so
// there is nothing to carry.
/** @var array<string, array{owner: string, sites: int, proves: list<string>}> */
const LEDGER_DAY_HANDOVERS = [];

/**
 * Modules and app, minus the tests that assert about them and the migrations,
 * which move columns rather than show them to anyone.
 *
 * @return list<string>
 */
function ledgerDayBackendFiles(): array
{
    return ledgerDayFilesUnder(['Modules', 'app'], '.php', static fn (string $path): bool => ! str_contains($path, '/tests/')
        && ! str_contains($path, '/Database/Migrations/'));
}

/** @return list<string> every Blade template a reader can be shown */
function ledgerDayViewFiles(): array
{
    return ledgerDayFilesUnder(['Modules', 'resources'], '.blade.php', static fn (string $path): bool => ! str_contains($path, '/tests/'));
}

/**
 * @param  list<string>  $roots
 * @param  callable(string): bool  $keep
 * @return list<string>
 */
function ledgerDayFilesUnder(array $roots, string $extension, callable $keep): array
{
    $paths = [];

    foreach ($roots as $root) {
        $directory = base_path($root);
        if (! is_dir($directory)) {
            continue;
        }

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        ) as $file) {
            $path = $file->getPathname();
            if ($file->isFile() && str_ends_with($path, $extension) && $keep($path)) {
                $paths[] = $path;
            }
        }
    }

    sort($paths);

    return array_values(array_unique($paths));
}

/**
 * The types a reader meets as a row: everything under a Dto or Queries folder,
 * plus anything named for a row wherever it lives, because a Livewire component
 * called SomethingRow is one too.
 *
 * @param  list<string>  $paths
 * @return list<string>
 */
function ledgerDayRowTypes(array $paths): array
{
    return array_values(array_filter($paths, static function (string $path): bool {
        $name = basename($path, '.php');

        return str_contains($path, '/Dto/')
            || str_contains($path, '/Queries/')
            || str_ends_with($name, 'Row')
            || str_ends_with($name, 'RowDto');
    }));
}

/**
 * Every property or parameter a row type declares, told apart by whether it
 * names the booking stamp. `counted` covers all of them, so a walk that reads
 * nothing cannot pass for a tree that declares nothing.
 *
 * @param  list<string>  $paths
 * @return array{booking: array<string, list<string>>, counted: int}
 */
function ledgerDayCarriersIn(array $paths): array
{
    $booking = [];
    $counted = 0;

    foreach ($paths as $path) {
        $relative = str_replace(base_path().'/', '', $path);

        foreach (BackendSourceFiles::codeTokens($path) as $token) {
            if (! is_array($token) || $token[0] !== T_VARIABLE) {
                continue;
            }

            $counted++;

            if (preg_match('/^\$booked_?at$/i', $token[1]) === 1) {
                $booking[$relative][] = $relative.':'.$token[2].' declares '.$token[1];
            }
        }
    }

    ksort($booking);

    return ['booking' => $booking, 'counted' => $counted];
}

/**
 * A file's lines with every comment blanked and the line count preserved, so a
 * walk never reads prose about the rule as a breach of it and still reports the
 * line it found.
 *
 * @return list<string>
 */
function ledgerDayCodeLines(string $path): array
{
    $source = (string) file_get_contents($path);

    if (str_ends_with($path, '.blade.php')) {
        $source = PatternScan::replaceCallback(
            '/\{\{--.*?--\}\}/s',
            static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
            $source,
        );

        return explode("\n", $source);
    }

    $rebuilt = '';
    foreach (token_get_all($source) as $token) {
        $text = is_array($token) ? $token[1] : $token;
        $rebuilt .= is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)
            ? str_repeat("\n", substr_count($text, "\n"))
            : $text;
    }

    return explode("\n", $rebuilt);
}

/**
 * Every day a Blade reads off a row or a model, told apart by which column it
 * names. Both spellings, because a view reaches a DTO through `->postedAt` and
 * a model through `->posted_at`.
 *
 * @param  list<string>  $paths
 * @return array{booking: array<string, list<string>>, counted: int}
 */
function ledgerDayViewReadsIn(array $paths): array
{
    $booking = [];
    $counted = 0;

    foreach ($paths as $path) {
        $relative = str_replace(base_path().'/', '', $path);

        foreach (ledgerDayCodeLines($path) as $number => $line) {
            $matches = PatternScan::sets('/(?:->|\[\s*[\'"])\s*(posted_?at|booked_?at)\b/i', $line);

            foreach ($matches as $match) {
                $counted++;

                if (stripos($match[1], 'booked') === 0) {
                    $booking[$relative][] = $relative.':'.($number + 1).' prints '.$match[1];
                }
            }
        }
    }

    ksort($booking);

    return ['booking' => $booking, 'counted' => $counted];
}

/**
 * Every `postedAt:` argument in the tree, told apart by whether the expression
 * feeding it names the booking stamp. This is the trap a field rename alone
 * leaves open: `postedAt: shown($source->bookedAt)` satisfies every other walk
 * here and shows the reader exactly the day this rule exists to keep off screen.
 *
 * @param  list<string>  $paths
 * @return array{fed: array<string, list<string>>, counted: int}
 */
function ledgerDayFedFromBookingIn(array $paths): array
{
    $fed = [];
    $counted = 0;

    foreach ($paths as $path) {
        $relative = str_replace(base_path().'/', '', $path);

        foreach (ledgerDayCodeLines($path) as $number => $line) {
            if (preg_match('/(?<![\w$>])postedAt\s*:(?!:)(.*)$/', $line, $match) !== 1) {
                continue;
            }

            $counted++;

            if (preg_match('/booked/i', $match[1]) === 1) {
                $fed[$relative][] = $relative.':'.($number + 1).' takes '.trim($match[0]);
            }
        }
    }

    ksort($fed);

    return ['fed' => $fed, 'counted' => $counted];
}

/**
 * The `postedAt:` arguments handed to a row construction, and whether each one
 * that is a call reaches the seam. A variable is left alone: what it holds is
 * the previous walk's question, not this one's.
 *
 * @param  list<string>  $paths
 * @return array{astray: array<string, list<string>>, counted: int}
 */
function ledgerDayRowConstructionsIn(array $paths): array
{
    $astray = [];
    $counted = 0;

    foreach ($paths as $path) {
        $relative = str_replace(base_path().'/', '', $path);
        $tokens = BackendSourceFiles::codeTokens($path);

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || ! in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            $previous = $index - 1;
            while ($previous >= 0 && is_array($tokens[$previous]) && $tokens[$previous][0] === T_WHITESPACE) {
                $previous--;
            }

            if ($previous < 0 || ! is_array($tokens[$previous]) || $tokens[$previous][0] !== T_NEW) {
                continue;
            }

            $name = $token[1];
            if (! str_ends_with($name, 'Row') && ! str_ends_with($name, 'RowDto')) {
                continue;
            }

            $arguments = BackendSourceFiles::callArguments($tokens, $index);
            if ($arguments === '') {
                continue;
            }

            $counted++;

            if (preg_match('/postedAt\s*:\s*([A-Za-z_\\\\][\w\\\\]*(?:::[A-Za-z_]\w*)?)\s*\(/', $arguments, $call) !== 1) {
                continue;
            }

            if ($call[1] !== 'LedgerDay::shown') {
                $astray[$relative][] = $relative.':'.$token[2].' builds '.$name.' with postedAt: '.$call[1].'(...)';
            }
        }
    }

    ksort($astray);

    return ['astray' => $astray, 'counted' => $counted];
}

/**
 * The argument list of a call, split on its top-level commas. Nested calls and
 * array literals keep their own commas, or `diff: ['source_ref' => [...]]`
 * would read as four arguments.
 *
 * @return list<string>
 */
function ledgerDaySplitArguments(string $arguments): array
{
    $parts = [];
    $buffer = '';
    $depth = 0;

    foreach (str_split($arguments) as $character) {
        if (in_array($character, ['(', '[', '{'], true)) {
            $depth++;
        } elseif (in_array($character, [')', ']', '}'], true)) {
            $depth--;
        }

        if ($character === ',' && $depth === 0) {
            $parts[] = $buffer;
            $buffer = '';

            continue;
        }

        $buffer .= $character;
    }

    if (trim($buffer) !== '') {
        $parts[] = $buffer;
    }

    return $parts;
}

/**
 * The constructor parameters each row type declares, keyed by the class name it
 * is constructed under. Keyed on the declared name rather than the file name:
 * PSR-4 makes the two the same here, and a walk that silently depends on that
 * reports an empty tree the day they differ instead of saying so.
 *
 * @param  list<string>  $paths
 * @return array<string, list<string>>
 */
function ledgerDayRowParameters(array $paths): array
{
    $declared = [];

    foreach ($paths as $path) {
        $source = (string) file_get_contents($path);
        $classes = PatternScan::allWithOffsets('/\bclass\s+(\w+)/', $source);

        if ($classes[1] === []) {
            continue;
        }

        foreach ($classes[1] as $position => $class) {
            [$name, $offset] = $class;
            if (! str_ends_with($name, 'Row') && ! str_ends_with($name, 'RowDto')) {
                continue;
            }

            // Bounded at the next class in the file, so a second class below
            // cannot lend this one its constructor.
            $end = $classes[1][$position + 1][1] ?? strlen($source);
            $body = substr($source, $offset, $end - $offset);

            if (preg_match('/function\s+__construct\s*\((.*?)\)\s*\{/s', $body, $signature) !== 1) {
                continue;
            }

            $parameters = PatternScan::all('/\$(\w+)/', $signature[1]);
            $declared[$name] = array_values(array_unique($parameters[1]));
        }
    }

    return $declared;
}

/**
 * Fields a row type declares that nothing ever puts a value in. A copy of the
 * same field off another instance of the same type -- what a with-er or a
 * rename helper does -- carries no information either way and is discarded, or
 * one copy constructor would launder a field that is null at every real site.
 *
 * @param  list<string>  $paths
 * @return array{dead: array<string, list<string>>, counted: int}
 */
function ledgerDayNeverPopulatedIn(array $paths): array
{
    $declared = ledgerDayRowParameters($paths);
    $passed = [];
    $counted = 0;

    foreach ($paths as $path) {
        $relative = str_replace(base_path().'/', '', $path);
        $tokens = BackendSourceFiles::codeTokens($path);

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_STRING || ! isset($declared[$token[1]])) {
                continue;
            }

            $previous = $index - 1;
            while ($previous >= 0 && is_array($tokens[$previous]) && $tokens[$previous][0] === T_WHITESPACE) {
                $previous--;
            }

            if ($previous < 0 || ! is_array($tokens[$previous]) || $tokens[$previous][0] !== T_NEW) {
                continue;
            }

            $arguments = BackendSourceFiles::callArguments($tokens, $index);
            if ($arguments === '') {
                continue;
            }

            foreach (ledgerDaySplitArguments($arguments) as $argument) {
                if (preg_match('/^\s*(\w+)\s*:(?!:)(.*)$/s', $argument, $named) !== 1) {
                    continue;
                }

                $counted++;
                $passed[$token[1]][$named[1]][] = [trim($named[2]), $relative.':'.$token[2]];
            }
        }
    }

    $dead = [];

    foreach ($declared as $type => $parameters) {
        foreach ($parameters as $parameter) {
            $sites = $passed[$type][$parameter] ?? [];
            $informative = array_values(array_filter(
                $sites,
                static fn (array $site): bool => preg_match('/^\$\w+\??->'.preg_quote($parameter, '/').'$/', $site[0]) !== 1,
            ));

            if ($informative === []) {
                continue;
            }

            foreach ($informative as $site) {
                if (strtolower($site[0]) !== 'null') {
                    continue 2;
                }
            }

            $dead[$type][] = $type.'::$'.$parameter.' is null at all '.count($informative)
                .' site(s): '.implode(', ', array_map(static fn (array $site): string => $site[1], $informative));
        }
    }

    ksort($dead);

    return ['dead' => $dead, 'counted' => $counted];
}

/**
 * @param  array<string, list<string>>  $found
 * @return array<string, int>
 */
function ledgerDayByBasename(array $found): array
{
    $counts = [];
    foreach ($found as $relative => $sites) {
        $counts[basename($relative)] = count($sites);
    }

    return $counts;
}

/**
 * @param  array<string, list<string>>  $found
 * @param  array<string, array{reason?: string, owner?: string, sites: int, proves: list<string>}>  $pins
 * @return array{offenders: list<string>, reached: list<string>}
 */
function ledgerDayJudge(array $found, array $pins, string $noun): array
{
    $offenders = [];
    $reached = [];

    foreach ($found as $relative => $sites) {
        $pin = $pins[$relative] ?? null;

        if ($pin === null) {
            $offenders = array_merge($offenders, $sites);

            continue;
        }

        $reached[] = $relative;

        if (count($sites) !== $pin['sites']) {
            $offenders[] = $relative.' is pinned at '.$pin['sites'].' '.$noun.' and now has '.count($sites);
        }
    }

    return ['offenders' => $offenders, 'reached' => $reached];
}

it('names no booking stamp on a type a reader is shown a row of', function (): void {
    $files = ledgerDayRowTypes(ledgerDayBackendFiles());
    expect(count($files))->toBeGreaterThan(150);

    $walk = ledgerDayCarriersIn($files);
    $verdict = ledgerDayJudge($walk['booking'], LEDGER_DAY_CARRIER_PINS, 'booking-stamp fields');

    // Below what the row types of this tree actually declare, so a walk that
    // stops reading fails here instead of reporting a clean tree.
    expect($walk['counted'])->toBeGreaterThan(2000);

    expect($verdict['offenders'])->toBe([], implode("\n  ", [
        'A row type named the issuer\'s booking stamp. The day a reader is shown is',
        'posted_at -- what the ledger stores, what the cursor pages on, and a',
        'different day on every row of a card statement. Offenders:',
        ...$verdict['offenders'],
    ]));

    expect($verdict['reached'])->toBe(array_keys(LEDGER_DAY_CARRIER_PINS));
});

it('prints no booking stamp on a screen except the one that labels it', function (): void {
    $files = ledgerDayViewFiles();
    expect(count($files))->toBeGreaterThan(200);

    $walk = ledgerDayViewReadsIn($files);
    $verdict = ledgerDayJudge($walk['booking'], LEDGER_DAY_VIEW_PINS, 'booking-stamp reads');

    expect($walk['counted'])->toBeGreaterThan(15);

    expect($verdict['offenders'])->toBe([], implode("\n  ", [
        'A view printed the issuer\'s booking stamp. Unlabelled, it is a date the',
        'reader will not see again anywhere else in the app -- and on the import',
        'preview it was the date they were being asked to confirm. Offenders:',
        ...$verdict['offenders'],
    ]));

    expect($verdict['reached'])->toBe(array_keys(LEDGER_DAY_VIEW_PINS));
});

it('feeds no postedAt from the booking stamp', function (): void {
    $files = ledgerDayBackendFiles();
    $walk = ledgerDayFedFromBookingIn($files);
    $verdict = ledgerDayJudge($walk['fed'], LEDGER_DAY_SINGLE_DAY_SOURCE_PINS, 'single-day feeds');

    expect($walk['counted'])->toBeGreaterThan(30);

    expect($verdict['offenders'])->toBe([], implode("\n  ", [
        'A field called postedAt was fed from booked_at. Renaming the field and',
        'leaving the expression is how the six earlier surfaces would have been',
        '"fixed" without changing a single date on screen. Offenders:',
        ...$verdict['offenders'],
    ]));

    expect($verdict['reached'])->toBe(array_keys(LEDGER_DAY_SINGLE_DAY_SOURCE_PINS));
});

it('routes every day a row is built with through the one seam that names it', function (): void {
    expect(is_file(base_path(LEDGER_DAY_SEAM)))->toBeTrue(LEDGER_DAY_SEAM.' is what this rule is written in terms of');

    $walk = ledgerDayRowConstructionsIn(ledgerDayBackendFiles());

    expect($walk['counted'])->toBeGreaterThan(10);

    $offenders = [];
    foreach ($walk['astray'] as $sites) {
        $offenders = array_merge($offenders, $sites);
    }

    expect($offenders)->toBe([], implode("\n  ", [
        'A row was built with a day formatted somewhere other than LedgerDay::shown().',
        'Fmt::shortDate() will format any date it is handed; the seam is what says',
        'which date a row is allowed to be handed. Offenders:',
        ...$offenders,
    ]));
});

it('still holds each pinned file and each handover to what was written about it', function (): void {
    $claims = array_merge(
        LEDGER_DAY_CARRIER_PINS,
        LEDGER_DAY_VIEW_PINS,
        LEDGER_DAY_SINGLE_DAY_SOURCE_PINS,
        LEDGER_DAY_HANDOVERS,
    );
    $reproved = 0;

    foreach ($claims as $relative => $claim) {
        $source = (string) file_get_contents(base_path($relative));

        foreach ($claim['proves'] as $pattern) {
            expect($source)->toMatch($pattern, $relative.' no longer reads the way this entry describes it');
        }

        $reproved++;
    }

    // Counted rather than left implicit, so this states something even with an
    // empty handover table: nought of nought re-proved is the debt genuinely
    // being empty, and an assertion rather than a loop that quietly does nothing.
    expect($reproved)->toBe(count($claims), 'every pin and handover must be re-proved against the file it was written for');
    expect(LEDGER_DAY_HANDOVERS)->toBe([], 'a handover is a surface still printing the wrong day; convert it and delete the entry');
});

it('declares no field on a row type that nothing ever puts a value in', function (): void {
    $walk = ledgerDayNeverPopulatedIn(ledgerDayBackendFiles());

    // Below the number of named arguments the row constructions of this tree
    // actually pass, so a walk that stops reading fails here rather than
    // reporting that every declared field is populated.
    expect($walk['counted'])->toBeGreaterThan(200);

    $offenders = [];
    foreach ($walk['dead'] as $sites) {
        $offenders = array_merge($offenders, $sites);
    }

    expect($offenders)->toBe([], implode("\n  ", [
        'A row type declares a field every construction site passes null. On the',
        'preview row -- whose whole job is to show the reader what the commit will',
        'write -- that is a slot for a fact the commit does have, sitting empty. It',
        'is how PreviewRowDto::$categoryName came to exist: declared for a column',
        'nobody added. Populate it in the same change that declares it, or do not',
        'declare it. Offenders:',
        ...$offenders,
    ]));
});

it('sees a planted carrier, print, feed and empty slot, and leaves a converted one alone', function (): void {
    $plantedType = tempnam(sys_get_temp_dir(), 'ledger-day').'Row.php';
    file_put_contents($plantedType, <<<'PHP'
        <?php
        final class PlantedPreviewRow
        {
            public function __construct(
                public readonly string $bookedAt,
                public readonly int $amountMinor,
            ) {}
        }
        PHP);

    $cleanType = tempnam(sys_get_temp_dir(), 'ledger-day-clean').'Row.php';
    file_put_contents($cleanType, <<<'PHP'
        <?php
        final class PlantedLedgerRow
        {
            public function __construct(
                public readonly string $postedAt,
                public readonly int $amountMinor,
            ) {}
        }
        PHP);

    // One file so the walk that needs a declaration and the walk that needs a
    // construction site both see the pair they are written about.
    $plantedFeed = tempnam(sys_get_temp_dir(), 'ledger-day-feed').'Row.php';
    file_put_contents($plantedFeed, <<<'PHP'
        <?php
        final class PlantedFeedRow
        {
            public function __construct(
                public readonly string $postedAt,
                public readonly int $amountMinor,
                public readonly ?string $categoryName,
            ) {}
        }
        final class PlantedFeed
        {
            public function row(SourceTransactionDto $source): PlantedFeedRow
            {
                return new PlantedFeedRow(
                    postedAt: Fmt::shortDate($source->bookedAt),
                    amountMinor: $source->amountMinor,
                    categoryName: null,
                );
            }

            public function copy(PlantedFeedRow $existing): PlantedFeedRow
            {
                return new PlantedFeedRow(
                    postedAt: $existing->postedAt,
                    amountMinor: $existing->amountMinor,
                    categoryName: $existing->categoryName,
                );
            }
        }
        PHP);

    $plantedView = tempnam(sys_get_temp_dir(), 'ledger-day-view').'.blade.php';
    file_put_contents($plantedView, '<td>{{ $row->bookedAt }}</td><td>{{ $other->posted_at }}</td>');

    try {
        $carriers = ledgerDayCarriersIn(ledgerDayRowTypes([$plantedType, $cleanType]));
        $feeds = ledgerDayFedFromBookingIn([$plantedFeed]);
        $constructions = ledgerDayRowConstructionsIn([$plantedFeed]);
        $views = ledgerDayViewReadsIn([$plantedView]);
        $empty = ledgerDayNeverPopulatedIn([$plantedFeed]);
    } finally {
        @unlink($plantedType);
        @unlink($cleanType);
        @unlink($plantedFeed);
        @unlink($plantedView);
    }

    expect(ledgerDayByBasename($carriers['booking']))->toBe([basename($plantedType) => 1]);
    expect($carriers['counted'])->toBe(4);

    expect(ledgerDayByBasename($feeds['fed']))->toBe([basename($plantedFeed) => 1]);
    expect($feeds['counted'])->toBe(2);

    expect(ledgerDayByBasename($constructions['astray']))->toBe([basename($plantedFeed) => 1]);
    expect($constructions['counted'])->toBe(2);

    expect(ledgerDayByBasename($views['booking']))->toBe([basename($plantedView) => 1]);
    expect($views['counted'])->toBe(2);

    // The copy constructor beside the real one is the point: it passes the
    // field too, and it must not be what makes the field look populated.
    expect(array_keys($empty['dead']))->toBe(['PlantedFeedRow']);
    expect($empty['dead']['PlantedFeedRow'])->toHaveCount(1);
    expect($empty['dead']['PlantedFeedRow'][0])->toContain('PlantedFeedRow::$categoryName is null at all 1 site(s)');
    expect($empty['counted'])->toBe(6);
});
