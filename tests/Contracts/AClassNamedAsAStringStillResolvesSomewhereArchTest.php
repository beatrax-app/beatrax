<?php

declare(strict_types=1);

use Tests\Contracts\Support\StringNamedClasses;

/**
 * @link ../../.docs/features/mobile/architecture.md#a-vendor-hard-codes-a-class-name-and-answers-a-miss-with-silence
 */

/**
 * Names only the mobile Composer root supplies, and the package that supplies
 * each. nativephp/desktop and nativephp/mobile hard-conflict, so the desktop
 * root cannot install these and class_exists() is how the caller asks.
 *
 * @return array<string, string> fully-qualified name => the package it comes from
 */
function stringNamedClassesOnlyTheMobileRootHas(): array
{
    return [
        'Beatrax\BiometricVault\Facades\BiometricVault' => 'beatrax/biometric-vault',
        'Native\Mobile\Facades\Browser' => 'nativephp/mobile',
        'Native\Mobile\Facades\Device' => 'nativephp/mobile',
        'Native\Mobile\Facades\SecureStorage' => 'nativephp/mobile',
        'NativePHP\LocalNotifications\Facades\LocalNotifications' => 'nativephp/mobile-local-notifications',
    ];
}

it('walks a tree that holds these lookups at all', function (): void {
    // An exact count. A floor cannot tell "the walk stopped reaching Mobile"
    // from "nobody asks about a class by string any more", and those are the
    // two outcomes this rule has to separate.
    expect(StringNamedClasses::lookups())->toHaveCount(5);
});

// The shape that cost 29 of 54 native element types: nativephp/mobile resolves
// its plugin allow-list by class_exists() on a literal string and answers a miss
// with an empty list, no exception and no log. Ours are the same construction,
// and a typo in one would read as "this is not the mobile runtime".
it('asks about no class that neither Composer root can answer for', function (): void {
    $elsewhere = stringNamedClassesOnlyTheMobileRootHas();
    $unanswerable = [];

    foreach (StringNamedClasses::lookups() as $name => $sites) {
        if (class_exists($name) || interface_exists($name) || enum_exists($name)) {
            continue;
        }

        if (array_key_exists($name, $elsewhere)) {
            continue;
        }

        $unanswerable[] = $name.' — asked in '.implode(', ', array_unique($sites));
    }

    expect($unanswerable)->toBe([], implode("\n  ", [
        'This root cannot resolve these, and they are not declared as names the mobile root '
            .'supplies. class_exists() answers a misspelling and a genuinely absent class the '
            .'same way, so the branch is taken either way and nothing says so. Either fix the '
            .'spelling or name the package in stringNamedClassesOnlyTheMobileRootHas():',
        ...$unanswerable,
    ]));
});

// A declaration for a name nothing asks about is the same silence one level up:
// it reads as a considered exemption and exempts nothing.
it('declares no absent name that nothing asks about', function (): void {
    $asked = array_keys(StringNamedClasses::lookups());

    $stale = array_values(array_diff(array_keys(stringNamedClassesOnlyTheMobileRootHas()), $asked));

    expect($stale)->toBe([], 'Declared as supplied by the mobile root, but nothing asks about them: '.implode(', ', $stale));
});
