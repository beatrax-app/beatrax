<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route as RouteFacade;
use Livewire\Component;
use Modules\Core\Public\Support\PatternScan;
use Modules\Sync\Internal\OpLog\QuarantineReason;
use Tests\Contracts\Support\BackendSourceFiles;
use Tests\Contracts\Support\WireCallableMethods;

// A quarantined entry can be a transaction, a split or a move that is simply
// not there, and the only person who can notice it is missing is the reader.
// So the count and the sentence that explains it may not live behind the
// developer flag, which is the one place they lived.

// The denominator this guard is written against. Asserted rather than derived
// so the file cannot pass by finding nothing: a case added or removed changes
// what "every reason" means and the author has to come back here and say what
// the new one tells a reader.
const QUARANTINE_READER_REASON_COUNT = 15;

// The four the devices screen already speaks for, through SyncBacklogState.
// Pinned as a number because it is the measurement this surface was built on
// top of, and moving a reason across the recoverable line silently would move
// it out of copy that asks the reader to act and into copy that says to wait.
const QUARANTINE_READER_RECOVERABLE_COUNT = 4;

// A reason that deliberately reaches no reader, keyed by its backing value and
// carrying the argument for it. Empty: every reason a device can refuse an
// operation for is something the reader can be told. An entry here is a
// decision somebody wrote down, never a hole the count drifted into.
const QUARANTINE_READER_DELIBERATELY_UNREAD = [];

// The class the recoverable set reaches a reader through. Named as a string
// because this guard must be able to run against a tree where the projection
// beside it does not exist yet — that is how it reports what is missing.
const QUARANTINE_READER_BACKLOG_PROJECTION = 'SyncBacklogState';

/**
 * Livewire components no developer-gated route stands in front of.
 *
 * @return list<class-string<Component>>
 */
function quarantineReaderComponents(): array
{
    static $ungated = null;

    if (is_array($ungated)) {
        return $ungated;
    }

    $gated = quarantineReaderGatedComponents();

    return $ungated = array_values(array_filter(
        WireCallableMethods::components(),
        static fn (string $component): bool => ! in_array($component, $gated, true),
    ));
}

/**
 * The components a reader with no developer flag can never reach: the action of
 * a route carrying ensureDeveloperMode, and everything the developer console
 * module ships. Read off the runtime route table, because the alias only
 * resolves through gatherMiddleware(), which expands the group.
 *
 * @return list<class-string<Component>>
 */
function quarantineReaderGatedComponents(): array
{
    static $resolved = null;

    if (is_array($resolved)) {
        return $resolved;
    }

    $gated = [];

    foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
        if (! in_array('ensureDeveloperMode', $route->gatherMiddleware(), true)) {
            continue;
        }

        $action = explode('@', $route->getActionName())[0];

        if (class_exists($action) && is_subclass_of($action, Component::class)) {
            $gated[] = $action;
        }
    }

    foreach (WireCallableMethods::components() as $component) {
        if (str_starts_with($component, 'Modules\\DevMode\\')) {
            $gated[] = $component;
        }
    }

    return $resolved = array_values(array_unique($gated));
}

/**
 * Everything one component puts in front of a reader: its own code, comments
 * stripped, plus every template it names resolved through the view finder, so
 * a renamed view is a missing template rather than a silently empty scan.
 *
 * @param  class-string<Component>  $component
 * @return list<string> absolute paths
 */
function quarantineReaderFilesOf(string $component): array
{
    $file = (new ReflectionClass($component))->getFileName();

    if ($file === false) {
        return [];
    }

    $paths = [$file];
    $factory = app('view');
    $finder = $factory->getFinder();

    foreach (PatternScan::all('/[\'"]([a-z][a-z0-9_-]*::[a-z0-9._-]+)[\'"]/', quarantineReaderCodeOf($file))[1] as $candidate) {
        if ($factory->exists($candidate)) {
            $paths[] = $finder->find($candidate);
        }
    }

    return array_values(array_unique($paths));
}

/** The source of one file with its comments removed, whether PHP or Blade. */
function quarantineReaderCodeOf(string $path): string
{
    if (str_ends_with($path, '.blade.php')) {
        return PatternScan::replace('/\{\{--.*?--\}\}/s', '', (string) file_get_contents($path));
    }

    return implode('', array_map(
        static fn (array|string $token): string => is_array($token) ? $token[1] : $token,
        BackendSourceFiles::codeTokens($path),
    ));
}

/**
 * Whether any of these files names a symbol, in code rather than in a comment.
 *
 * @param  list<string>  $paths
 */
function quarantineReaderNames(array $paths, string $symbol): bool
{
    foreach ($paths as $path) {
        if (str_contains(quarantineReaderCodeOf($path), $symbol)) {
            return true;
        }
    }

    return false;
}

/**
 * Every enum beside QuarantineReason that declares which reasons it speaks for.
 * Discovered by reflection off the live symbols — never by grep, because a
 * sweep enumerated with a pattern once and mis-triaged the whole set.
 *
 * @return array<string, list<QuarantineReason>> short class name => the reasons it covers
 */
function quarantineReaderProjections(): array
{
    $projections = [];

    foreach (glob(base_path('Modules/Sync/Internal/OpLog/*.php')) ?: [] as $path) {
        $fqcn = 'Modules\\Sync\\Internal\\OpLog\\'.basename($path, '.php');

        if (! enum_exists($fqcn) || ! method_exists($fqcn, 'reasons')) {
            continue;
        }

        $covered = [];

        foreach ((new ReflectionEnum($fqcn))->getCases() as $case) {
            $instance = $case->getValue();

            foreach ($instance->reasons() as $reason) {
                if ($reason instanceof QuarantineReason) {
                    $covered[$reason->value] = $reason;
                }
            }
        }

        if ($covered !== []) {
            $projections[basename($path, '.php')] = array_values($covered);
        }
    }

    return $projections;
}

/**
 * Reason value => the projection that carries it to a reader with no developer
 * flag. A projection counts only where a component the reader can actually
 * open names it: an enum nothing renders tells nobody anything.
 *
 * @return array<string, string>
 */
function quarantineReaderCoverage(): array
{
    static $answer = null;

    if (is_array($answer)) {
        return $answer;
    }

    $files = [];

    foreach (quarantineReaderComponents() as $component) {
        foreach (quarantineReaderFilesOf($component) as $path) {
            $files[] = $path;
        }
    }

    $files = array_values(array_unique($files));
    $covered = [];

    if (quarantineReaderNames($files, QUARANTINE_READER_BACKLOG_PROJECTION)) {
        foreach (QuarantineReason::recoverable() as $value) {
            $covered[$value] = QUARANTINE_READER_BACKLOG_PROJECTION;
        }
    }

    foreach (quarantineReaderProjections() as $name => $reasons) {
        if (! quarantineReaderNames($files, $name)) {
            continue;
        }

        foreach ($reasons as $reason) {
            $covered[$reason->value] = $name;
        }
    }

    return $answer = $covered;
}

it('is written against every reason the live enum has', function (): void {
    expect(QuarantineReason::cases())->toHaveCount(
        QUARANTINE_READER_REASON_COUNT,
        'This guard asserts that every quarantine reason reaches a reader, and it counts them itself so it cannot '.
        "pass by finding none.\nThe enum now has ".count(QuarantineReason::cases()).' cases against a pinned '.
        QUARANTINE_READER_REASON_COUNT.".\nRead the new case, decide what it tells somebody whose data is affected, ".
        'give it an outcome, and only then move this number.',
    );
});

it('scans a reader surface that actually exists', function (): void {
    $components = quarantineReaderComponents();
    $gated = quarantineReaderGatedComponents();

    expect($components)->not->toBe([], 'no ungated Livewire component was found, so this guard read nothing at all')
        ->and($gated)->not->toBe([], 'no developer-gated component was found — is DevModeServiceProvider booting?');

    $files = [];

    foreach ($components as $component) {
        $files = array_merge($files, quarantineReaderFilesOf($component));
    }

    expect($files)->not->toBe([], 'the ungated components named no file at all, so every reason would read as unreached');
});

it('still finds the recoverable set on the devices screen', function (): void {
    $coverage = quarantineReaderCoverage();

    $viaBacklog = array_keys(array_filter(
        $coverage,
        static fn (string $projection): bool => $projection === QUARANTINE_READER_BACKLOG_PROJECTION,
    ));

    sort($viaBacklog);
    $recoverable = QuarantineReason::recoverable();
    sort($recoverable);

    expect($viaBacklog)->toBe(
        $recoverable,
        "SyncBacklogState is how the recoverable reasons reach a reader, and this is the measurement the rest of\n".
        "the quarantine surface was built beside: four of fifteen. If that set moved, the copy moved with it —\n".
        'a reason held for a reason no pass retries must never be shown as "behind, catching up".',
    )->and($viaBacklog)->toHaveCount(QUARANTINE_READER_RECOVERABLE_COUNT);
});

it('tells a reader with no developer flag about every reason an operation can be refused for', function (): void {
    $coverage = quarantineReaderCoverage();
    $unreached = [];

    foreach (QuarantineReason::cases() as $reason) {
        if (isset($coverage[$reason->value]) || array_key_exists($reason->value, QUARANTINE_READER_DELIBERATELY_UNREAD)) {
            continue;
        }

        $unreached[] = $reason->value;
    }

    expect($unreached)->toBe(
        [],
        "These quarantine reasons reach nobody outside the developer console:\n  ".implode("\n  ", $unreached)."\n\n".
        "A quarantined entry is a transaction, a split or a move that is not there, and the reader is the only\n".
        "person who can tell. Every reason therefore needs one of two things: an outcome on an enum under\n".
        "Modules/Sync/Internal/OpLog declaring reasons(), rendered by a component no ensureDeveloperMode route\n".
        "stands in front of — or an entry in QUARANTINE_READER_DELIBERATELY_UNREAD with the argument for it.\n".
        'A count with no sentence beside it is not either of those.',
    );
});

it('does not credit a projection named only in a comment', function (): void {
    $planted = tempnam(sys_get_temp_dir(), 'quarantine-reader').'.blade.php';
    file_put_contents($planted, <<<'BLADE'
        {{-- QuarantineOutcome is named here inside a comment and must not count --}}
        <p>{{ Lang::get('sync::quarantine.last_seen') }}</p>
        BLADE);

    try {
        $inComment = quarantineReaderNames([$planted], 'QuarantineOutcome');
        $inCode = quarantineReaderNames([$planted], 'sync::quarantine.last_seen');
    } finally {
        @unlink($planted);
    }

    expect($inComment)->toBeFalse('a symbol mentioned in a Blade comment was credited as copy a reader can see')
        ->and($inCode)->toBeTrue('the scanner missed a key the template really renders, so it would credit nothing');
});
