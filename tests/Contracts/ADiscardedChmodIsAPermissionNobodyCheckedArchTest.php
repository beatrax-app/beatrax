<?php

declare(strict_types=1);

use Modules\Core\Public\Support\BladePhpSource;
use Modules\Core\Public\Support\OwnerOnlyPath;

/**
 * @link ../../.docs/architecture/owner-only-paths.md
 */

/** @return list<string> repo-relative PHP files that ship */
function discardedChmodShippedFiles(): array
{
    $files = ['bootstrap/app.php', 'mobile-app/bootstrap/app.php'];

    foreach (['Modules', 'app'] as $root) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path($root), FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if ($file->isFile() && str_ends_with($path, '.php') && ! str_contains($path, '/tests/')) {
                $files[] = str_replace(base_path().'/', '', $path);
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * Lines carrying a `chmod()` whose answer nothing reads. Tokenised rather than
 * matched: `if (! @chmod(...))` and `@chmod(...);` differ only in what stands
 * before the call, which no expression over the raw line can tell apart.
 *
 * @return list<int>
 */
function discardedChmodLines(string $source): array
{
    $tokens = array_values(array_filter(
        token_get_all($source),
        static fn (array|string $token): bool => ! is_array($token)
            || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));

    $lines = [];

    foreach ($tokens as $index => $token) {
        if (! is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'chmod') {
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
        if (in_array($before, [';', '{', '}'], true) || (is_array($before) && $before[0] === T_OPEN_TAG)) {
            $lines[] = $token[2];
        }
    }

    return $lines;
}

// Every one of these narrows a path holding somebody's financial life: a key,
// a ledger, a plaintext snapshot, a bank credential. chmod answering false and
// nobody asking is the mode staying wherever the umask left it, silently and
// for as long as the file exists.
it('reads the answer of every chmod that ships', function (): void {
    $offenders = [];

    foreach (discardedChmodShippedFiles() as $path) {
        if (discardedChmodLines(BladePhpSource::forPath($path, (string) file_get_contents(base_path($path)))) !== []) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe(
        pinnedDiscardedChmods(),
        "A chmod() whose answer nothing reads leaves the mode to the umask and says nothing when it fails.\n".
        'Route the path through '.OwnerOnlyPath::class." instead of adding a pin:\n  ".
        implode("\n  ", array_diff($offenders, pinnedDiscardedChmods())),
    );
});

/**
 * The three files whose discarded answer genuinely carries nothing, each
 * already covered by a checked call over the same path.
 *
 * @return list<string>
 */
function pinnedDiscardedChmods(): array
{
    return [
        // rename() preserves the mode of a tmp file this class already chmod'ed
        // and checked, so the re-chmod is belt-and-braces over a settled mode.
        'Modules/Core/Internal/Console/Support/BackupSidecar.php',
        // The seam itself: it discards the answer on purpose and reads the mode
        // back off disk instead, which is the stronger question.
        'Modules/Core/Public/Support/OwnerOnlyPath.php',
        // The mkdir(0700) above it is checked and throws; this only re-states
        // the mode of a run directory an earlier spawn already created.
        'Modules/DevMode/Internal/Process/CommandSpawner.php',
    ];
}
