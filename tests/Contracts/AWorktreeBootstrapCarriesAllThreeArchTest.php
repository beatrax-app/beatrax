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
        'public/build' => 'copy_tree public/build',
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

    // cp -al is the whole point for vendor/: a symlinked vendor/ makes Pest
    // resolve the project root to the main checkout, so every test loses its
    // TestCase binding and fails with $this->app null.
    expect($script)->toContain('cp -al')
        ->and($script)->toContain('-L ')
        ->and($script)->not->toContain('ln -s');
});

// `cp -al` shares file inodes, and composer rewrites vendor/composer/ in place.
// One worktree's `composer dump-autoload` therefore handed its classmap to six
// siblings, the main checkout included, which then failed static analysis with
// `Class "App\PhpStan\..." not found` for a rename none of them had made.
it('gives each worktree its own vendor/composer, which composer rewrites in place', function (): void {
    $script = worktreeBootstrapScript();

    // Every needle here is unique to the one line it stands for. The first
    // attempt asserted the bare function name, which the definition also
    // carries, and `-type f -links +1`, which both the copy and the check
    // carry — so deleting the call passed, and deleting the check passed.
    // Watched both do it.
    $arms = [
        // The call, which the definition below it does not match.
        'it is never called' => "\nunshare_composer_metadata\n",
        // The copy that actually breaks the links.
        'it copies nothing' => 'cp -R "$shared" "$shared.unshared"',
        // The check that refuses to hand back a worktree still sharing either
        // tree, addressed at $target where the copy above is at $shared.
        'it hands back a shared worktree' => 'find "$target/$shared" -type f -links +1',
        // public/build carries its own inodes for its MTIMES: hardlinked, the
        // checkout stamps every source newer than the bundle and
        // RefuseToShipAStaleFrontEnd reads a fresh worktree as stale — while a
        // `touch` to fix it would restamp the main checkout's bundle through
        // the same inodes, hiding a real staleness everywhere at once.
        'the bundle keeps the main checkout timestamps' => 'find "$target/$what" -exec touch {} +',
    ];

    $missing = [];
    foreach ($arms as $failure => $needle) {
        if (! str_contains($script, $needle)) {
            $missing[] = $failure;
        }
    }

    expect($missing)->toBe([], 'bin/worktree.sh shares vendor/composer again: '.implode(', ', $missing));
});

it('ends on a positive control, so a broken suite and a bare worktree are told apart', function (): void {
    $script = worktreeBootstrapScript();

    expect($script)->toContain('vendor/bin/pest');
});

it('is the thing AGENTS.md tells an agent to run', function (): void {
    expect((string) file_get_contents(base_path('AGENTS.md')))->toContain('bin/worktree.sh');
});
