<?php

declare(strict_types=1);

use Modules\Auth\Internal\Lock\IdleTimeoutOptions;
use Modules\Core\Public\Support\PatternScan;
use Symfony\Component\Finder\Finder;

/**
 * @link ../../.docs/features/auth/architecture.md
 * @link ../../.docs/conventions/arch-invariants.md
 */

// A sentence that tells a reader how long something takes is true only while
// the number in the sentence and the number the code runs on are the same one.
// Nothing was making them the same. The app lock's grace window was written 30
// in PHP and 30000 in a script, called "30-second" in a third place, and the
// settings screen mentioned none of it: it offered an idle timeout as though
// that were the only way the app locks, so a reader who chose thirty minutes
// was locked in thirty seconds by a rule nobody had told them about.
//
// So a window a reader is told about gets one home and every other place reads
// it. The rules below are written against symbols rather than against the
// number, because the number is not rare: this repository has a 30-day session
// lifetime, a 30-second PIN backoff and a 30-minute idle option, and a scan
// hunting for "30" would call all three offenders and teach the next reader to
// switch it off.

/**
 * Every window a reader is told about. `disclosed_by` is the line that states
 * it on screen, `carried_by` is the name it travels to the browser under, and
 * `named_by` is every other file that has to talk about it — each with the
 * symbol it must reach for instead of the number.
 */
const WINDOWS_A_READER_IS_TOLD_ABOUT = [
    'the window leaving the foreground locks on' => [
        'seconds' => IdleTimeoutOptions::BACKGROUND_GRACE_SECONDS,
        'constant' => IdleTimeoutOptions::class.'::BACKGROUND_GRACE_SECONDS',
        'disclosed_by' => 'Modules/Auth/Resources/lang/en/app_lock.php',
        'key' => 'auto_lock_note',
        'placeholder' => ':window',
        'carried_by' => 'window.beatraxGraceMs',
        'carried_from' => 'resources/views/layouts/app.blade.php',
        'noun' => 'grace',
        'named_by' => [
            'resources/js/lock.js' => 'window.beatraxGraceMs',
            'resources/views/layouts/app.blade.php' => 'backgroundGraceMs',
            'Modules/Auth/Resources/views/livewire/app-lock-settings-section.blade.php' => 'IdleTimeoutOptions::BACKGROUND_GRACE_SECONDS',
            '.docs/features/auth/architecture.md' => 'BACKGROUND_GRACE_SECONDS',
        ],
    ],
];

// A timing that lives only in the browser, that no server value governs and no
// sentence states, is a literal nobody can drift from. Each names why, and the
// reason is re-checked against the file: a timing that is deleted or renamed
// stops matching its excuse and fails here rather than going quiet.
const CLIENT_ONLY_TIMINGS = [
    'resources/js/emoji-action-hold.js|HOLD_MS' => 'how long a touch is held before it reads as a hold rather than a tap, a gesture threshold no server value governs',
    'resources/js/emoji-action-hold.js|LINGER_MS' => 'how long the verb stays on screen after that hold, a presentation timing no server value governs',
    'resources/js/lock.js|HEARTBEAT_MS' => 'the floor between activity POSTs, a throttle on this page\'s own chatter; the server reads the stamp it produces and never the interval',
];

// Walked rather than globbed: a script moved into a subdirectory would leave a
// flat glob reporting a clean tree over a file nobody opened, which is the
// shape this guard exists to refuse in the first place. Not RepoTree either:
// its scopes carry .php and .blade.php only, and the roots git says hold .js
// are resources, build, and vite.config.js at the top level — a depth-1 path
// RepoTree cannot reach. A scope here would widen the subject to every script
// under resources/, which is not what this guard is about.
/** @return list<string> every script the browser is served */
function readerWindowScripts(): array
{
    $files = [];

    foreach (Finder::create()->files()->in(base_path('resources/js'))->name('*.js') as $file) {
        $files[] = $file->getPathname();
    }

    sort($files);

    return $files;
}

/**
 * The numeric timing constants the scripts declare, as `path|NAME => value`.
 *
 * @return array<string, int>
 */
function readerWindowScriptTimings(): array
{
    $found = [];

    foreach (readerWindowScripts() as $path) {
        $relative = str_replace(base_path().'/', '', $path);

        foreach (PatternScan::sets(
            '/\bconst\s+([A-Za-z_][A-Za-z0-9_]*(?:_MS|_SECONDS|_MINUTES))\s*=\s*(\d+)\s*;/',
            (string) file_get_contents($path),
        ) as $match) {
            $found[$relative.'|'.$match[1]] = (int) $match[2];
        }
    }

    ksort($found);

    return $found;
}

/** A number standing immediately in front of the noun the prose calls the window. */
function readerWindowWrittenOut(string $source, string $noun): int
{
    return PatternScan::count('/(?<![\w.])\d+\s*-?\s*(?:m?s|sec|seconds?|minutes?)?\s+'.preg_quote($noun, '/').'\b/i', $source);
}

/** The number an option label opens with, or null when it opens with a word. */
function readerWindowLeadingNumeral(string $label): ?int
{
    $match = PatternScan::first('/^\s*(\d+)\b/', $label);

    return isset($match[1]) ? (int) $match[1] : null;
}

it('names at least one window a reader is told about, and resolves everything it names', function (): void {
    expect(WINDOWS_A_READER_IS_TOLD_ABOUT)->not->toBeEmpty(
        'The table is empty, so every case in this file iterates nothing and passes without reading a line.',
    );

    foreach (WINDOWS_A_READER_IS_TOLD_ABOUT as $window => $declared) {
        expect(defined($declared['constant']))->toBeTrue(
            'The table names '.$declared['constant'].' as the one definition of '.$window
            .', and no such constant exists. A table pointing at nothing asserts nothing.'
        );
        expect(constant($declared['constant']))->toBe(
            $declared['seconds'],
            'The table states '.$window.' as '.$declared['seconds'].' seconds and '.$declared['constant'].' now says otherwise. '
            .'One of the two is the definition and this table is not it.',
        );

        $paths = [...array_keys($declared['named_by']), $declared['disclosed_by'], $declared['carried_from']];

        foreach ($paths as $path) {
            expect(is_file(base_path($path)))->toBeTrue(
                'The table lists '.$path.' for '.$window.', and the file is gone, so the scans below read nothing.'
            );
        }
    }
});

it('discloses every window a reader is told about, in a line that interpolates it', function (): void {
    foreach (WINDOWS_A_READER_IS_TOLD_ABOUT as $window => $declared) {
        /** @var array<string, mixed> $lines */
        $lines = require base_path($declared['disclosed_by']);

        expect(array_key_exists($declared['key'], $lines))->toBeTrue(
            $declared['disclosed_by'].' declares no '.$declared['key'].', so nothing on screen tells a reader about '
            .$window.'. A screen that names one condition and enforces two has told the reader something untrue.'
        );

        $line = $lines[$declared['key']];
        expect($line)->toBeString($declared['disclosed_by'].' declares '.$declared['key'].' as something other than a line of prose.');

        expect(str_contains((string) $line, $declared['placeholder']))->toBeTrue(
            'The line disclosing '.$window.' must take the number from '.$declared['constant'].' through '
            .$declared['placeholder'].', so the sentence cannot outlive the constant.'
        );

        expect(PatternScan::count('/(?<![\w:])\d+/', (string) $line))->toBe(
            0,
            'The line disclosing '.$window.' writes a numeral out: "'.$line.'". A number typed into a sentence is a '
            .'second definition, and it is the sentence that goes stale rather than the code that goes wrong.'
        );
    }
});

it('reaches for the symbol, in every file that has to talk about the window', function (): void {
    $silent = [];

    foreach (WINDOWS_A_READER_IS_TOLD_ABOUT as $window => $declared) {
        foreach ($declared['named_by'] as $path => $symbol) {
            if (! str_contains((string) file_get_contents(base_path($path)), $symbol)) {
                $silent[] = $path.' no longer names '.$symbol.', for '.$window;
            }
        }
    }

    expect($silent)->toBe([], implode("\n  ", [
        'These describe or enforce a window and have stopped reading it from the one place it is defined. Whatever '
            .'each holds instead is a second definition, free to drift from the sentence a reader is shown:',
        ...$silent,
    ]));
});

it('writes no window a reader is told about out as a number beside its own noun', function (): void {
    $offenders = [];

    foreach (WINDOWS_A_READER_IS_TOLD_ABOUT as $window => $declared) {
        foreach (array_keys($declared['named_by']) as $path) {
            $written = readerWindowWrittenOut((string) file_get_contents(base_path($path)), $declared['noun']);

            if ($written > 0) {
                $offenders[] = $path.' writes "'.$declared['noun'].'" out as a number '.$written
                    .' time(s), for '.$window;
            }
        }
    }

    expect($offenders)->toBe([], implode("\n  ", [
        'Prose that states the number is a copy of it. Name the constant instead — the number moves, the prose does '
            .'not, and the page then describes a window the code stopped running:',
        ...$offenders,
    ]));
});

it('accounts for every timing a script declares', function (): void {
    $timings = readerWindowScriptTimings();

    expect(count($timings))->toBeGreaterThan(
        2,
        'The scan found '.count($timings).' script timings, which is too few to have read resources/js at all.'
    );

    $unaccounted = [];

    foreach (array_keys($timings) as $timing) {
        if (! array_key_exists($timing, CLIENT_ONLY_TIMINGS)) {
            $unaccounted[] = $timing;
        }
    }

    expect($unaccounted)->toBe([], implode("\n  ", [
        'A script declares these timings as literals of its own, and nothing says they are the browser\'s alone. '
            .'Either the server owns the value — emit it and read it, the way window.beatraxGraceMs is — or add it '
            .'to CLIENT_ONLY_TIMINGS with the reason no server value and no sentence depends on it:',
        ...$unaccounted,
    ]));

    $stale = [];

    foreach (array_keys(CLIENT_ONLY_TIMINGS) as $timing) {
        if (! array_key_exists($timing, $timings)) {
            $stale[] = $timing;
        }
    }

    expect($stale)->toBe([], implode("\n  ", [
        'These are excused and no script declares them. The excuse covers nothing and reads as considered:',
        ...$stale,
    ]));
});

it('agrees with the value the code selects on, in every option label that is a number', function (): void {
    /** @var array<string, string> $lines */
    $lines = require base_path('Modules/Auth/Resources/lang/en/app_lock.php');

    expect(IdleTimeoutOptions::LABEL_KEYS)->not->toBeEmpty(
        'The option list is empty, so the loop below compares no label against the value it selects on.',
    );

    foreach (IdleTimeoutOptions::LABEL_KEYS as $minutes => $labelKey) {
        $key = str_replace('auth::app_lock.', '', $labelKey);

        expect(array_key_exists($key, $lines))->toBeTrue(
            'Modules/Auth/Resources/lang/en/app_lock.php declares no '.$key.', which the option list renders.'
        );
        expect(readerWindowLeadingNumeral($lines[$key]))->toBe(
            $minutes,
            'The option reading "'.$lines[$key].'" is selected on '.$minutes.'. A label whose number is its own '
            .'value has to be that value, or the screen offers one window and the lock runs another.'
        );
    }
});

// Every verdict above is read off one list, and a list built by a scan that
// stopped reading is empty for the wrong reason. These plant each drift
// against the reader rather than against the tree.
it('finds each way a window stops having one definition', function (): void {
    expect(readerWindowWrittenOut('starts a 30-second grace timer when the window is', 'grace'))
        ->toBe(1, 'prose writing the window out as a number went unreported');

    expect(readerWindowWrittenOut('starting the grace here meant clicking away', 'grace'))
        ->toBe(0, 'prose naming the window without a number was read as writing it out');

    expect(readerWindowWrittenOut('regardless of a 30-minute idle setting', 'grace'))
        ->toBe(0, 'a number governing a different noun was read as this window');

    expect(PatternScan::count('/(?<![\w:])\d+/', 'locks Beatrax within 30 seconds whatever this setting says'))
        ->toBe(1, 'a numeral typed into the disclosing line went unreported');

    expect(PatternScan::count('/(?<![\w:])\d+/', 'locks Beatrax within :window whatever this setting says'))
        ->toBe(0, 'a placeholder was read as a numeral');

    expect(readerWindowLeadingNumeral('15 minutes'))->toBe(15, 'a label opening with its own value went unread');
    expect(readerWindowLeadingNumeral('Fifteen minutes'))->toBeNull('a label opening with a word has no numeral to compare and must read as none');
});
