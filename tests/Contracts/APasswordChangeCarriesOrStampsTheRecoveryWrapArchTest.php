<?php

declare(strict_types=1);

// The app-lock recovery wrap is built from the ACCOUNT PASSWORD, so every path
// that writes a new one either carries it over or admits it cannot. A CLI reset
// did neither: the password changed, the wrap silently stopped opening, and no
// alert said so — while the lock screen still offered that road by name.

/**
 * @return array<string, string> class => why it needs no wrap decision
 */
function passwordWritersWithNoWrapToCarry(): array
{
    return [
        'SignupAction' => 'creates the account; there is no app lock yet',
        'AddUserAction' => 'creates a household member; their lock does not exist yet',
        'InstallCommand' => 'first-run creation, and it refuses to change an existing password',
    ];
}

/**
 * @return list<string>
 */
function filesWritingANewPasswordHash(): array
{
    $roots = [base_path('Modules'), base_path('app')];
    $hits = [];

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

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

            $found = preg_match("/'password'\\s*=>[^,\\n]*(?:hasher->make|Hash::make)/i", $source);

            if ($found === false) {
                throw new RuntimeException('pattern failed on '.$path.': '.preg_last_error_msg());
            }

            if ($found === 1) {
                $hits[] = $path;
            }
        }
    }

    sort($hits);

    return $hits;
}

it('finds the password writers it is meant to be checking', function (): void {
    expect(count(filesWritingANewPasswordHash()))->toBeGreaterThanOrEqual(4);
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
