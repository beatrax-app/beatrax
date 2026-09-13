<?php

declare(strict_types=1);

use Tests\Contracts\Support\SonarSourceFiles;

// The app-lock PIN was metered on the lock screen and unmetered on three
// settings panels that checked the same secret, because each of them held a
// PinHasher and asked it directly. A guard naming those three call sites would
// have been blind to the fourth, so this one names the capability instead:
// which files can compare a typed code against the stored hash at all.
// @link ../../.docs/features/auth/every-pin-check-is-metered.md

const PIN_VERIFYING_FILE = 'Modules/Auth/Internal/Lock/PinVerificationService.php';

const PIN_HASHING_FILE = 'Modules/Auth/Internal/Lock/PinHasher.php';

// Four files name the type today — the two above, the provisioner that still
// hashes new PINs with it, and the provider that binds it. A walk that finds
// fewer has stopped reading the tree rather than found it clean.
const PIN_HASHER_HOLDER_FLOOR = 4;

/** @return list<string> the analysed files whose source carries this needle */
function pinCheckingFilesNaming(string $needle): array
{
    $found = [];

    foreach (SonarSourceFiles::all() as $path) {
        if (str_contains((string) file_get_contents($path), $needle)) {
            $found[] = str_replace(base_path().'/', '', $path);
        }
    }

    sort($found);

    return $found;
}

/**
 * Every name a PinHasher is bound to in this file: the variable after the type
 * in a declaration, and the one a `new PinHasher` is assigned to.
 *
 * @param  list<array{0:int|null,1:string,2:int}>  $tokens
 * @return list<string>
 */
function pinHasherBindings(array $tokens): array
{
    $names = [];

    foreach ($tokens as $index => $token) {
        if ($token[0] !== T_STRING || $token[1] !== 'PinHasher') {
            continue;
        }

        $after = $tokens[$index + 1] ?? null;
        if ($after !== null && $after[0] === T_VARIABLE) {
            $names[] = ltrim($after[1], '$');
        }

        // `$hasher = new PinHasher(...)` binds backwards instead.
        $before = $tokens[$index - 1] ?? null;
        $assigned = $tokens[$index - 3] ?? null;
        if ($before !== null && $before[0] === T_NEW && $assigned !== null && $assigned[0] === T_VARIABLE) {
            $names[] = ltrim($assigned[1], '$');
        }
    }

    return array_values(array_unique($names));
}

/**
 * @param  list<array{0:int|null,1:string,2:int}>  $tokens
 * @param  list<string>  $bound
 */
function pinHasherIsAskedToVerify(array $tokens, array $bound): bool
{
    foreach ($tokens as $index => $token) {
        if ($token[0] !== T_STRING || $token[1] !== 'verify') {
            continue;
        }

        $arrow = $tokens[$index - 1] ?? null;
        $open = $tokens[$index + 1] ?? null;
        $receiver = $tokens[$index - 2] ?? null;

        $isCall = $arrow !== null
            && in_array($arrow[0], [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
            && $open !== null && $open[0] === null && $open[1] === '(';

        if ($isCall && $receiver !== null && in_array(ltrim($receiver[1], '$'), $bound, true)) {
            return true;
        }
    }

    return false;
}

it('leaves one file able to check a PIN against the hash beside it', function (): void {
    $holders = pinCheckingFilesNaming('PinHasher');

    expect(count($holders))->toBeGreaterThanOrEqual(
        PIN_HASHER_HOLDER_FLOOR,
        'The walk found '.count($holders).' files naming PinHasher, so it is reading less of the tree than this rule covers.',
    );

    $askers = [];

    foreach ($holders as $relative) {
        $tokens = SonarSourceFiles::tokens((string) file_get_contents(base_path($relative)));

        if (pinHasherIsAskedToVerify($tokens, pinHasherBindings($tokens))) {
            $askers[] = $relative;
        }
    }

    // The positive control: a scanner that recognised nothing would report an
    // empty set and read as a clean tree.
    expect($askers)->toBe(
        [PIN_VERIFYING_FILE],
        "A PIN checked outside the metered verifier is a guess nothing counts.\nFound in:\n  ".implode("\n  ", $askers),
    );
});

it('leaves the libsodium comparison under PinHasher reachable from one file', function (): void {
    $callers = pinCheckingFilesNaming('sodium_crypto_pwhash_str_verify');

    expect($callers)->toBe(
        [PIN_HASHING_FILE],
        'Reaching past PinHasher to libsodium is the other way to get an unmetered check.',
    );
});

// The verifier proves a PIN by unwrapping rather than by hashing, so a second
// unwrap of this column would be a second PIN check that never touches
// PinHasher and that neither rule above can see. AppLockProvisioner is here
// because it writes the column and reads it for presence in keyState(); it
// does not unwrap it. A third name arriving on this list is the thing to look
// at, whatever it says it is doing.
it('pins every file that reads the PIN wrap', function (): void {
    expect(pinCheckingFilesNaming('pin_wrapped_key'))->toBe([
        'Modules/Auth/Internal/Lock/AppLockProvisioner.php',
        PIN_VERIFYING_FILE,
    ]);
});
