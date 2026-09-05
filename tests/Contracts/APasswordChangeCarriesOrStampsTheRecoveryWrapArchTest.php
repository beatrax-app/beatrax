<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

// The app-lock recovery wrap is built from the ACCOUNT PASSWORD, so every path
// that writes a new one either carries it over or admits it cannot. A CLI reset
// did neither: the password changed, the wrap silently stopped opening, and no
// alert said so — while the lock screen still offered that road by name.
//
// The first version of this walk looked for `hasher->make` or `Hash::make` on
// the same line as the key. That is only ONE of the two shapes in the tree:
// User casts `password` to `hashed`, so `User::create(['password' => $plain])`
// writes a hash with neither token on the line — the shape InstallCommand
// already uses, and the one a fifth path is most likely to be written in. It
// was invisible, and the InstallCommand exemption sat there proving nothing.

/**
 * @return array<string, string> class => why it needs no wrap decision
 */
function passwordWritersWithNoWrapToCarry(): array
{
    return [
        'SignupAction' => 'creates the account; there is no app lock yet',
        'AddUserAction' => 'creates a household member; their lock does not exist yet',
        'InstallCommand' => 'first-run creation, and it refuses to change an existing password',
        'MobileImportBootstrap' => 'stashes the typed account password in the session so a failed provisioning can be retried; writes no users row',
    ];
}

/**
 * A `'password' =>` array key whose value is neither a literal nor a
 * translation lookup — the one shape every write path in the tree shares,
 * whether it hashes at the call site or leaves it to the model cast. The
 * excluded shapes are real: validation errors key their message on the field
 * name, and the model declares the cast with the same key.
 */
function passwordWriteKeyPattern(): string
{
    return '/\'password\'\s*=>(?!\s*(?:Lang::|__\(|trans\(|\'|"))/';
}

/**
 * @return list<string> absolute paths
 */
function filesWritingANewPasswordHash(): array
{
    $roots = [base_path('Modules'), base_path('app')];
    $hits = [];

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            $path = (string) $file;

            if (! str_ends_with($path, '.php') || str_contains($path, '/tests/')
                || str_contains($path, '/Database/') || str_contains($path, '/Resources/')) {
                continue;
            }

            $source = file_get_contents($path);

            if ($source === false) {
                throw new RuntimeException('unreadable: '.$path);
            }

            if (PatternScan::matches(passwordWriteKeyPattern(), $source)) {
                $hits[] = $path;
            }
        }
    }

    sort($hits);

    return $hits;
}

/**
 * @return list<string> class basename of every file the walk produced
 */
function passwordWriterClassNames(): array
{
    return array_map(
        static fn (string $path): string => basename($path, '.php'),
        filesWritingANewPasswordHash(),
    );
}

it('can see both shapes a new password is written in, and neither shape that is not one', function (): void {
    $pattern = passwordWriteKeyPattern();

    expect(PatternScan::matches($pattern, "'password' => \$this->hasher->make(\$p),"))->toBeTrue();
    expect(PatternScan::matches($pattern, "'password' => Hash::make(\$p),"))->toBeTrue();
    expect(PatternScan::matches($pattern, "'password' => \$password,"))->toBeTrue();
    expect(PatternScan::matches($pattern, "'password'  =>   \$hashed,"))->toBeTrue();

    // A greedy `\s*` OUTSIDE the lookahead backtracks to zero and lets the
    // space itself satisfy it, so both of these matched before the whitespace
    // moved inside — and the two loudest false positives in the tree were read
    // as password writers.
    expect(PatternScan::matches($pattern, "'password' => Lang::get('auth::x.y'),"))->toBeFalse();
    expect(PatternScan::matches($pattern, "'password' => 'hashed',"))->toBeFalse();
});

it('finds the password writers it is meant to be checking', function (): void {
    $found = passwordWriterClassNames();

    // The four that must decide, named rather than counted: a count survives a
    // walk that quietly stopped seeing one and picked up something else.
    foreach (['ResetPasswordCommand', 'ResetPasswordAction', 'ChangePasswordPage', 'ManageUserPage'] as $writer) {
        expect($found)->toContain($writer);
    }

    expect(count($found))->toBeGreaterThanOrEqual(4 + count(passwordWritersWithNoWrapToCarry()));
});

it('exempts no password writer the walk does not reach', function (): void {
    $stale = array_values(array_diff(
        array_keys(passwordWritersWithNoWrapToCarry()),
        passwordWriterClassNames(),
    ));

    expect($stale)->toBe([], implode("\n  ", array_merge(
        ['An exemption names a class the walk never produced, so it is excusing nothing and',
            'would go on excusing nothing if that class grew a real password write. Either the',
            'class no longer writes a password — delete the entry — or the walk stopped seeing',
            'it, which is the more expensive of the two. Stale entries:'],
        $stale,
    )));
});

it('has every password writer either carry the recovery wrap or stamp it stale', function (): void {
    $exempt = passwordWritersWithNoWrapToCarry();
    $offenders = [];

    foreach (filesWritingANewPasswordHash() as $path) {
        $class = basename($path, '.php');

        if (array_key_exists($class, $exempt)) {
            continue;
        }

        $source = (string) file_get_contents($path);

        if (str_contains($source, 'rewrapRecoveryKey') || str_contains($source, 'markRecoveryWrapStale')) {
            continue;
        }

        $offenders[] = str_replace(base_path().'/', '', $path);
    }

    expect($offenders)->toBe([], implode("\n  ", [
        'These write a new account password without deciding what happens to the',
        'app-lock recovery wrap, which is built from the old one. Call',
        'rewrapRecoveryKey() when both passwords are in hand, or',
        'markRecoveryWrapStale() when only the new one is — never neither, because',
        'the failure is silent until a forgotten PIN needs that road.',
        ...$offenders,
    ]));
});
