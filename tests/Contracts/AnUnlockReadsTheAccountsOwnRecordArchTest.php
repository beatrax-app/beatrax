<?php

declare(strict_types=1);

use Tests\Contracts\Support\SonarSourceFiles;

/**
 * @link ../../.docs/design/cold-start-biometric-unlock.md
 */

// The OS vault keys its entry on the user id alone, and the account's own
// record that it asked for the enrolment is a column. A screen that reads that
// column only at mount() gates nothing: every Livewire method is callable
// whatever the render offered, so the entry an earlier holder of this id left
// behind is one crafted call away from opening to a key this account has never
// held -- and the session goes on to seal its writes under it.
//
// This does not pin the two screens by name. It derives them: in any file that
// names a cold-start vault, every recover() call must have the account's own
// record read before it, in the same function body.

const COLD_START_UNLOCK_FILE_FLOOR = 1_000;

/** The seams that reach a biometric-gated entry holding a data key. */
const COLD_START_UNLOCK_VAULTS = ['ColdStartVault', 'BiometricKeyVault'];

/** Reading one of these is reading the account's own record of the enrolment. */
const COLD_START_UNLOCK_GATES = ['iscoldstartenrolled', 'isenrolled'];

function coldStartUnlockNamesAVault(string $source): bool
{
    foreach (COLD_START_UNLOCK_VAULTS as $vault) {
        if (preg_match('/\b'.$vault.'\b/', $source) === 1) {
            return true;
        }
    }

    return false;
}

function coldStartUnlockIsVaultImplementation(string $source): bool
{
    return preg_match('/\bimplements\b[^{;]*\bColdStartVault\b/', $source) === 1;
}

/**
 * Every `->recover(` call in a file, as token indexes.
 *
 * @param  list<array{0:int|null,1:string,2:int}>  $tokens
 * @return list<array{index:int,line:int}>
 */
function coldStartUnlockRecoverCalls(array $tokens): array
{
    $calls = [];

    foreach ($tokens as $index => $token) {
        if ($token[0] !== T_OBJECT_OPERATOR && $token[0] !== T_NULLSAFE_OBJECT_OPERATOR) {
            continue;
        }

        $name = $tokens[$index + 1] ?? null;
        $paren = $tokens[$index + 2] ?? null;

        if ($name === null || $name[0] !== T_STRING || strtolower($name[1]) !== 'recover') {
            continue;
        }
        if ($paren === null || $paren[0] !== null || $paren[1] !== '(') {
            continue;
        }

        $calls[] = ['index' => $index, 'line' => $token[2]];
    }

    return $calls;
}

/**
 * The innermost function body containing a token — the unit a gate has to hold
 * within, since a check made in some other method gates nothing here.
 *
 * @param  list<array{0:int|null,1:string,2:int}>  $tokens
 * @param  array<int,int>  $brackets
 * @return array{name:string,open:int,close:int}|null
 */
function coldStartUnlockEnclosingFunction(array $tokens, array $brackets, int $index): ?array
{
    $count = count($tokens);
    $best = null;

    for ($i = 0; $i < $count; $i++) {
        if ($tokens[$i][0] !== T_FUNCTION) {
            continue;
        }

        for ($j = $i + 1; $j < $count; $j++) {
            if ($tokens[$j][0] === null && $tokens[$j][1] === '(') {
                $j = $brackets[$j] ?? $j;

                continue;
            }
            if ($tokens[$j][0] === null && $tokens[$j][1] === ';') {
                break;
            }
            if ($tokens[$j][0] !== null || $tokens[$j][1] !== '{') {
                continue;
            }

            $close = $brackets[$j] ?? $j;

            if ($j < $index && $index < $close && ($best === null || $j > $best['open'])) {
                $best = [
                    'name' => ($tokens[$i + 1][0] ?? null) === T_STRING ? $tokens[$i + 1][1] : '{closure}',
                    'open' => $j,
                    'close' => $close,
                ];
            }

            break;
        }
    }

    return $best;
}

/**
 * @param  list<array{0:int|null,1:string,2:int}>  $tokens
 */
function coldStartUnlockReadsTheRecord(array $tokens, int $from, int $to): bool
{
    for ($i = $from; $i < $to; $i++) {
        if ($tokens[$i][0] === T_STRING && in_array(strtolower($tokens[$i][1]), COLD_START_UNLOCK_GATES, true)) {
            return true;
        }
    }

    return false;
}

/**
 * Every way one file's recover calls fall short of the gate.
 *
 * @return list<string>
 */
function coldStartUnlockFaultsIn(string $path, string $source): array
{
    $tokens = SonarSourceFiles::tokens($source);
    $brackets = SonarSourceFiles::brackets($tokens);
    $faults = [];

    foreach (coldStartUnlockRecoverCalls($tokens) as $call) {
        $where = $path.':'.$call['line'];
        $function = coldStartUnlockEnclosingFunction($tokens, $brackets, $call['index']);

        if ($function === null) {
            $faults[] = $where.' — reaches the vault outside any function, where no read can gate it';

            continue;
        }

        if (! coldStartUnlockReadsTheRecord($tokens, $function['open'], $call['index'])) {
            $faults[] = $where.' — '.$function['name'].'() prompts the vault without reading the account\'s own record of the enrolment first';
        }
    }

    return $faults;
}

it('reads the account\'s own record of the enrolment before it asks the vault for a key', function (): void {
    $files = SonarSourceFiles::all();

    expect(count($files))->toBeGreaterThan(
        COLD_START_UNLOCK_FILE_FLOOR,
        'The walk opened '.count($files).' production files, which is what a reader that stopped reading looks like.',
    );

    $callers = [];
    $faults = [];

    foreach ($files as $path) {
        $source = (string) file_get_contents($path);
        $relative = str_replace(base_path().'/', '', $path);

        if (! coldStartUnlockNamesAVault($source) || coldStartUnlockIsVaultImplementation($source)) {
            continue;
        }

        if (coldStartUnlockRecoverCalls(SonarSourceFiles::tokens($source)) === []) {
            continue;
        }

        $callers[] = $relative;
        $faults = array_merge($faults, coldStartUnlockFaultsIn($relative, $source));
    }

    expect(count($callers))->toBeGreaterThanOrEqual(
        2,
        'Found '.count($callers).' screens asking a cold-start vault for a key; both lock screens do, so a walk seeing fewer read nothing and its verdict on the rest means nothing.',
    );

    expect($faults)->toBe([], implode("\n", [
        'These ask the OS vault for a data key without reading the account\'s record first:',
        ...$faults,
        '',
        'The vault keys its entry on the user id alone, so an entry left by an',
        'earlier holder of that id opens to a key this account has never held —',
        'and a Livewire method is callable whatever mount() rendered. Read',
        'isColdStartEnrolled() at the boundary, not only at the render.',
    ]));
});

it('reads a caller that prompts ungated, and passes one that reads the record first', function (): void {
    $ungated = <<<'PHP'
        <?php
        final class Screen
        {
            public function nativeUnlock(ColdStartVault $vault, Gateway $gateway): void
            {
                $dataKey = $vault->recover($this->userId, 'reason');
                $gateway->isColdStartEnrolled($this->userId);
            }
        }
        PHP;

    expect(coldStartUnlockFaultsIn('Screen.php', $ungated))->toBe(
        ['Screen.php:6 — nativeUnlock() prompts the vault without reading the account\'s own record of the enrolment first'],
        'a read that happens after the key is already in hand gates nothing',
    );

    $gated = <<<'PHP'
        <?php
        final class Screen
        {
            public function nativeUnlock(ColdStartVault $vault, Gateway $gateway): void
            {
                if (! $gateway->isColdStartEnrolled($this->userId)) {
                    return;
                }

                $dataKey = $vault->recover($this->userId, 'reason');
            }
        }
        PHP;

    expect(coldStartUnlockFaultsIn('Screen.php', $gated))->toBe([]);

    expect(coldStartUnlockNamesAVault('<?php final class S { public function f(BiometricKeyVault $v) {} }'))->toBeTrue();
    expect(coldStartUnlockNamesAVault('<?php final class S { public function recover() {} }'))->toBeFalse(
        'a recover() that reaches no vault is somebody else\'s method of that name',
    );
    expect(coldStartUnlockIsVaultImplementation('<?php final class V implements ColdStartVault {}'))->toBeTrue();
});
