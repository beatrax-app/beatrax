<?php

declare(strict_types=1);

use Modules\Core\Public\Support\BladePhpSource;
use Modules\Core\Public\Support\OwnerOnlyPath;
use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RepoTree;

/**
 * @link ../../.docs/architecture/owner-only-paths.md
 */

// Each entry is one CALL SITE whose discarded answer genuinely carries nothing,
// named by the text of the call itself rather than by the file holding it. A
// per-file pin would wave on the next chmod written into the same class, which
// is the whole shape this guard exists to catch; `proves` is re-run against the
// walk, so a pin whose call has moved or changed fails as loudly as a new one.
// chmod leaves a mode to the umask. fflush and fsync leave BYTES unwritten:
// fwrite reports what it put in a userspace buffer, so a full disk surfaces at
// the flush, and a short temp file then gets chmod'ed and renamed over a good
// one. Both are an answer nobody read.
const DISCARDED_ANSWER_NAMES = ['chmod', 'fflush', 'fsync'];

const DISCARDED_ANSWER_PINS = [
    'Modules/Core/Internal/Console/Support/BackupSidecar.php' => [
        'reason' => 'rename() preserves the mode of a tmp file this class already chmod\'ed and checked, so the re-chmod is belt-and-braces over a settled mode',
        'proves' => '/^chmod\(\$sidecar,0o600\)$/',
    ],
    'Modules/Core/Public/Support/OwnerOnlyPath.php' => [
        'reason' => 'the seam itself: it discards the answer on purpose and reads the mode back off disk instead, which is the stronger question',
        'proves' => '/^chmod\(\$path,\$mode\)$/',
    ],
    'Modules/DevMode/Internal/Logging/LogFileStats.php' => [
        'reason' => 'truncating a log to zero, where the flush has no bytes to lose and the caller already answers 0 on any failure',
        'proves' => '/^fflush\(\$handle\)$/',
    ],
    'Modules/DevMode/Internal/Process/CommandSpawner.php' => [
        'reason' => 'the mkdir(0700) above it is checked and throws; this only re-states the mode of a run directory an earlier spawn already created',
        'proves' => '/^chmod\(\$path,0700\)$/',
    ],
];

/**
 * The PHP that ships, read from the one declared home for a scan's roots. The
 * hand-written pair this used to carry — Modules and app, plus two bootstrap
 * files by name — said nothing about scripts/, config/ or either shell's
 * bootstrap, and the rule below claims every chmod that ships.
 *
 * Absolute, and the relative half is taken from RepoTree's own root rather than
 * from base_path(): the mobile job points base_path() at mobile-app/, and a
 * path rebuilt on it there names a file the walk never opened.
 *
 * @return list<string>
 */
function discardedAnswerShippedFiles(): array
{
    return RepoTree::files(RepoTree::PRODUCTION_PHP);
}

function discardedAnswerRelative(string $path): string
{
    return str_replace(RepoTree::root().'/', '', $path);
}

/**
 * The `chmod()` calls whose answer nothing reads, each as the text of the call
 * itself so a pin can name one site rather than a whole file. Tokenised rather
 * than matched: `if (! @chmod(...))` and `@chmod(...);` differ only in what
 * stands before the call, which no expression over the raw line can tell apart.
 *
 * @return list<array{line: int, call: string}>
 */
function discardedAnswerCalls(string $source): array
{
    $tokens = array_values(array_filter(
        token_get_all($source),
        static fn (array|string $token): bool => ! is_array($token)
            || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));

    $calls = [];

    foreach ($tokens as $index => $token) {
        if (! is_array($token) || $token[0] !== T_STRING
            || ! in_array(strtolower($token[1]), DISCARDED_ANSWER_NAMES, true)) {
            continue;
        }

        if (($tokens[$index + 1] ?? null) !== '(') {
            continue;
        }

        $before = $tokens[$index - 1] ?? ';';
        $before = $before === '@' ? ($tokens[$index - 2] ?? ';') : $before;

        // A call that opens its own statement is one whose answer has nowhere
        // to go. Anything else — an `if`, a `!`, an assignment, a `&&` — is
        // holding on to it.
        if (! in_array($before, [';', '{', '}'], true) && ! (is_array($before) && $before[0] === T_OPEN_TAG)) {
            continue;
        }

        $calls[] = ['line' => $token[2], 'call' => discardedAnswerCallText($tokens, $index, $token[1])];
    }

    return $calls;
}

/**
 * The call as source, whitespace already dropped by the tokeniser, so a pin
 * matches the argument list and not the formatting around it.
 *
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 */
function discardedAnswerCallText(array $tokens, int $index, string $name): string
{
    $text = $name;
    $depth = 0;

    for ($cursor = $index + 1, $count = count($tokens); $cursor < $count; $cursor++) {
        $piece = is_array($tokens[$cursor]) ? $tokens[$cursor][1] : $tokens[$cursor];
        $text .= $piece;

        if ($piece === '(') {
            $depth++;

            continue;
        }

        if ($piece === ')') {
            $depth--;

            if ($depth === 0) {
                break;
            }
        }
    }

    return $text;
}

// Every one of these narrows a path holding somebody's financial life: a key,
// a ledger, a plaintext snapshot, a bank credential. chmod answering false and
// nobody asking is the mode staying wherever the umask left it, silently and
// for as long as the file exists.
it('reads the answer of every call that ships and can fail quietly', function (): void {
    $files = discardedAnswerShippedFiles();

    // Far under the ~6,700 the tree holds. A walk that opened nothing would
    // find no discarded call and report a clean tree.
    expect(count($files))->toBeGreaterThan(
        2000,
        'The walk opened '.count($files).' shipped PHP files, which is too few to have read the tree at all.',
    );

    $offenders = [];
    $reached = [];
    $found = 0;

    foreach ($files as $path) {
        $relative = discardedAnswerRelative($path);
        $source = BladePhpSource::forPath($path, (string) file_get_contents($path));

        foreach (discardedAnswerCalls($source) as $call) {
            $found++;
            $pin = DISCARDED_ANSWER_PINS[$relative] ?? null;

            if ($pin !== null && PatternScan::matches($pin['proves'], $call['call'])) {
                $reached[$relative] = true;

                continue;
            }

            $offenders[] = $relative.':'.$call['line'].' — '.$call['call'];
        }
    }

    expect($found)->toBeGreaterThanOrEqual(
        count(DISCARDED_ANSWER_PINS),
        'The walk found fewer discarded calls than are pinned, so the reader stopped rather than the tree getting cleaner.',
    );

    expect($offenders)->toBe(
        [],
        "An answer nothing reads is a failure nobody hears: a mode left to the umask, or bytes never written.\n".
        'For a mode, route the path through '.OwnerOnlyPath::class.". For a flush, throw:\n  ".
        implode("\n  ", $offenders),
    );

    // A pin nothing reaches any more is a claim about the tree that stopped
    // being true, and it would otherwise sit here forever.
    $reachedPins = array_keys($reached);
    $declaredPins = array_keys(DISCARDED_ANSWER_PINS);
    sort($reachedPins);
    sort($declaredPins);

    expect($reachedPins)->toBe(
        $declaredPins,
        'A pinned call site the walk no longer reaches excuses nothing. Delete the entry rather than leave a '
        .'waiver standing over a file whose next chmod it would silently cover.',
    );
});

it('still holds each pinned call site to the reason it was granted for', function (): void {
    $broken = [];

    foreach (DISCARDED_ANSWER_PINS as $relative => $pin) {
        $path = RepoTree::root().'/'.$relative;

        if (! is_file($path)) {
            $broken[] = $relative.' is pinned and no longer exists';

            continue;
        }

        $calls = array_column(discardedAnswerCalls((string) file_get_contents($path)), 'call');
        $matching = array_filter($calls, static fn (string $call): bool => PatternScan::matches($pin['proves'], $call));

        if (count($matching) !== 1) {
            $broken[] = $relative.' is exempt because "'.$pin['reason'].'", and '.count($matching)
                .' discarded call(s) read that way rather than exactly one';
        }
    }

    expect($broken)->toBe([], implode("\n  ", [
        'An exemption whose reason no longer holds is a gap nobody chose:',
        ...$broken,
    ]));
});

// The guard is only worth its runtime if it fails on the shape it describes,
// and the near-misses are the half a pattern gets wrong: a checked answer, a
// method that merely shares the name, and a call inside a longer expression.
it('reports a discarded answer and leaves every one somebody reads alone', function (): void {
    $planted = implode("\n", [
        '<?php',
        '@chmod($path, 0600);',
        'chmod($other, 0700);',
        'if (! @chmod($checked, 0600)) { throw new RuntimeException("no"); }',
        '$ok = chmod($assigned, 0600);',
        '$files->chmod($viaMethod, 0600);',
        'Filesystem::chmod($viaStatic, 0600);',
        '@fflush($fp);',
        'fsync($fp);',
        'if (@fflush($ok) === false) { throw new RuntimeException("no"); }',
        '$flushed = fsync($kept);',
    ]);

    $calls = discardedAnswerCalls($planted);

    expect(array_column($calls, 'call'))->toBe(
        ['chmod($path,0600)', 'chmod($other,0700)', 'fflush($fp)', 'fsync($fp)'],
        'The reader either missed a discarded call or read a checked one as discarded.',
    );
    expect(array_column($calls, 'line'))->toBe([2, 3, 8, 9], 'A pinned call is found by its line, so the line has to be the call\'s own.');
});
