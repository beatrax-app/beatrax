<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

// storage/app is where the application writes what belongs to the person using
// it: imported statements, dropped mail, sync identities, whole database
// backups. The ignore list named those directories one at a time, so it lagged
// the code that mints them -- db-backups/ held databases and matched no line.

function ignoreAnswerFor(string $path): int
{
    return Process::path(base_path())->run(['git', 'check-ignore', '-q', '--', $path])->exitCode() ?? 128;
}

// git answers 0 for ignored and 1 for not, and anything else means it could
// not answer at all -- which a test must not read as either one.
function isIgnoredByGit(string $path): bool
{
    $code = ignoreAnswerFor($path);

    expect($code)->toBeIn([0, 1], 'git check-ignore could not answer for '.$path);

    return $code === 0;
}

it('ignores a data directory under storage/app that no line names', function (string $name): void {
    expect(isIgnoredByGit('storage/app/'.$name))->toBeTrue(
        'storage/app/'.$name.' is committable. The ignore rule for storage/app has stopped being '
        ."wholesale, so the next directory the application mints will be committable too.\n"
        .'This is what put whole databases one `git add -A` away from the history.',
    );
})->with([
    'the one that was missing' => 'db-backups',
    'a name minted after this test was written' => 'a-directory-nobody-has-invented-yet',
    'a file rather than a directory' => 'some-export.sqlite',
    'something nested' => 'private/statements/march.pdf',
]);

it('keeps every file under storage/app that is actually tracked', function (): void {
    // The other half: excluding the directory wholesale is only correct if the
    // handful of placeholders git already tracks are re-included by name. A
    // tracked file that the rules also ignore is a working tree that disagrees
    // with itself, and git resolves that disagreement silently.
    $tracked = Process::path(base_path())->run(['git', 'ls-files', '--', 'storage/app']);

    expect($tracked->successful())->toBeTrue();

    $files = array_values(array_filter(explode("\n", trim($tracked->output()))));

    expect($files)->not->toBeEmpty('git listed no tracked file under storage/app, so this read nothing.');

    $contradicted = array_values(array_filter($files, static fn (string $file): bool => isIgnoredByGit($file)));

    expect($contradicted)->toBe([], implode("\n", [
        'These are tracked AND ignored:',
        ...$contradicted,
        '',
        'Re-include each by name after the wholesale exclusion. A parent directory',
        'must be re-included before anything under it can be.',
    ]));
});

it('reads a path it should call committable as committable', function (): void {
    // The positive control. Every assertion above passes if check-ignore has
    // started answering "ignored" for everything -- a broken invocation, a
    // wrong working directory, an exit code read from the wrong process.
    expect(isIgnoredByGit('tests/Contracts/ADataDirectoryNobodyListedIsStillIgnoredArchTest.php'))->toBeFalse();
});
