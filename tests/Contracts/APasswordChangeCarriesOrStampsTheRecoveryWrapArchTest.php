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

// Keyed on the path rather than on the class name: a basename excuses every
// future file that happens to share it, and the four below sit in three modules
// that each already own a second class named for what it does.
/**
 * @return array<string, string> repo-relative path => why it needs no wrap decision
 */
function passwordWritersWithNoWrapToCarry(): array
{
    return [
        'Modules/Auth/Public/Actions/SignupAction.php' => 'creates the account; there is no app lock yet',
        'Modules/Auth/Public/Actions/AddUserAction.php' => 'creates a household member; their lock does not exist yet',
        'Modules/Core/Internal/Console/InstallCommand.php' => 'first-run creation, and it refuses to change an existing password',
        'Modules/Mobile/Internal/Http/Livewire/MobileImportBootstrap.php' => 'stashes the typed account password in the session so a failed provisioning can be retried; writes no users row',
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

            // A seeder writes the demo household's password when it builds it,
            // which is a first creation with no lock behind it, and it is the
            // only write under Database/ the pattern below reaches.
            if (! str_ends_with($path, '.php') || str_contains($path, '/tests/')
                || str_contains($path, '/Database/')) {
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
 * @return list<string> every file the walk produced, relative to the repository root
 */
function passwordWriterPaths(): array
{
    return array_map(
        static fn (string $path): string => str_replace(base_path().'/', '', $path),
        filesWritingANewPasswordHash(),
    );
}

it('can see both shapes a new password is written in, and neither shape that is not one', function (string $line, bool $writes): void {
    expect(PatternScan::matches(passwordWriteKeyPattern(), $line))->toBe(
        $writes,
        'The reader answered '.var_export(! $writes, true).' for a line it has to read as '
        .($writes ? 'a password write' : 'something else').': '.$line
    );
})->with([
    'hashed at the call site' => ["'password' => \$this->hasher->make(\$p),", true],
    'hashed through the facade' => ["'password' => Hash::make(\$p),", true],
    'left to the model cast' => ["'password' => \$password,", true],
    'spaced out' => ["'password'  =>   \$hashed,", true],
    // A greedy `\s*` OUTSIDE the lookahead backtracks to zero and lets the
    // space itself satisfy it, so both of these matched before the whitespace
    // moved inside — and the two loudest false positives in the tree were read
    // as password writers.
    'a translated field label' => ["'password' => Lang::get('auth::x.y'),", false],
    'the model declaring the cast' => ["'password' => 'hashed',", false],
]);

it('finds the password writers it is meant to be checking', function (): void {
    $found = passwordWriterPaths();

    // The four that must decide, named rather than counted: a count survives a
    // walk that quietly stopped seeing one and picked up something else.
    foreach ([
        'Modules/Auth/Internal/Console/ResetPasswordCommand.php',
        'Modules/Auth/Public/Actions/ResetPasswordAction.php',
        'Modules/Auth/Internal/Http/Livewire/ChangePasswordPage.php',
        'Modules/Auth/Internal/Http/Livewire/ManageUserPage.php',
    ] as $writer) {
        expect($found)->toContain($writer);
    }

    expect(count($found))->toBeGreaterThanOrEqual(
        4 + count(passwordWritersWithNoWrapToCarry()),
        'The walk produced '.count($found).' password writers, which is fewer than the four that must decide plus '
        .'the '.count(passwordWritersWithNoWrapToCarry()).' excused: it has stopped seeing one of them.'
    );
});

it('exempts no password writer the walk does not reach', function (): void {
    $stale = array_values(array_diff(
        array_keys(passwordWritersWithNoWrapToCarry()),
        passwordWriterPaths(),
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
        $relative = str_replace(base_path().'/', '', $path);

        if (array_key_exists($relative, $exempt)) {
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
