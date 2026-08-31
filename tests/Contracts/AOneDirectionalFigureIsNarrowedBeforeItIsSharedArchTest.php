<?php

declare(strict_types=1);

use Tests\Contracts\Support\BackendSourceFiles;

// Spend is signed on purpose: a refund is counted beside the expense it
// reverses, with the sign it already carries, which is what makes it reduce
// spend. A category's period net can therefore come out below zero, and the
// dashboard's top-spending card had answered that three ways at once -- an
// empty state over three categorised expenses, Groceries at 1600% of a EUR5.00
// denominator drawn as a full bar under aria-valuenow="100", and a -EUR75.00
// category ranked as spending. A max() in the template was all that stood
// between the reader and each of them.
// @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-one-directional-figure-ranked-on-a-signed-sum

const DIRECTIONAL_SHARE_SEAM = 'Modules/Ledger/Public/Support/OutwardSpend.php';

const DIRECTIONAL_BAR_SEAM = 'Modules/Core/Resources/views/components/progress-bar.blade.php';

// A money figure divided by something that is not a constant is one money
// figure expressed as a fraction of another. Each entry says why that fraction
// is not a part of a one-directional whole; `proves` re-checks the reason
// against the file, so an exemption that stops being true fails here.
const DIRECTIONAL_SHARE_PINS = [
    'Modules/DriftAlerts/Database/Seeders/Demo/DemoDriftAlertsSeeder.php' => [
        'reason' => 'the evaluator own test re-spelled, so the demo cannot carry an alert the shipped detector would have refused, and it refuses the same sign flip before it divides',
        'proves' => '/\(\$priorMinor > 0\) !== \(\$latestMinor > 0\)/',
    ],
    'Modules/DriftAlerts/Internal/AmountMovement.php' => [
        'reason' => 'a ratio between two magnitudes of one series, refused outright when the baseline is nought or the pair flips sign, so neither end can be the wrong way round',
        'proves' => '/\(\$priorMinor > 0\) === \(\$latestMinor > 0\)/',
    ],
    'Modules/Goals/Public/Services/GoalProjectionService.php' => [
        'reason' => 'days rather than a share: a remaining balance over a daily rate is how long the goal takes, and the answer leaves here as a date',
        'proves' => '/\$daysToFinish = ceil\(/',
    ],
];

// A template that computes its own bar can clamp away the arithmetic behind it,
// which is exactly what hid all three symptoms. Each entry says why that bar is
// not a share of a one-directional whole.
const DIRECTIONAL_BAR_PINS = [
    'Modules/Counterparties/Resources/views/livewire/counterparty-index.blade.php' => [
        'reason' => 'a shape strip rather than a bar: every column is aria-hidden inside one role="img" that announces nothing per column, and a counterparty months run both ways, so the strip is scaled to its own largest magnitude and claims no direction',
        'proves' => '/\$sparkMax = max\(1,/',
    ],
    'Modules/Counterparties/Resources/views/livewire/profile-tabs/bank.blade.php' => [
        'reason' => 'a relative-size bar rather than a share: every row is drawn against the largest magnitude on the panel and the figure printed beside it is that same magnitude, so the bar and the figure agree and neither claims a direction',
        'proves' => '/\$maxBar = max\(1,/',
    ],
];

const DIRECTIONAL_MONEY_IDENTIFIER = '/[A-Za-z_]+_?[Mm]inor\b/';

/**
 * Every division whose numerator reads as a minor-unit money figure and whose
 * divisor is not a constant, as path => the expression text.
 *
 * A constant divisor is a unit conversion or a rate -- a hundredth, a quarter
 * of a year -- and nothing has to add back up to it. A bare `$minor` does not
 * read as money either: the identifier has to carry a word in front of it.
 *
 * @return list<array{path: string, line: int, expression: string}>
 */
function directionalShareSites(): array
{
    $sites = [];

    foreach (BackendSourceFiles::all() as $path) {
        $relative = str_replace(base_path().'/', '', $path);
        $tokens = BackendSourceFiles::codeTokens($path);
        $texts = array_map(
            static fn (array|string $token): string => is_array($token) ? $token[1] : $token,
            $tokens,
        );
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($texts[$i] !== '/') {
                continue;
            }

            $left = directionalShareOperand($texts, $i, forwards: false);
            if (preg_match(DIRECTIONAL_MONEY_IDENTIFIER, $left) !== 1) {
                continue;
            }

            $right = directionalShareOperand($texts, $i, forwards: true);
            if (preg_match('/^\s*[0-9_.]+\s*$/', $right) === 1) {
                continue;
            }

            $sites[] = [
                'path' => $relative,
                'line' => directionalShareLine($tokens, $i),
                'expression' => trim(preg_replace('/\s+/', ' ', $left.' / '.$right) ?? ''),
            ];
        }
    }

    return $sites;
}

// The balanced expression on one side of the operator, stopping at the
// punctuation that closes it or at the bracket it was written inside.
/**
 * @param  list<string>  $texts
 */
function directionalShareOperand(array $texts, int $index, bool $forwards): string
{
    $opening = $forwards ? ['(', '['] : [')', ']'];
    $closing = $forwards ? [')', ']'] : ['(', '['];
    $stops = $forwards ? [';', ',', '{', '}'] : [';', ',', '=', '?', ':', '{', '}', '=>'];

    $depth = 0;
    $operand = '';
    $step = $forwards ? 1 : -1;
    $count = count($texts);

    for ($i = $index + $step, $seen = 0; $i >= 0 && $i < $count && $seen < 40; $i += $step, $seen++) {
        $text = $texts[$i];

        if (in_array($text, $opening, true)) {
            $depth++;
        } elseif (in_array($text, $closing, true)) {
            $depth--;
            if ($depth < 0) {
                break;
            }
        } elseif ($depth === 0 && in_array($text, $stops, true)) {
            break;
        }

        $operand = $forwards ? $operand.$text : $text.$operand;
    }

    return $operand;
}

/**
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 */
function directionalShareLine(array $tokens, int $index): int
{
    for ($i = $index; $i >= 0; $i--) {
        if (is_array($tokens[$i])) {
            return $tokens[$i][2];
        }
    }

    return 0;
}

/**
 * Every value a template hands to a bar, as the file it sits in, the attribute
 * it feeds, and the expression itself.
 *
 * @return list<array{path: string, line: int, attribute: string, expression: string, source: string}>
 */
function directionalBarBindings(): array
{
    $patterns = [
        ':value' => '/<x-core::progress-bar\b[^>]*?:value="([^"]*)"/s',
        'aria-valuenow' => '/aria-valuenow="\{\{\s*(.*?)\s*\}\}"/s',
        'width' => '/style="[^"]*width:\s*\{\{\s*(.*?)\s*\}\}%/s',
        'height' => '/style="[^"]*height:\s*\{\{\s*(.*?)\s*\}\}%/s',
    ];

    $bindings = [];

    foreach (directionalBladeFiles() as $path) {
        $relative = str_replace(base_path().'/', '', $path);
        $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($path));

        foreach ($patterns as $attribute => $pattern) {
            $found = preg_match_all($pattern, $source, $matches, PREG_OFFSET_CAPTURE);

            // False is the engine giving up, not a file with no bars in it, and
            // read as the latter it is a silent green over whatever it skipped.
            expect($found)->not->toBeFalse($relative.' could not be read for '.$attribute);

            foreach ($matches[1] as $index => $captured) {
                $bindings[] = [
                    'path' => $relative,
                    'line' => substr_count(substr($source, 0, $matches[0][$index][1]), "\n") + 1,
                    'attribute' => $attribute,
                    'expression' => trim($captured[0]),
                    'source' => $source,
                ];
            }
        }
    }

    return $bindings;
}

/**
 * @return list<string>
 */
function directionalBladeFiles(): array
{
    $files = [];

    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('Modules'), RecursiveDirectoryIterator::SKIP_DOTS),
    ) as $file) {
        $path = $file->getPathname();

        if (! $file->isFile() || ! str_ends_with($path, '.blade.php') || str_contains($path, '/tests/')) {
            continue;
        }

        $files[] = $path;
    }

    sort($files);

    return $files;
}

it('cuts every share of a money figure in the one place that narrows it first', function (): void {
    $sites = directionalShareSites();

    // A walk that reads nothing finds no arithmetic and reports a clean tree.
    // The seam divides money by money itself, so it has to be in what the walk
    // found before any verdict here means anything.
    expect(array_column($sites, 'path'))->toContain(DIRECTIONAL_SHARE_SEAM);
    expect(count($sites))->toBeGreaterThan(3);

    $offenders = [];
    $pinned = [];

    foreach ($sites as $site) {
        if ($site['path'] === DIRECTIONAL_SHARE_SEAM) {
            continue;
        }

        if (array_key_exists($site['path'], DIRECTIONAL_SHARE_PINS)) {
            $pinned[$site['path']] = true;

            continue;
        }

        $offenders[] = $site['path'].':'.$site['line'].'  '.$site['expression'];
    }

    sort($offenders);

    expect($offenders)->toBe([], implode("\n  ", [
        'Spend, and every rollup built the way spend is, carries the sign of the',
        'rows it counts, so the whole a share is cut from can be nought or run the',
        'other way. OutwardSpend narrows the map to the part running outward, ranks',
        'and limits that part, sums that part for the whole, and refuses a share',
        'whose part or whole is not positive. Dividing here decides that again, and',
        'the last three answers were 1600%, a -EUR75.00 category ranked as spending,',
        'and an empty state over three categorised expenses. Offenders:',
        ...$offenders,
    ]));

    // A pin nobody reaches any more is a claim about the tree that stopped
    // being true, and it would otherwise sit here forever.
    expect(array_keys($pinned))->toBe(array_keys(DIRECTIONAL_SHARE_PINS));
});

it('lets no template work out the bar it is drawing', function (): void {
    $bindings = directionalBarBindings();

    // A walk that stops reading finds no bars at all, and the component every
    // bar is drawn by is the one file it cannot honestly miss.
    expect(count($bindings))->toBeGreaterThan(9);
    expect(count(array_unique(array_column($bindings, 'path'))))->toBeGreaterThan(4);
    expect(array_column($bindings, 'path'))->toContain(DIRECTIONAL_BAR_SEAM);

    $offenders = [];
    $pinned = [];

    foreach ($bindings as $binding) {
        if ($binding['path'] === DIRECTIONAL_BAR_SEAM) {
            continue;
        }

        $reason = directionalBarFault($binding);
        if ($reason === null) {
            continue;
        }

        if (array_key_exists($binding['path'], DIRECTIONAL_BAR_PINS)) {
            $pinned[$binding['path']] = true;

            continue;
        }

        $offenders[] = $binding['path'].':'.$binding['line'].' '.$binding['attribute'].'="'.$binding['expression'].'" — '.$reason;
    }

    sort($offenders);

    expect($offenders)->toBe([], implode("\n  ", [
        'A bar value reaches a template already decided: a row answers for its own',
        'bar, the way TopCategoryRow::barWidth() and GoalProgressRow::barWidth() do.',
        'Worked out in the view, the clamp that keeps it inside the track is also',
        'what hides whatever produced it — max(2, min(100, 1600)) drew a category',
        'worth EUR80.00 of EUR130.00 as a full bar and announced it as 100.',
        'Offenders:',
        ...$offenders,
    ]));

    expect(array_keys($pinned))->toBe(array_keys(DIRECTIONAL_BAR_PINS));
});

/**
 * Why this binding is the template deciding, or null when it is not.
 *
 * @param  array{path: string, line: int, attribute: string, expression: string, source: string}  $binding
 */
function directionalBarFault(array $binding): ?string
{
    $expression = $binding['expression'];

    if (preg_match('/^\$[A-Za-z_]\w*(?:->\w+(?:\(\))?|\[[^\]]*\])*$/', $expression) !== 1) {
        return 'the expression is worked out here rather than handed in';
    }

    $root = (string) preg_replace('/^(\$[A-Za-z_]\w*).*$/', '$1', $expression);
    $assignment = '/(?<![=!<>])'.preg_quote($root, '/').'\s*=(?![=>])/';

    if (preg_match($assignment, $binding['source']) === 1) {
        return $root.' is assigned in this same template';
    }

    return null;
}

it('still holds each pinned share and each pinned bar to the reason it was granted for', function (): void {
    foreach ([...DIRECTIONAL_SHARE_PINS, ...DIRECTIONAL_BAR_PINS] as $relative => $pin) {
        $source = (string) file_get_contents(base_path($relative));

        expect($source)->toMatch($pin['proves'], $relative.' no longer reads as "'.$pin['reason'].'"');
    }
});
