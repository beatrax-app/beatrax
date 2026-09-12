<?php

declare(strict_types=1);

// A worktree missing any one of these three reports failures that look like
// code: no vendor/ is no pest at all, no public/build/ is 22
// ViteManifestNotFoundException failures across 4 files, and no .env is a
// key:generate that throws on a file that is not there. The script is the only
// place that knowledge lives now, so dropping a line from it silently brings
// the whole class of phantom failure back.

/**
 * The script without its comment lines. The header explains what each of the
 * three things is for, so a scan over the whole file finds every name whether
 * or not the code still acts on it — which is how this rule first passed with
 * `link_tree public/build` deleted.
 */
function worktreeBootstrapScript(): string
{
    $lines = explode("\n", (string) file_get_contents(base_path('bin/worktree.sh')));

    return implode("\n", array_filter(
        $lines,
        static fn (string $line): bool => ! str_starts_with(ltrim($line), '#'),
    ));
}

it('is executable, because a setup step nobody can run is documentation', function (): void {
    $path = base_path('bin/worktree.sh');

    expect(is_file($path))->toBeTrue('bin/worktree.sh is gone, and AGENTS.md tells every agent to run it.')
        ->and(is_executable($path))->toBeTrue();
});

it('brings all three of the things a fresh worktree lacks', function (): void {
    $script = worktreeBootstrapScript();

    // The CALL that brings each one, not the name: every one of the three is
    // also named by the symlink check further down, so a rule looking for the
    // bare name passes with the copy deleted. Watched it do exactly that.
    $brings = [
        'vendor' => 'link_tree vendor',
        'public/build' => 'link_tree public/build',
        '.env' => 'cp "$main/.env"',
    ];

    $missing = [];
    foreach ($brings as $needed => $call) {
        if (! str_contains($script, $call)) {
            $missing[] = $needed;
        }
    }

    expect($missing)->toBe([], 'bin/worktree.sh no longer brings: '.implode(', ', $missing));
});

it('hardlinks rather than symlinks, and refuses a symlink it finds', function (): void {
    $script = worktreeBootstrapScript();

    // cp -al is the whole point: a symlinked vendor/ makes Pest resolve the
    // project root to the main checkout, so every test loses its TestCase
    // binding and fails with $this->app null.
    expect($script)->toContain('cp -al')
        ->and($script)->toContain('-L ')
        ->and($script)->not->toContain('ln -s');
});

it('ends on a positive control, so a broken suite and a bare worktree are told apart', function (): void {
    $script = worktreeBootstrapScript();

    expect($script)->toContain('vendor/bin/pest');
});

it('is the thing AGENTS.md tells an agent to run', function (): void {
    expect((string) file_get_contents(base_path('AGENTS.md')))->toContain('bin/worktree.sh');
});
