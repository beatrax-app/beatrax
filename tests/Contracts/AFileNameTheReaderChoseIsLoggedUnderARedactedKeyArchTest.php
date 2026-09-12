<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Modules\DevMode\Internal\Logging\RedactSecretsProcessor;
use Tests\Contracts\Support\RepoTree;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-file-name-the-reader-chose
 */

/**
 * Every context key whose value is a path or a name off one. The shipped log
 * channel redacts by key name, so a value's safety is decided entirely by
 * which of these it was written under.
 *
 * @var list<string>
 */
const PATH_SHAPED_CONTEXT_KEYS = [
    'path', 'file', 'filepath', 'file_path', 'dir', 'directory',
    'filename', 'file_name', 'original_filename', 'source_filename',
    'source_path', 'target_path', 'destination',
];

/**
 * The files whose path-keyed context names a path the APPLICATION owns — a
 * bundled corpus, the updater's download, this install's own secrets file.
 * None of them can hold a name a reader chose, and redacting them would cost
 * the only diagnostic those lines carry.
 *
 * @var array<string, string>
 */
const PATH_KEY_APPLICATION_OWNED = [
    'Modules/Community/Internal/Corpus/CorpusLoader.php' => 'the bundled merchants corpus directory and the region files inside it',
    'Modules/Community/Internal/Corpus/CorpusYamlReader.php' => 'a bundled corpus YAML file this build shipped',
    'Modules/Community/Public/Services/ClassificationRuleProvider.php' => 'the bundled classification corpus directory',
    'Modules/Core/Public/Services/ElectronUpdateChannel.php' => 'the installer electron-updater downloaded, named by the publisher',
    'Modules/OpenBanking/Internal/Services/OpenBankingSecretsFile.php' => 'this install\'s own secrets file, at a path the application composed',
    'Modules/Tax/Internal/Corpus/TaxCorpusLoader.php' => 'a bundled tax corpus file this build shipped',
];

/**
 * The key names the shipped channel replaces outright, read off the processor
 * so the two cannot drift: a key dropped from those lists has to fail here
 * rather than quietly stop being redacted.
 *
 * @return list<string>
 */
function pathKeyRedactedKeys(): array
{
    $processor = new ReflectionClass(RedactSecretsProcessor::class);

    /** @var list<string> $secret */
    $secret = (array) $processor->getConstant('SECRET_KEYS');
    /** @var list<string> $private */
    $private = (array) $processor->getConstant('PRIVATE_CONTENT_KEYS');

    return array_values(array_unique([...$secret, ...$private]));
}

/**
 * The argument text of every log call in $source, with the line each starts on.
 *
 * @return list<array{args: string, line: int}>
 */
function pathKeyLogCalls(string $source): array
{
    $levels = 'emergency|alert|critical|error|warning|notice|info|debug';
    $receivers = ['Log', 'logger\(\)', '\\$[A-Za-z_]*[Ll]og(?:ger)?', '\\$this->log(?:ger)?'];
    $pattern = '/(?:'.implode('|', $receivers).')\s*(?:->|::)\s*(?:'.$levels.')\s*\(/';

    $calls = [];

    foreach (PatternScan::allWithOffsets($pattern, $source)[0] as $match) {
        $start = (int) $match[1] + strlen((string) $match[0]);
        $cursor = pathKeyCloser($source, $start);

        $calls[] = [
            'args' => substr($source, $start, $cursor - $start - 1),
            'line' => substr_count($source, "\n", 0, (int) $match[1]) + 1,
        ];
    }

    return $calls;
}

/** The index one past the `)` closing the call whose arguments begin at $start. */
function pathKeyCloser(string $source, int $start): int
{
    $depth = 1;
    $cursor = $start;
    $length = strlen($source);

    while ($depth > 0 && $cursor < $length) {
        $character = $source[$cursor];

        if ($character === "'" || $character === '"') {
            $cursor = pathKeySkipLiteral($source, $cursor, $character);

            continue;
        }

        $depth += match ($character) {
            '(' => 1,
            ')' => -1,
            default => 0,
        };
        $cursor++;
    }

    return $cursor;
}

function pathKeySkipLiteral(string $source, int $start, string $quote): int
{
    $cursor = $start + 1;
    $length = strlen($source);

    while ($cursor < $length) {
        if ($source[$cursor] === '\\') {
            $cursor += 2;

            continue;
        }

        if ($source[$cursor] === $quote) {
            return $cursor + 1;
        }

        $cursor++;
    }

    return $length;
}

/**
 * @return list<string> the path-shaped context keys $args writes
 */
function pathKeysIn(string $args): array
{
    $found = [];

    foreach (PatternScan::sets('/\'([A-Za-z0-9_\-]+)\'\s*=>/', $args) as $match) {
        $normalised = strtolower(str_replace(['-', ' '], '_', (string) $match[1]));

        if (in_array($normalised, PATH_SHAPED_CONTEXT_KEYS, true)) {
            $found[] = $normalised;
        }
    }

    return $found;
}

// The redactor's own reason for holding a filename list: a bank names a
// statement for the account it covers, so the name spells an IBAN, a card
// number or the holder. The list is by KEY, so the same bytes written under
// `path` walk straight past it into the daily log.
it('writes a reader-chosen file name only under a key the shipped channel redacts', function (): void {
    $redacted = pathKeyRedactedKeys();

    expect($redacted)->toContain('filename', 'the processor no longer redacts `filename`, which every upload path logs under.');

    $files = RepoTree::relativeFiles(RepoTree::PRODUCTION_PHP);

    expect(count($files))->toBeGreaterThan(
        3000,
        'RepoTree returned '.count($files).' shipped PHP files, which is too few to have read the tree.'
    );

    $seen = 0;
    $offenders = [];

    foreach ($files as $file) {
        $source = (string) file_get_contents(RepoTree::root().'/'.$file);

        if (! str_contains($source, '=>')) {
            continue;
        }

        foreach (pathKeyLogCalls($source) as $call) {
            foreach (pathKeysIn($call['args']) as $key) {
                $seen++;

                if (in_array($key, $redacted, true) || array_key_exists($file, PATH_KEY_APPLICATION_OWNED)) {
                    continue;
                }

                $offenders[] = $file.':'.$call['line'].' — '.$key;
            }
        }
    }

    // Read before the verdict: the walk is a balanced-paren reader over every
    // shipped file, and one that stopped early leaves an empty offender list
    // that reads exactly like a clean tree.
    expect($seen)->toBeGreaterThan(
        10,
        'the walk found '.$seen.' path-shaped log context keys, which is too few to be this tree.'
    );

    sort($offenders);

    expect($offenders)->toBe(
        [],
        "The shipped log channel redacts by KEY NAME, and these keys are not on\n".
        "its list. A bank names a statement for the account it covers, so a name\n".
        "a reader chose spells an IBAN, a card number or the holder — and the\n".
        "daily log is where it then sits for LOG_DAILY_DAYS.\n".
        "Write the name under `filename` (or drop it): RedactSecretsProcessor's\n".
        "PRIVATE_CONTENT_KEYS replaces that one outright, and `dir` beside it\n".
        "still says which folder without saying whose file.\n".
        "A line naming a path the APPLICATION composed — a bundled corpus, a\n".
        "downloaded installer — belongs in PATH_KEY_APPLICATION_OWNED with the\n".
        "reason it can hold no reader's name.\n".
        "Offenders:\n  ".implode("\n  ", $offenders),
    );
});

// Driven against planted sources, because the tree writes none and a reader
// that found nothing would report that in exactly the same words.
it('tells a path key from a redacted one and reads past a parenthesis in a message', function (): void {
    $offending = "<?php\n\$logger->warning('scan failed', ['user_id' => 1, 'path' => \$path]);\n";
    $calls = pathKeyLogCalls($offending);

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['line'])->toBe(2)
        ->and(pathKeysIn($calls[0]['args']))->toBe(['path']);

    $safe = "<?php\n\$logger->warning('scan failed (badly)', ['dir' => \$d, 'filename' => basename(\$path)]);\n";
    $safeCalls = pathKeyLogCalls($safe);

    expect($safeCalls)->toHaveCount(1)
        ->and(pathKeysIn($safeCalls[0]['args']))->toBe(['dir', 'filename'])
        ->and(pathKeyRedactedKeys())->toContain('filename')
        ->and(pathKeyRedactedKeys())->not->toContain('dir');

    expect(pathKeysIn("['import_run_id' => 7, 'reason' => 'x']"))->toBe([]);
});

// An entry that no longer names a file is a decision nobody is applying, and
// the next reader takes it for one that is.
it('pins no application-owned file that has gone', function (): void {
    foreach (array_keys(PATH_KEY_APPLICATION_OWNED) as $file) {
        expect(is_file(RepoTree::root().'/'.$file))->toBeTrue($file.' is pinned as application-owned but no longer exists.');
    }
});
