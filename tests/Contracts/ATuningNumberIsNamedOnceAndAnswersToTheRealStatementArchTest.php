<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Modules\Chains\Public\Support\StatementDueDate;
use Modules\Ingestion\Internal\Adapters\Ics\IcsPdfExtractionMap;

// Two numbers deciding when a card statement is due were measured against a
// fixture the repo itself labels "NOT anonymised from real user data", while a
// real statement sat beside it printing a deadline twenty-four days past the
// derived period rather than five. Both halves of that are guarded here: a
// tuning number is declared once, and the ones this failure was about are held
// against the real statement rather than against the synthesised one.
// @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-tolerance-calibrated-on-a-synthesised-fixture-while-a-real-one-disagrees

// The words a number carries when it is a tolerance, a window or a bound —
// the kind that is measured against something and can therefore stop matching
// what it was measured against. A CACHE_KEY or a BITMASK is not one of these.
const TUNING_NUMBER_VOCABULARY = '/(?:^|_)(DAYS|WINDOW|GRACE|TOLERANCE|THRESHOLD|RETENTION|LIMIT|MAX|MIN|SIZE|PAGE|CHUNK|TIMEOUT|TTL|SECONDS|MINUTES|HOURS|EPSILON|FLOOR|CEILING|MARGIN|BUFFER|LAG|PERIOD|STALE|EXPIRY|ATTEMPTS|RETRIES|BACKOFF|INTERVAL|AGE|COUNT|DEPTH|BYTES|PERCENT|OCCURRENCES|PORT)(?:$|_)/';

// The one real ICS statement the repo commits, and the two facts read off it.
// Its `.md` record states the provenance: "the `pdftotext -layout` extraction
// of a real Mijn ICS consumer-portal monthly statement".
const REAL_ICS_STATEMENT = 'Modules/Ingestion/tests/fixtures/ics/ics-sample-1.txt';

const REAL_ICS_PERIOD_END = '2026-02-12';

const REAL_ICS_PRINTED_DUE = '2026-03-08';

/** @var array<string, int> */
const REAL_ICS_MONTHS = [
    'jan' => 1, 'feb' => 2, 'mrt' => 3, 'apr' => 4,
    'mei' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8,
    'sep' => 9, 'okt' => 10, 'nov' => 11, 'dec' => 12,
];

// A name and a value declared in two classes at once, kept because the two are
// not one concept. Each entry re-proves itself: `sites` is re-counted against
// the walk, and every `proves` regex is re-run against the file it names, so a
// pin outlives only as long as the code it describes.
//
// A collision of the same NAME at DIFFERENT values is out of scope and
// deliberately so: MAX_PER_WINDOW is 10, 60 and 120 in three Sync limiters,
// each with a written reason, and a rule that flagged those would be answered
// by renaming rather than by merging.
/** @var array<string, array{reason: string, sites: int, proves: array<string, list<string>>}> */
const TUNING_NUMBER_PINS = [
    'MAX_ATTEMPTS = 5' => [
        'reason' => 'One bounds a guest route against enumeration; the other is how many booked-at nudges a manual entry may take before it gives up on a fingerprint collision. Merged, tuning the throttle would move a ledger write.',
        'sites' => 2,
        'proves' => [
            'Modules/Auth/Public/Actions/ResetPasswordAction.php' => [
                '/tooManyAttempts\(\$throttleKey, self::MAX_ATTEMPTS\)/',
            ],
            'Modules/CashBook/Internal/Actions/RecordManualTransaction.php' => [
                '/for \(\$attempt = 0; \$attempt < self::MAX_ATTEMPTS; \$attempt\+\+\)/',
            ],
        ],
    ],
    'MIN_OCCURRENCES = 2' => [
        'reason' => 'One counts sightings of an email sender, the other transactions forming a recurring series. EmailScan already single-sources its own within its module, which is the model; crossing into Recurring would tie a mailbox badge to a detector gate.',
        'sites' => 2,
        'proves' => [
            'Modules/EmailScan/Public/Services/DiscoveredSenderQuery.php' => [
                '/discovered_senders\\.occurrence_count/',
            ],
            'Modules/Recurring/Internal/Support/SeriesDetectionGate.php' => [
                '/public const int MIN_OCCURRENCES = 2;/',
            ],
        ],
    ],
    'PAGE_SIZE = 25' => [
        'reason' => 'How many rows two unrelated screens show. What they genuinely share is the +1 lookahead that tells a page there is another without a second COUNT, and that is a protocol rather than a number; 25 is a choice each screen may keep.',
        'sites' => 2,
        'proves' => [
            'Modules/Chains/Internal/Http/Livewire/ChainReviewQueue.php' => [
                '/self::PAGE_SIZE \+ 1/',
            ],
            'Modules/Notifications/Public/Services/NotificationQuery.php' => [
                '/self::PAGE_SIZE \+ 1/',
            ],
        ],
    ],
    'PAGE_SIZE = 26' => [
        'reason' => 'The same, on two more screens. Both add their own +1 on top of this figure, and AnomalyAlertQuery\'s own 26 already INCLUDES its lookahead — merging the three would double-count a row.',
        'sites' => 2,
        'proves' => [
            'Modules/DriftAlerts/Internal/Http/Livewire/DriftPage.php' => [
                '/\$anomalyLookahead = \$this->pageSize \+ 1;/',
            ],
            'Modules/Recurring/Internal/Http/Livewire/RecurringReviewPage.php' => [
                '/public const int PAGE_SIZE = 26;/',
                '/\\$limit \\+ 1/',
            ],
        ],
    ],
    'TIMEOUT_SECONDS = 10' => [
        'reason' => 'Three HTTP clients against three unrelated endpoints — the update manifest CDN, Google token revocation and the relay. The precedent for a real timing seam is ProtocolTimings, which is scoped to ONE protocol rather than to all HTTP.',
        'sites' => 3,
        'proves' => [
            'Modules/Core/Internal/AutoUpdate/HttpPublisherManifestFetcher.php' => ['/timeout\(self::TIMEOUT_SECONDS\)/'],
            'Modules/EmailScan/Internal/OAuth/GoogleTokenRevoker.php' => ['/timeout\(self::TIMEOUT_SECONDS\)/'],
            'Modules/Sync/Internal/Transport/Relay/RelayClient.php' => ['/self::TIMEOUT_SECONDS/'],
        ],
    ],
    'TIMEOUT_SECONDS = 5' => [
        'reason' => 'One bounds an external `--version` probe on a developer machine, the other a read-only SQLite busy timeout. Different subsystems, different units of patience, and one is a float because the API it feeds takes one.',
        'sites' => 2,
        'proves' => [
            'Modules/Core/Internal/Console/Probes/ExternalToolVersionProbe.php' => [
                '/private const float TIMEOUT_SECONDS = 5\.0;/',
                '/\\$process->setTimeout\\(self::TIMEOUT_SECONDS\\)/',
            ],
            'Modules/DevMode/Internal/Sql/ReadOnlySqliteConnection.php' => [
                '/public const int TIMEOUT_SECONDS = 5;/',
                '/\\$this->isolated->run\\(/',
            ],
        ],
    ],
];

// Deliberately empty. An entry would name a duplicated tuning number another
// owner held when this rule was written, with a count that stops matching the
// moment they convert it — a debt that empties itself, spelled differently
// from a pin so it cannot decay into one.
/** @var array<string, array{owner: string, sites: int, proves: array<string, list<string>>}> */
const TUNING_NUMBER_HANDOVERS = [];

/**
 * @return list<string> every backend PHP file a tuning number can hide in;
 *                      tests and migrations describe fixtures and schema
 *                      rather than the rules this walks
 */
function tuningNumberFiles(): array
{
    $paths = [];

    foreach (['Modules', 'app'] as $root) {
        $directory = base_path($root);
        if (! is_dir($directory)) {
            continue;
        }

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        ) as $file) {
            $path = $file->getPathname();
            if (! $file->isFile() || ! str_ends_with($path, '.php')) {
                continue;
            }
            if (str_contains($path, '/tests/') || str_contains($path, '/Database/Migrations/')) {
                continue;
            }
            $paths[] = $path;
        }
    }

    sort($paths);

    return array_values(array_unique($paths));
}

/**
 * Every tuning number, keyed by name and value so that one concept spelled in
 * two classes shows up as one key with two files. `counted` covers every
 * declaration the walk read, colliding or not, so a walk that stops reading
 * cannot pass for a tree that declares nothing twice.
 *
 * @param  list<string>  $paths
 * @return array{collisions: array<string, list<string>>, counted: int}
 */
function tuningNumbersIn(array $paths): array
{
    $byKey = [];
    $counted = 0;

    foreach ($paths as $path) {
        $source = (string) file_get_contents($path);
        if (preg_match_all('/const\s+(?:int|float|string|array|bool)?\s*([A-Z][A-Z0-9_]*)\s*=\s*([^;]+);/', $source, $matches, PREG_SET_ORDER) === 0) {
            continue;
        }

        $relative = str_replace(base_path().'/', '', $path);

        foreach ($matches as $match) {
            $name = $match[1];
            $value = trim($match[2]);
            if (preg_match(TUNING_NUMBER_VOCABULARY, $name) !== 1) {
                continue;
            }
            // Only a bare literal. A constant defined as another constant is
            // already single-sourced, which is the outcome this rule wants.
            if (preg_match('/^-?[0-9][0-9_]*(?:\.[0-9]+)?$/', $value) !== 1) {
                continue;
            }

            $counted++;
            $key = $name.' = '.(string) (float) str_replace('_', '', $value);
            $byKey[$key][$relative] = true;
        }
    }

    $collisions = [];
    foreach ($byKey as $key => $files) {
        if (count($files) > 1) {
            $collisions[$key] = array_keys($files);
        }
    }

    ksort($collisions);

    return ['collisions' => $collisions, 'counted' => $counted];
}

/** @return array{periodStart: string, periodEnd: string, printedDue: string} read off the committed statement itself */
function realIcsStatementFacts(): array
{
    $text = (string) file_get_contents(base_path(REAL_ICS_STATEMENT));

    // The header date carries the year the undated transaction rows belong to,
    // and a row naming a month later than the header's is the tail of the
    // previous year — the same roll the adapter applies.
    expect(preg_match('/(\d{1,2})\s+februari\s+(\d{4})/', $text, $header))->toBe(1);
    $headerYear = (int) $header[2];
    $headerMonth = 2;

    $days = [];
    foreach (preg_split('/\n/', $text) ?: [] as $line) {
        $trimmed = trim($line);
        if (preg_match('/\s(?:Af|Bij)$/', $trimmed) !== 1) {
            continue;
        }
        if (preg_match('/^(\d{1,2})\s+([a-z]{3})\.\s/i', $trimmed, $row) !== 1) {
            continue;
        }
        $month = REAL_ICS_MONTHS[strtolower($row[2])] ?? null;
        if ($month === null) {
            continue;
        }
        $year = $month > $headerMonth ? $headerYear - 1 : $headerYear;
        $days[] = sprintf('%04d-%02d-%02d', $year, $month, (int) $row[1]);
    }

    sort($days);

    // Read through the same named anchor the adapter reads it through, so a
    // parser that stops recognising the paragraph fails here too.
    $pattern = '/'.preg_quote(IcsPdfExtractionMap::MIN_DUE_PARAGRAPH, '/')
        .'[^\n]*?(\d{1,2})\s+maart\s+(\d{4})/u';
    expect(preg_match($pattern, $text, $due))->toBe(1);

    return [
        'periodStart' => $days[0] ?? '',
        'periodEnd' => $days[count($days) - 1] ?? '',
        'printedDue' => sprintf('%04d-03-%02d', (int) $due[2], (int) $due[1]),
    ];
}

it('declares every tuning number once', function (): void {
    $files = tuningNumberFiles();
    expect($files)->not->toBeEmpty();

    $walk = tuningNumbersIn($files);
    $offenders = [];
    $reached = [];
    $handed = [];

    foreach ($walk['collisions'] as $key => $sites) {
        $pin = TUNING_NUMBER_PINS[$key] ?? null;
        if ($pin !== null) {
            $reached[$key] = true;

            if (count($sites) !== $pin['sites']) {
                $offenders[] = $key.' is pinned at '.$pin['sites'].' declarations and now has '.count($sites);
            }

            continue;
        }

        $handover = TUNING_NUMBER_HANDOVERS[$key] ?? null;
        if ($handover === null) {
            $offenders[] = $key.' is declared in '.count($sites).' classes: '.implode(', ', $sites);

            continue;
        }

        $handed[$key] = true;

        if (count($sites) !== $handover['sites']) {
            $offenders[] = $key.' is handed to '.$handover['owner'].' at '.$handover['sites']
                .' unconverted declarations and now has '.count($sites).' — convert them and delete the entry';
        }
    }

    // Below what this tree actually declares, so a walk that reads nothing
    // fails here instead of reporting a tree with no duplicates in it.
    expect($walk['counted'])->toBeGreaterThan(200);

    expect($offenders)->toBe([], implode("\n  ", [
        'A tolerance, window or bound declared in two classes is one rule with two',
        'copies, and the copies drift. Name it once in a seam both consumers share —',
        'RetentionWindow, WeekStart, SettlementTolerance and StatementDueDate are the',
        'shape. Where the two really are different concepts, pin the collision with',
        'the evidence rather than merging them. Offenders:',
        ...$offenders,
    ]));

    // A pin nobody reaches is a claim about the tree that stopped being true.
    expect(array_keys($reached))->toBe(array_keys(TUNING_NUMBER_PINS));

    expect(array_keys($handed))->toBe(
        array_keys(TUNING_NUMBER_HANDOVERS),
        'a handover nobody reaches has been converted by its owner — delete the entry',
    );
});

it('still holds each pinned and handed-over collision to what was written about it', function (): void {
    $claims = array_merge(TUNING_NUMBER_PINS, TUNING_NUMBER_HANDOVERS);
    $reproved = 0;

    foreach ($claims as $key => $claim) {
        foreach ($claim['proves'] as $relative => $patterns) {
            $source = (string) file_get_contents(base_path($relative));

            foreach ($patterns as $pattern) {
                expect($source)->toMatch($pattern, $relative.' no longer reads the way the entry for '.$key.' describes it');
                $reproved++;
            }
        }
    }

    // Counted rather than left implicit, so an entry whose `proves` list was
    // emptied cannot pass as an entry that was re-proved.
    expect($reproved)->toBeGreaterThanOrEqual(count($claims) * 2);
});

it('sees two classes declaring one number, and leaves a derived constant and a lone one alone', function (): void {
    $duplicateA = tempnam(sys_get_temp_dir(), 'tuning-a').'.php';
    file_put_contents($duplicateA, <<<'PHP'
        <?php
        final class PlantedWindowA
        {
            private const int SETTLE_WINDOW_DAYS = 10;
        }
        PHP);

    $duplicateB = tempnam(sys_get_temp_dir(), 'tuning-b').'.php';
    file_put_contents($duplicateB, <<<'PHP'
        <?php
        final class PlantedWindowB
        {
            private const int SETTLE_WINDOW_DAYS = 10;

            private const int SETTLE_GRACE_DAYS = 5;

            private const string CACHE_KEY_PREFIX = 'planted';
        }
        PHP);

    $derived = tempnam(sys_get_temp_dir(), 'tuning-c').'.php';
    file_put_contents($derived, <<<'PHP'
        <?php
        final class PlantedDerivedWindow
        {
            private const int SETTLE_WINDOW_DAYS = StatementDueDate::MATCH_WINDOW_DAYS;
        }
        PHP);

    try {
        $walk = tuningNumbersIn([$duplicateA, $duplicateB, $derived]);
    } finally {
        @unlink($duplicateA);
        @unlink($duplicateB);
        @unlink($derived);
    }

    expect(array_keys($walk['collisions']))->toBe(['SETTLE_WINDOW_DAYS = 10']);
    expect($walk['collisions']['SETTLE_WINDOW_DAYS = 10'])->toHaveCount(2);

    // Three numeric tuning declarations across the three files: two colliding
    // windows and one lone grace. The CACHE_KEY_PREFIX is not a tuning number
    // and the derived window is already single-sourced, so neither is counted.
    expect($walk['counted'])->toBe(3);
});

it('reads the committed real statement, not the synthesised one it was tuned against', function (): void {
    $facts = realIcsStatementFacts();

    expect($facts['periodStart'])->toBe('2026-01-15');
    expect($facts['periodEnd'])->toBe(REAL_ICS_PERIOD_END);
    expect($facts['printedDue'])->toBe(REAL_ICS_PRINTED_DUE);

    $printedLag = (int) CarbonImmutable::parse($facts['periodEnd'])
        ->diffInDays(CarbonImmutable::parse($facts['printedDue']));

    expect($printedLag)->toBe(24);

    // The whole of the shipped failure in one inequality: the grace cannot
    // reach this statement's own deadline even with the matching window spent
    // on it, so a due day derived from the constant matches no payment made on
    // the day the issuer asked for.
    expect(abs($printedLag - StatementDueDate::GRACE_DAYS))
        ->toBeGreaterThan(
            StatementDueDate::MATCH_WINDOW_DAYS,
            'the grace now reaches the real statement by arithmetic — which is a constant tuned to one issuer, not a rule',
        );
});

it('dates the real statement by the day it printed, and only falls back where nothing was printed', function (): void {
    $facts = realIcsStatementFacts();

    expect(StatementDueDate::of($facts['printedDue'], $facts['periodEnd'])->toDateString())
        ->toBe(REAL_ICS_PRINTED_DUE);

    expect(StatementDueDate::of(null, $facts['periodEnd'])->toDateString())
        ->toBe(
            CarbonImmutable::parse($facts['periodEnd'])->addDays(StatementDueDate::GRACE_DAYS)->toDateString(),
        );

    $paidOnTheDayAsked = CarbonImmutable::parse(REAL_ICS_PRINTED_DUE);
    [$printedStart, $printedEnd] = StatementDueDate::printedDueWindow($paidOnTheDayAsked);
    [$derivedStart, $derivedEnd] = StatementDueDate::derivedDueWindow($paidOnTheDayAsked);

    expect(REAL_ICS_PRINTED_DUE.' 00:00:00')->toBeGreaterThanOrEqual($printedStart);
    expect(REAL_ICS_PRINTED_DUE.' 00:00:00')->toBeLessThanOrEqual($printedEnd);

    // And the window that would have been used instead: the real period_end
    // sits outside it, which is the read that returned zero statements.
    expect(REAL_ICS_PERIOD_END.' 00:00:00')->toBeLessThan($derivedStart);
    expect($derivedEnd)->toBeGreaterThan($derivedStart);
});

it('keeps the day rule and the matching window in one place', function (): void {
    $offenders = [];

    foreach (tuningNumberFiles() as $path) {
        $relative = str_replace(base_path().'/', '', $path);
        if ($relative === 'Modules/Chains/Public/Support/StatementDueDate.php') {
            continue;
        }

        $source = (string) file_get_contents($path);
        if (preg_match('/const\s+(?:int|float)?\s*[A-Z0-9_]*(?:GRACE_DAYS|DUE_GRACE|PERIOD_WINDOW_DAYS|MATCH_WINDOW_DAYS)[A-Z0-9_]*\s*=\s*[0-9]/', $source) === 1) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([], implode("\n  ", [
        'The statement due-date grace and the settlement matching window are',
        'StatementDueDate\'s to state. A second declaration is the shape this file',
        'exists to stop: STATEMENT_DUE_GRACE_DAYS lived on a Public query and',
        'PERIOD_WINDOW_DAYS on an Internal resolver, so neither consumer could read',
        'the other without reaching across a module. Offenders:',
        ...$offenders,
    ]));
});
