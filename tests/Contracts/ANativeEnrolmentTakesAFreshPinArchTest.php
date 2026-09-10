<?php

declare(strict_types=1);

use Tests\Contracts\Support\SonarSourceFiles;

/**
 * @link ../../.docs/design/cold-start-biometric-unlock.md
 */

// An entry in the OS vault is a durable wrap of the data key that a biometric
// alone opens afterwards, for as long as it exists — across app kills, across
// reboots. Creating one therefore costs what removing one costs: the PIN, typed
// now. A control that armed it from the key the session already held asked for
// nothing at all, and there were two such controls before there was one.
//
// This does not pin the funnel by name. It derives it: every call to a cold
// start vault's enroll() outside a vault implementation is counted, there must
// be exactly one, and that one must take a PIN, spend it on the verification
// that produces the key, and zero the key afterwards. A second control cannot
// grow back without failing the count, and a first one cannot lose its PIN
// without failing the shape.
//
// There are TWO durable wraps, and for a while this guard watched one. The
// WebAuthn credential row is `secret || wrapped_key` and opens the same data
// key to the same biometric, outliving the session exactly as the vault entry
// does -- and it is reached through completeEnrollment(), which spells nothing
// this file was reading. So it is a second subject here.
//
// The two subjects are held to different rules, and the difference is real
// rather than a concession. A vault entry is written in the request that takes
// the PIN, so the PIN can be required to produce the very key being stored. A
// WebAuthn ceremony leaves for the browser and comes back, so what survives the
// gap is a proof of the PIN -- single-use, deadlined, bound to the account --
// and the key is read at the moment it is wrapped. The proof is checked at FILE
// scope rather than in the calling function, because it is consumed on the way
// into the action and spent in the method that writes; that is weaker than the
// enroll() rule and is written down here rather than left to be discovered.

const NATIVE_ENROLMENT_FILE_FLOOR = 1_000;

const NATIVE_ENROLMENT_IMPLEMENTATION_FLOOR = 3;

/** The contract whose implementations are allowed to reach the platform directly. */
const NATIVE_ENROLMENT_VAULT_CONTRACT = 'ColdStartVault';

/** The other durable wrap: the WebAuthn credential row, written by this call. */
const NATIVE_ENROLMENT_BROWSER_VERB = 'completeenrollment';

/** What spending a fresh PIN leaves behind for a ceremony that has to travel. */
const NATIVE_ENROLMENT_PROOF = 'FreshPinProof';

function nativeEnrolmentIsVaultImplementation(string $source): bool
{
    return preg_match(
        '/\bimplements\b[^{;]*\b'.NATIVE_ENROLMENT_VAULT_CONTRACT.'\b/',
        $source
    ) === 1;
}

/**
 * Every `->enroll(` call in a file, as token indexes.
 *
 * @param  list<array{0:int|null,1:string,2:int}>  $tokens
 * @return list<array{index:int,line:int}>
 */
function nativeEnrolmentCalls(array $tokens): array
{
    $calls = [];

    foreach ($tokens as $index => $token) {
        if ($token[0] !== T_OBJECT_OPERATOR && $token[0] !== T_NULLSAFE_OBJECT_OPERATOR) {
            continue;
        }

        $name = $tokens[$index + 1] ?? null;
        $paren = $tokens[$index + 2] ?? null;

        if ($name === null || $name[0] !== T_STRING || strtolower($name[1]) !== 'enroll') {
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
 * The innermost function body containing a token, with the parameter list that
 * declares it — the unit a gate has to hold within, since a PIN checked in some
 * other method of the same file gates nothing.
 *
 * @param  list<array{0:int|null,1:string,2:int}>  $tokens
 * @param  array<int,int>  $brackets
 * @return array{name:string,paramOpen:int,paramClose:int,open:int,close:int}|null
 */
function nativeEnrolmentEnclosingFunction(array $tokens, array $brackets, int $index): ?array
{
    $count = count($tokens);
    $best = null;

    for ($i = 0; $i < $count; $i++) {
        if ($tokens[$i][0] !== T_FUNCTION) {
            continue;
        }

        $paramOpen = null;

        for ($j = $i + 1; $j < $count; $j++) {
            if ($tokens[$j][0] === null && $tokens[$j][1] === '(') {
                $paramOpen ??= $j;
                $j = $brackets[$j] ?? $j;

                continue;
            }
            if ($tokens[$j][0] === null && $tokens[$j][1] === '{') {
                $close = $brackets[$j] ?? $j;

                if ($paramOpen !== null && $j < $index && $index < $close && ($best === null || $j > $best['open'])) {
                    $best = [
                        'name' => ($tokens[$i + 1][0] ?? null) === T_STRING ? $tokens[$i + 1][1] : '{closure}',
                        'paramOpen' => $paramOpen,
                        'paramClose' => $brackets[$paramOpen] ?? $paramOpen,
                        'open' => $j,
                        'close' => $close,
                    ];
                }

                break;
            }
            if ($tokens[$j][0] === null && $tokens[$j][1] === ';') {
                break;
            }
        }
    }

    return $best;
}

/**
 * @param  list<array{0:int|null,1:string,2:int}>  $tokens
 */
function nativeEnrolmentDeclaresPin(array $tokens, int $from, int $to): bool
{
    for ($i = $from; $i < $to; $i++) {
        if ($tokens[$i][0] === T_VARIABLE && strtolower($tokens[$i][1]) === '$pin') {
            return true;
        }
    }

    return false;
}

/**
 * The index of the verification call that is handed `$pin`, or null when the
 * body verifies nothing — a verify() called on some other value is not a gate.
 *
 * @param  list<array{0:int|null,1:string,2:int}>  $tokens
 * @param  array<int,int>  $brackets
 */
function nativeEnrolmentPinVerification(array $tokens, array $brackets, int $from, int $to): ?int
{
    for ($i = $from; $i < $to; $i++) {
        if ($tokens[$i][0] !== T_STRING || strtolower($tokens[$i][1]) !== 'verify') {
            continue;
        }

        $paren = $tokens[$i + 1] ?? null;
        if ($paren === null || $paren[0] !== null || $paren[1] !== '(') {
            continue;
        }

        $close = $brackets[$i + 1] ?? $i + 1;

        if (nativeEnrolmentDeclaresPin($tokens, $i + 1, $close)) {
            return $i;
        }
    }

    return null;
}

/**
 * @param  list<array{0:int|null,1:string,2:int}>  $tokens
 */
function nativeEnrolmentZeroesTheKey(array $tokens, int $from, int $to): bool
{
    for ($i = $from; $i < $to; $i++) {
        if ($tokens[$i][0] === T_STRING && strtolower($tokens[$i][1]) === 'sodium_memzero') {
            return true;
        }
    }

    return false;
}

/**
 * Every way one file's enrolment calls fall short of the gate.
 *
 * @return list<string>
 */
function nativeEnrolmentFaultsIn(string $path, string $source): array
{
    $tokens = SonarSourceFiles::tokens($source);
    $brackets = SonarSourceFiles::brackets($tokens);
    $faults = [];

    foreach (nativeEnrolmentCalls($tokens) as $call) {
        $where = $path.':'.$call['line'];
        $function = nativeEnrolmentEnclosingFunction($tokens, $brackets, $call['index']);

        if ($function === null) {
            $faults[] = $where.' — arms the vault outside any function, where no PIN can gate it';

            continue;
        }

        $named = $where.' — '.$function['name'].'()';

        if (! nativeEnrolmentDeclaresPin($tokens, $function['paramOpen'], $function['paramClose'])) {
            $faults[] = $named.' takes no $pin, so it arms the vault on whatever key it can already reach';

            continue;
        }

        $verified = nativeEnrolmentPinVerification($tokens, $brackets, $function['open'], $call['index']);

        if ($verified === null) {
            $faults[] = $named.' reaches the vault without verifying its $pin first';

            continue;
        }

        if (! nativeEnrolmentZeroesTheKey($tokens, $call['index'], $function['close'])) {
            $faults[] = $named.' leaves the released data key in memory after wrapping it';
        }
    }

    return $faults;
}

/**
 * Every `->completeEnrollment(` call in a file, as line numbers.
 *
 * @param  list<array{0:int|null,1:string,2:int}>  $tokens
 * @return list<int>
 */
function nativeEnrolmentBrowserCalls(array $tokens): array
{
    $lines = [];

    foreach ($tokens as $index => $token) {
        if ($token[0] !== T_OBJECT_OPERATOR && $token[0] !== T_NULLSAFE_OBJECT_OPERATOR) {
            continue;
        }

        $name = $tokens[$index + 1] ?? null;
        $paren = $tokens[$index + 2] ?? null;

        if ($name === null || $name[0] !== T_STRING || strtolower($name[1]) !== NATIVE_ENROLMENT_BROWSER_VERB) {
            continue;
        }
        if ($paren === null || $paren[0] !== null || $paren[1] !== '(') {
            continue;
        }

        $lines[] = $token[2];
    }

    return $lines;
}

/**
 * Whether a file spends a fresh-PIN proof: it must name the proof and consume
 * one. Minting is not spending -- the screen that takes the PIN mints, and a
 * file that only minted would be arming itself.
 */
function nativeEnrolmentSpendsAProof(string $source): bool
{
    return str_contains($source, NATIVE_ENROLMENT_PROOF) && preg_match('/->consume\(/', $source) === 1;
}

it('arms the OS vault from exactly one place, and that place spends a PIN to do it', function (): void {
    $files = SonarSourceFiles::all();

    expect(count($files))->toBeGreaterThan(
        NATIVE_ENROLMENT_FILE_FLOOR,
        'The walk opened '.count($files).' production files, which is what a reader that stopped reading looks like.',
    );

    $implementations = [];
    $delegations = 0;
    $funnels = [];
    $faults = [];

    foreach ($files as $path) {
        $source = (string) file_get_contents($path);
        $relative = str_replace(base_path().'/', '', $path);

        if (nativeEnrolmentIsVaultImplementation($source)) {
            $implementations[] = $relative;
        }

        if (! str_contains($source, '->enroll(')) {
            continue;
        }

        // An implementation is the platform's own side of the contract: it is
        // where the key finally lands, and it is reached only through whatever
        // gate stands in front of the contract.
        if (nativeEnrolmentIsVaultImplementation($source)) {
            $delegations++;

            continue;
        }

        $funnels[] = $relative;
        $faults = array_merge($faults, nativeEnrolmentFaultsIn($relative, $source));
    }

    expect(count($implementations))->toBeGreaterThanOrEqual(
        NATIVE_ENROLMENT_IMPLEMENTATION_FLOOR,
        'Found '.count($implementations).' implementations of '.NATIVE_ENROLMENT_VAULT_CONTRACT
        .'; the platform-detection half of this guard read nothing, so its verdict on the rest means nothing.',
    );

    expect($delegations)->toBeGreaterThanOrEqual(
        1,
        'No implementation was seen passing the key down to its platform, so the call reader found nothing to read.',
    );

    expect($faults)->toBe([], implode("\n", [
        'These arm the OS vault without a fresh PIN behind them:',
        ...$faults,
        '',
        'The entry written there is a durable wrap of the data key that a',
        'biometric alone opens afterwards, surviving app kills and reboots. It',
        'costs what removing it costs: a PIN, typed now, verified now, and used',
        'to produce the very key that gets wrapped — never the key the session',
        'was already holding. Zero that key once it is wrapped.',
    ]));

    expect($funnels)->toHaveCount(1, implode("\n", [
        'The vault is armed from '.count($funnels).' places outside its own implementations:',
        ...$funnels,
        '',
        'There is one funnel on purpose. Two controls for the same enrolment is',
        'how the PIN-free one shipped: the gated one was mounted by nothing and',
        'the mounted one asked for nothing. Route the new caller through the',
        'existing enroller rather than reaching the contract a second time.',
    ]));
});

it('reads a caller that arms the vault with no PIN, and passes one that spends a PIN on the key it stores', function (): void {
    $ungated = <<<'PHP'
        <?php
        final class Settings
        {
            public function enrolNatively(KeyService $keys, Session $session): void
            {
                $dataKey = $keys->release($session);
                $this->vault->enroll($this->userId, $dataKey);
            }
        }
        PHP;

    expect(nativeEnrolmentFaultsIn('Settings.php', $ungated))->toBe(
        ['Settings.php:7 — enrolNatively() takes no $pin, so it arms the vault on whatever key it can already reach'],
        'a session-held key is the shape the whole guard exists to refuse',
    );

    $unverified = <<<'PHP'
        <?php
        final class Settings
        {
            public function enrol(int $userId, string $pin): void
            {
                $this->vault->enroll($userId, $this->keys->held());
            }
        }
        PHP;

    expect(nativeEnrolmentFaultsIn('Settings.php', $unverified))->toBe(
        ['Settings.php:6 — enrol() reaches the vault without verifying its $pin first'],
        'accepting a PIN and never checking it is a box, not a gate',
    );

    $unzeroed = <<<'PHP'
        <?php
        final class Enroller
        {
            public function enrol(int $userId, string $pin, Session $session): bool
            {
                $dataKey = $this->verifier->verify($userId, $pin, $session);

                return $this->vault->enroll($userId, $dataKey);
            }
        }
        PHP;

    expect(nativeEnrolmentFaultsIn('Enroller.php', $unzeroed))->toBe(
        ['Enroller.php:8 — enrol() leaves the released data key in memory after wrapping it'],
        'the key is unwrapped for exactly as long as it takes to wrap it again',
    );

    $gated = <<<'PHP'
        <?php
        final class Enroller
        {
            public function enrol(int $userId, string $pin, Session $session): bool
            {
                $dataKey = $this->verifier->verify($userId, $pin, $session);

                if ($dataKey === null) {
                    return false;
                }

                $stored = $this->vault->enroll($userId, $dataKey);
                sodium_memzero($dataKey);

                return $stored;
            }
        }
        PHP;

    expect(nativeEnrolmentFaultsIn('Enroller.php', $gated))->toBe([]);

    expect(nativeEnrolmentIsVaultImplementation('<?php final class V implements ColdStartVault {}'))->toBeTrue();
    expect(nativeEnrolmentIsVaultImplementation('<?php final class V { public function f(ColdStartVault $v) {} }'))->toBeFalse(
        'naming the contract in a signature is using it, not being it',
    );
});

it('writes the other durable wrap from exactly one place, and that place spends a proof of a fresh PIN', function (): void {
    $files = SonarSourceFiles::all();

    expect(count($files))->toBeGreaterThan(
        NATIVE_ENROLMENT_FILE_FLOOR,
        'The walk opened '.count($files).' production files, which is what a reader that stopped reading looks like.',
    );

    $callers = [];
    $unproven = [];

    foreach ($files as $path) {
        $source = (string) file_get_contents($path);

        if (! str_contains($source, '->completeEnrollment(')) {
            continue;
        }

        $relative = str_replace(base_path().'/', '', $path);
        $callers[] = $relative;

        if (nativeEnrolmentSpendsAProof($source)) {
            continue;
        }

        foreach (nativeEnrolmentBrowserCalls(SonarSourceFiles::tokens($source)) as $line) {
            $unproven[] = $relative.':'.$line;
        }
    }

    expect($unproven)->toBe([], implode("\n", [
        'These write a WebAuthn wrap of the data key without a fresh PIN behind them:',
        ...$unproven,
        '',
        'The row it writes is `secret || wrapped_key` in the ledger\'s own file, and',
        'a biometric alone opens it afterwards for as long as it exists. An',
        'unlocked session is what it used to cost, and the session cannot be the',
        'proof that the enrolment was asked for, because the entry outlives it.',
        'Consume a FreshPinProof before the ceremony is allowed to complete.',
    ]));

    expect($callers)->toHaveCount(1, implode("\n", [
        'The WebAuthn wrap is written from '.count($callers).' places:',
        ...$callers,
        '',
        'One is enough, and one is what the proof can be reasoned about across.',
        'Route a new caller through the existing action rather than reaching the',
        'ceremony a second time.',
    ]));
});

it('reads a browser enrolment that spends no proof, and passes one that does', function (): void {
    $ungated = <<<'PHP'
        <?php
        final class Enrol
        {
            public function __invoke(array $response, Session $session): Outcome
            {
                $dataKey = $this->lockState->heldKey($session);

                $this->service->completeEnrollment($this->userId, $response, $dataKey, $session);

                return Outcome::Enrolled;
            }
        }
        PHP;

    expect(nativeEnrolmentSpendsAProof($ungated))->toBeFalse(
        'an unlocked session is not a proof that the enrolment was asked for',
    );
    expect(nativeEnrolmentBrowserCalls(SonarSourceFiles::tokens($ungated)))->toBe([8]);

    $minted = <<<'PHP'
        <?php
        final class Screen
        {
            public function __construct(private FreshPinProof $proof) {}

            public function enrolWithPin(string $pin): void
            {
                $this->proof->mint($this->session, $this->userId);
            }
        }
        PHP;

    expect(nativeEnrolmentSpendsAProof($minted))->toBeFalse(
        'the screen that takes the PIN mints the proof; a file that only mints is arming itself',
    );

    $proven = <<<'PHP'
        <?php
        final class Enrol
        {
            public function __construct(private FreshPinProof $pinProof) {}

            public function __invoke(array $response, Session $session): Outcome
            {
                if (! $this->pinProof->consume($session, $this->userId)) {
                    return Outcome::PinNotProved;
                }

                $this->service->completeEnrollment($this->userId, $response, $session);

                return Outcome::Enrolled;
            }
        }
        PHP;

    expect(nativeEnrolmentSpendsAProof($proven))->toBeTrue();
});
