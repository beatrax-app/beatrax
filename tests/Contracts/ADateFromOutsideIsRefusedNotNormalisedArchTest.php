<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Modules\Core\Public\Support\SafeDate;
use Tests\Contracts\Support\BackendSourceFiles;

// Every date parser PHP has rolls an out-of-range component FORWARD instead of
// refusing it, and reads far more than a date besides: '2027-02-29' became 1
// March, '2026-1-5' parsed, '2026' and 'tomorrow' became today and tomorrow,
// and a bare string bound against a DATE column was compared lexically. None of
// those failed. They each produced a different, perfectly storable answer under
// a screen still printing what the reader typed.
//
// So the rule is a shape check, not a parse: SafeDate::dayOrNull() formats the
// result back and compares it to what arrived. Its sibling
// SafeDate::normalisedDayOrNull() still normalises, and is named for that —
// it is the right reader for a machine-emitted string with no Y-m-d shape to
// check, and the wrong one for anything a reader or a peer supplies.
// @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-date-from-outside-normalised-instead-of-refused

// The refusal each reader-typed date field is held to. Keyed by the property
// path the picker binds, because that is the fact in the tree: add a date field
// anywhere and this goes red until the file that refuses it is written down.
// `sites` is re-counted against the walk and every `refusals` pattern is re-run
// against the file, so a refusal that is deleted or reworded fails here.
/** @var array<string, array{reason: string, sites: int, refusals: array<string, list<string>>}> */
const SUPPLIED_DATE_FIELDS = [
    'asOfInput' => [
        'reason' => 'The opening-balance as-of date, checked in the action so a caller that is not the editor is checked too.',
        'sites' => 1,
        'refusals' => [
            'Modules/Forecasting/Public/Actions/SetAccountOpeningBalance.php' => [
                '/\$asOf = SafeDate::dayOrNull\(\$openingBalanceAsOfDate\);/',
            ],
        ],
    ],
    'conditions.*.value' => [
        'reason' => 'A rule condition bound. Stored and synced, so it raises rather than answering false: ReapplyRulesJob counts the row as errored and the operator is told.',
        'sites' => 1,
        'refusals' => [
            'Modules/Categorization/Internal/Services/RuleEngine.php' => [
                '/private static function conditionDay\(string \$value\): CarbonImmutable/',
                '/SafeDate::dayOrNull\(\$value\)\s*\n\s*\?\? throw new InvalidFormatException/',
            ],
        ],
    ],
    'conditions.*.value2' => [
        'reason' => 'The upper bound of a `between` condition, judged by the same reading as the lower one.',
        'sites' => 1,
        'refusals' => [
            'Modules/Categorization/Internal/Services/RuleEngine.php' => [
                '/self::withinInclusiveRange\(\$t, \$v, self::conditionDay\(\$value2\)\)/',
            ],
        ],
    ],
    'customFrom' => [
        'reason' => 'A report period end, refused loudly through InvalidReportPeriod::malformed so the builder can name which half is wrong.',
        'sites' => 1,
        'refusals' => [
            'Modules/Reports/Internal/Aggregation/PeriodPresetResolver.php' => [
                '/return \\$value === null \\? null : SafeDate::dayOrNull\\(\\$value\\);/',
                '/InvalidReportPeriod::malformed\(.customFrom., \$customFrom\)/',
            ],
        ],
    ],
    'customTo' => [
        'reason' => 'The other half of the report period, resolved by the same call.',
        'sites' => 1,
        'refusals' => [
            'Modules/Reports/Internal/Aggregation/PeriodPresetResolver.php' => [
                '/InvalidReportPeriod::malformed\(.customTo., \$customTo\)/',
            ],
        ],
    ],
    'date' => [
        'reason' => 'The cash-book entry date. A day February does not have would have booked the entry in March under a form still reading the 29th.',
        'sites' => 1,
        'refusals' => [
            'Modules/CashBook/Internal/Http/Livewire/CashBookPage.php' => [
                '/\$date = SafeDate::dayOrNull\(\$this->date\);/',
            ],
        ],
    ],
    'editedDate' => [
        'reason' => 'The starting-balance the wizard writes. strtotime() read this and accepted "yesterday" and "last friday" as well as an impossible day.',
        'sites' => 2,
        'refusals' => [
            'Modules/Onboarding/Internal/Services/StartingBalanceRule.php' => [
                '/\$day = \$date === null \? null : SafeDate::dayOrNull\(\$date\);/',
                '/\$day === null => StartingBalanceRejection::DateUnreadable,/',
            ],
            'Modules/Onboarding/Internal/Http/Livewire/StartingBalanceCard.php' => [
                '/SafeDate::dayOrNull\(\$date\) !== null \? \$date : null/',
            ],
        ],
    ],
    'filterAfter' => [
        'reason' => 'Also #[Url] as ?after=. Coerced on the property, not on the way to the query, so the chip and the active-filter count see the same filter the rows do.',
        'sites' => 2,
        'refusals' => [
            'Modules/Ledger/Internal/Http/Livewire/TransactionsList.php' => [
                '/\$this->filterAfter = self::supportedDay\(\$this->filterAfter\);/',
                "/return SafeDate::dayOrNull\\(\\\$raw\\) === null \\? '' : trim\\(\\\$raw\\);/",
            ],
            'Modules/Search/Public/Services/SearchQuery.php' => [
                '/private static function boundDay\(string \$raw, bool \$endOfMonth\): \?string/',
                '/\$after = self::boundDay\(\$filters->after, endOfMonth: false\);/',
            ],
        ],
    ],
    'filterBefore' => [
        'reason' => 'The mirror of ?after=, and the bound that returned an empty list with no message: ?before=2026 compared lexically against every 2026 row.',
        'sites' => 2,
        'refusals' => [
            'Modules/Ledger/Internal/Http/Livewire/TransactionsList.php' => [
                '/\$this->filterBefore = self::supportedDay\(\$this->filterBefore\);/',
            ],
            'Modules/Search/Public/Services/SearchQuery.php' => [
                '/\$before = self::boundDay\(\$filters->before, endOfMonth: true\);/',
                '/\$query->whereRaw\(self::MATCH_NOTHING\);/',
            ],
        ],
    ],
    'form.date' => [
        'reason' => 'A one-off what-if. Refused in the DTO because that is the only point the sidebar form and a row arriving from a peer both pass through.',
        'sites' => 1,
        'refusals' => [
            'Modules/Forecasting/Public/Dto/ScenarioMutationPayload/AddOneOffPayload.php' => [
                "/\\\$this->date = self::assertCalendarDay\\(\\\$date, 'date'\\);/",
            ],
            'Modules/Forecasting/Public/Dto/ScenarioMutationPayload/ScenarioMutationPayload.php' => [
                '/protected static function assertCalendarDay\(string \$raw, string \$field\): string/',
                '/if \(SafeDate::dayOrNull\(\$raw\) === null\) \{/',
            ],
        ],
    ],
    'form.newNextDate' => [
        'reason' => 'The date a series is shifted to, refused in its own DTO for the same reason.',
        'sites' => 1,
        'refusals' => [
            'Modules/Forecasting/Public/Dto/ScenarioMutationPayload/ShiftSeriesDatePayload.php' => [
                "/\\\$this->newNextDate = self::assertCalendarDay\\(\\\$newNextDate, 'newNextDate'\\);/",
            ],
        ],
    ],
    'form.startDate' => [
        'reason' => 'The first occurrence of a recurring what-if, refused in its own DTO for the same reason.',
        'sites' => 1,
        'refusals' => [
            'Modules/Forecasting/Public/Dto/ScenarioMutationPayload/AddRecurringPayload.php' => [
                "/\\\$this->startDate = self::assertCalendarDay\\(\\\$startDate, 'startDate'\\);/",
            ],
        ],
    ],
    'statementDate' => [
        'reason' => 'The reconcile window. The screen difference, the disabled button and completeReconcile() all read this one value, so a normalised one moved all three together.',
        'sites' => 1,
        'refusals' => [
            'Modules/Ledger/Internal/Http/Livewire/ReconcilePage.php' => [
                '/\$date = SafeDate::dayOrNull\(\$this->statementDate\);/',
                '/\$statementDate = SafeDate::dayOrNull\(\$this->statementDate\);/',
            ],
        ],
    ],
    'targetDate' => [
        'reason' => 'The goal target date, which is where this repo first wrote the rule down; the writer now asks SafeDate for it rather than spelling it a second time.',
        'sites' => 3,
        'refusals' => [
            'Modules/Goals/Public/Services/GoalWriter.php' => [
                '/if \(SafeDate::dayOrNull\(\$targetDate\) === null\) \{/',
                '/throw new InvalidGoalTargetDateException/',
            ],
        ],
    ],
];

// The op-log applier used to be here, holding the last debt in this file: a
// wire-supplied value written straight into a DATE column, refused only by the
// model cast on the way back OUT. Sync paid it — SuppliedDateGate reads the day
// before either write path takes it and the op is quarantined as
// ImpossibleDate, the way a cross-user reference already was. What replaces the
// entry is the assertion below, because a debt that leaves without one is just
// a defect nobody is watching for any more.

// strtotime() reads relative English, so it is never the reader of a supplied
// date. A Retry-After header is the one thing in this tree it IS the reader of:
// RFC 9110 allows an HTTP-date there in three legal spellings, and what comes
// back is a delay rather than a day.
/** @var array<string, string> */
const SUPPLIED_DATE_STRTOTIME_PINS = [
    'Modules/EmailScan/Internal/Clients/GraphErrorMapper.php' => 'A Retry-After HTTP-date, read as a delay in seconds rather than as a calendar day.',
];

/** @return list<string> every Blade template in Modules and resources */
function suppliedDateBladeFiles(): array
{
    $paths = [];

    foreach ([base_path('Modules'), base_path('resources')] as $root) {
        if (! is_dir($root)) {
            continue;
        }

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        ) as $file) {
            $path = $file->getPathname();
            if ($file->isFile() && str_ends_with($path, '.blade.php')) {
                $paths[] = $path;
            }
        }
    }

    sort($paths);

    return array_values(array_unique($paths));
}

/**
 * Every `<x-core::date-input>` in those templates and the property path it
 * binds. Blade comments are stripped first: the goals modal explains this very
 * component INSIDE a `{{-- --}}` block, and reading that as a binding invented
 * a field that does not exist. `elements` counts the tags rather than the
 * bindings, so a walk that reads nothing cannot pass for a tree with no dates.
 *
 * @param  list<string>  $paths
 * @return array{bindings: array<string, list<string>>, elements: int}
 */
function suppliedDateBindings(array $paths): array
{
    $bindings = [];
    $elements = 0;

    foreach ($paths as $path) {
        $source = (string) file_get_contents($path);
        $source = (string) preg_replace_callback(
            '/\{\{--.*?--\}\}/s',
            static fn (array $m): string => str_repeat("\n", substr_count($m[0], "\n")),
            $source,
        );

        $relative = str_replace(base_path().'/', '', $path);

        // Attribute values are skipped over as a unit, because a Blade
        // expression inside one may itself hold a `>`.
        $tags = PatternScan::allWithOffsets('/<x-core::date-input\b(?:[^>"]|"[^"]*")*>/', $source);

        foreach ($tags[0] as [$tag, $offset]) {
            $elements++;
            $line = substr_count(substr($source, 0, $offset), "\n") + 1;

            $models = PatternScan::all('/wire:model[.\w]*\s*=\s*"([^"]+)"/', $tag);

            foreach ($models[1] as $model) {
                $field = trim((string) preg_replace('/\{\{.*?\}\}/', '*', $model));
                $bindings[$field][] = $relative.':'.$line;
            }
        }
    }

    ksort($bindings);

    return ['bindings' => $bindings, 'elements' => $elements];
}

/**
 * Direct createFromFormat() calls reading a whole-day format, which is the
 * round-trip SafeDate::dayOrNull() owns. Five files held a copy of it before
 * this rule; a sixth is how the answers start disagreeing again.
 *
 * @param  list<string>  $paths
 * @return array{respellings: list<string>, counted: int}
 */
function suppliedDateRespellings(array $paths): array
{
    $respellings = [];
    $counted = 0;

    foreach ($paths as $path) {
        if (str_ends_with($path, 'Public/Support/SafeDate.php')) {
            continue;
        }

        $tokens = BackendSourceFiles::codeTokens($path);
        $relative = str_replace(base_path().'/', '', $path);

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== 'createFromFormat') {
                continue;
            }

            $counted++;
            $arguments = BackendSourceFiles::callArguments($tokens, $index);

            if (preg_match("/^\\s*'!?Y-m-d'\\s*,|DAY_FORMAT|DATE_FORMAT/", $arguments) !== 1) {
                continue;
            }

            $respellings[] = $relative.':'.$token[2].' reads a whole day with createFromFormat('.trim($arguments).')';
        }
    }

    sort($respellings);

    return ['respellings' => $respellings, 'counted' => $counted];
}

/**
 * @param  list<string>  $paths
 * @return list<string>
 */
function suppliedDateStrtotimeCalls(array $paths): array
{
    $calls = [];

    foreach ($paths as $path) {
        $tokens = BackendSourceFiles::codeTokens($path);
        $relative = str_replace(base_path().'/', '', $path);

        foreach ($tokens as $index => $token) {
            if (is_array($token) && $token[0] === T_STRING && $token[1] === 'strtotime') {
                $calls[] = $relative;
            }
        }
    }

    sort($calls);

    return array_values(array_unique($calls));
}

it('names the file that refuses every date a reader can type into a picker', function (): void {
    $blades = suppliedDateBladeFiles();
    $walk = suppliedDateBindings($blades);

    $offenders = [];
    $reached = [];

    foreach ($walk['bindings'] as $field => $sites) {
        $pin = SUPPLIED_DATE_FIELDS[$field] ?? null;

        if ($pin === null) {
            $offenders[] = $field.' is bound at '.implode(', ', $sites).' and no file is named as refusing it';

            continue;
        }

        $reached[$field] = true;

        if (count($sites) !== $pin['sites']) {
            $offenders[] = $field.' is pinned at '.$pin['sites'].' picker(s) and is now bound at '
                .count($sites).': '.implode(', ', $sites);
        }
    }

    // Both below what the tree actually holds, so a stripped comment eating the
    // markup, a renamed component or a broken regex fails here rather than
    // reporting a tree with no reader-supplied dates in it.
    expect(count($blades))->toBeGreaterThan(200);
    expect($walk['elements'])->toBeGreaterThan(15);

    expect($offenders)->toBe([], implode("\n  ", [
        'A date a reader can put into a picker is a date the client chooses, and',
        'every parser PHP has answers a wrong date rather than refusing a bad one:',
        "'2027-02-29' becomes 1 March and 'tomorrow' becomes a boundary that moves.",
        'Route it through SafeDate::dayOrNull(), then name the file that does so',
        'here — with a pattern re-run against that file, so the claim stays true.',
        'Offenders:',
        ...$offenders,
    ]));

    expect(array_keys($reached))->toBe(
        array_keys(SUPPLIED_DATE_FIELDS),
        'a pinned field nobody binds has been renamed or removed — delete the entry',
    );
});

it('still holds each pinned field to the refusal that was written for it', function (): void {
    $offenders = [];
    $reproved = 0;

    foreach (SUPPLIED_DATE_FIELDS as $field => $pin) {
        expect($pin['reason'])->not->toBe('');

        foreach ($pin['refusals'] as $relative => $patterns) {
            $path = base_path($relative);

            if (! is_file($path)) {
                $offenders[] = $field.' names '.$relative.', which is gone';

                continue;
            }

            $source = (string) file_get_contents($path);

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $source) !== 1) {
                    $offenders[] = $field.': '.$relative.' no longer reads the way this entry describes it ('.$pattern.')';
                }

                $reproved++;
            }
        }
    }

    expect($offenders)->toBe([], implode("\n  ", [
        'A refusal named here is no longer in the file it was named in. Either the',
        'check moved and this entry has to move with it, or it was deleted and the',
        'field is back to taking whatever arrives.',
        ...$offenders,
    ]));

    // Counted rather than left implicit: a table whose patterns all vanished
    // would otherwise pass by asserting nothing at all.
    expect($reproved)->toBeGreaterThan(20);
});

it('spells the whole-day round-trip once, in SafeDate, and reads no date with strtotime', function (): void {
    $files = BackendSourceFiles::all();
    expect($files)->not->toBeEmpty();

    $walk = suppliedDateRespellings($files);

    expect($walk['respellings'])->toBe([], implode("\n  ", [
        'createFromFormat() rolls an out-of-range component forward and reports it',
        'only as a warning, so reading a whole day with it needs a round-trip back',
        'to the raw string. Five files each held their own copy of that round-trip,',
        'and they did not all agree. SafeDate::dayOrNull() is the copy that stays.',
        ...$walk['respellings'],
    ]));

    // createFromFormat is still reached for the shapes an importer names, so a
    // walk that stopped seeing calls entirely is a walk that stopped reading.
    expect($walk['counted'])->toBeGreaterThan(0);

    $strtotime = suppliedDateStrtotimeCalls($files);
    $unpinned = array_values(array_diff($strtotime, array_keys(SUPPLIED_DATE_STRTOTIME_PINS)));

    expect($unpinned)->toBe([], implode("\n  ", [
        'strtotime() reads relative English — "yesterday", "last friday", "+1 week"',
        '— and rolls an impossible day forward besides, so it is never the reader',
        'of a date somebody supplied. It read the starting-balance date, and every',
        'one of those answered true.',
        ...$unpinned,
    ]));

    expect(array_values(array_intersect(array_keys(SUPPLIED_DATE_STRTOTIME_PINS), $strtotime)))
        ->toBe(array_keys(SUPPLIED_DATE_STRTOTIME_PINS), 'a pinned strtotime() site no longer calls it — delete the entry');
});

it('reads a supplied day before the op-log applier writes it, on both paths', function (): void {
    $applier = (string) file_get_contents(base_path('Modules/Sync/Internal/Merge/OpLogEntryApplier.php'));
    $gate = base_path('Modules/Sync/Internal/Merge/SuppliedDateGate.php');

    expect(is_file($gate))->toBeTrue('SuppliedDateGate is gone — the applier writes supplied days unread again.');
    // A refusing reader, never normalisedDayOrNull: this is the supplying
    // side, so a value that is not exactly a day is refused rather than rolled
    // into one. dayIgnoringTimeOrNull sets aside only the time the writer's own
    // cast stamps on, then puts the day itself through dayOrNull.
    $gateSource = (string) file_get_contents($gate);
    expect($gateSource)->toContain('SafeDate::dayIgnoringTimeOrNull')
        ->and($gateSource)->not->toContain('SafeDate::normalisedDayOrNull');

    // And that reader must stay a refusing one: the name promises it discards
    // a time, not that it rolls a day nobody meant into one that exists.
    $safeDate = (string) file_get_contents(base_path('Modules/Core/Public/Support/SafeDate.php'));
    $body = substr($safeDate, (int) strpos($safeDate, 'function dayIgnoringTimeOrNull'));
    expect(substr($body, 0, (int) strpos($body, "\n    }")))->toContain('self::dayOrNull(');

    // Both writes, or neither: a create gates the column a Set rewrites
    // afterwards, and guarding one of them leaves the other open.
    expect(substr_count($applier, '$this->suppliedDates->refuses('))->toBe(2,
        'the applier has a create path and a field-merge path, and both write a supplied day.');
    // A refused day quarantines the op rather than falling through.
    expect($applier)->toContain('QuarantineReason::ImpossibleDate');
});

it('sees a planted picker and a planted second spelling, and is not fooled by a Blade comment', function (): void {
    $planted = tempnam(sys_get_temp_dir(), 'supplied-date').'.blade.php';
    file_put_contents($planted, <<<'BLADE'
        <div>
            {{-- A comment that names <x-core::date-input wire:model="ghostDate" /> and binds nothing. --}}
            <x-core::date-input field-id="planted" wire:model.live="plantedDate" :aria-label="$a > $b ? 'x' : 'y'" />
            <x-core::date-input wire:model.lazy="rows.{{ $i }}.when" />
        </div>
        BLADE);

    $plantedPhp = tempnam(sys_get_temp_dir(), 'supplied-date-respelling').'.php';
    file_put_contents($plantedPhp, <<<'PHP'
        <?php
        final class PlantedSecondSpelling
        {
            public function day(string $raw): ?CarbonImmutable
            {
                $parsed = CarbonImmutable::createFromFormat('Y-m-d', $raw);

                return $parsed?->format('Y-m-d') === $raw ? $parsed : null;
            }

            public function stamp(string $raw): ?DateTimeImmutable
            {
                return DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $raw) ?: null;
            }
        }
        PHP);

    try {
        $walk = suppliedDateBindings([$planted]);
        $respellings = suppliedDateRespellings([$plantedPhp]);
    } finally {
        @unlink($planted);
        @unlink($plantedPhp);
    }

    expect(array_keys($walk['bindings']))->toBe(['plantedDate', 'rows.*.when']);
    expect($walk['elements'])->toBe(2);

    expect($respellings['counted'])->toBe(2);
    expect(count($respellings['respellings']))->toBe(1);
    expect($respellings['respellings'][0])->toContain("createFromFormat('Y-m-d', \$raw)");
});

// The split is the deliverable, so it is asserted rather than assumed: a rename
// that quietly collapses the two back into one parser would leave every call
// site above reading correctly and behaving the way it did before.
it('keeps a refusing reader and a normalising one under names that say which is which', function (): void {
    expect(method_exists(SafeDate::class, 'dayOrNull'))->toBeTrue();
    expect(method_exists(SafeDate::class, 'normalisedDayOrNull'))->toBeTrue();

    expect(SafeDate::dayOrNull('2027-02-29'))->toBeNull();
    expect(SafeDate::normalisedDayOrNull('2027-02-29')?->toDateString())->toBe('2027-03-01');
});
