<?php

declare(strict_types=1);

use Modules\Sync\Internal\Pairing\PairingPeerLink;
use Modules\Sync\Public\Enums\PairingFrameSend;
use Modules\Sync\Public\Services\PairingGateway;
use Tests\Contracts\Support\SonarSourceFiles;

// A phone's identity locked under an open trust gate. sendResponderAccept()
// answered the poll with a bare `return`, so fifty-eight ticks drained the
// relay, cleared the message and redrew a live Confirm button across four
// minutes in which not one frame was sent.
/**
 * @link ../../.docs/features/sync/pairing-handshake.md#a-ceremony-that-cannot-speak-says-so
 */

/** @var list<class-string> the seams a pairing screen sends through */
const PAIRING_SEND_SEAMS = [
    PairingPeerLink::class,
    PairingGateway::class,
];

// Two other classes answer to the same method names. PairingFrameCourier's
// sends throw rather than decline, and PairingPeerErrands' own refusals sit
// behind a guard its one caller has already run. Pinned by the receiver that
// reaches them, so a rename fails the pin rather than excusing a new call.
const PAIRING_SEND_OTHER_RECEIVERS = [
    'Modules/Sync/Internal/Pairing/PairingPeerLink.php' => 'frameCourier->',
    'Modules/Sync/Internal/Pairing/PairingPeerErrands.php' => 'frameCourier->',
    'Modules/Sync/Internal/Pairing/PendingPairingCourier.php' => 'frameCourier->',
    'Modules/Sync/Internal/Http/Livewire/PairingFlowModal.php' => 'errands->',
];

/** @return list<array{name: string, returns: ?string, readsIdentity: bool}> */
function pairingSendMethods(string $class): array
{
    $reflection = new ReflectionClass($class);
    $source = explode("\n", (string) file_get_contents((string) $reflection->getFileName()));

    $found = [];

    foreach ($reflection->getMethods() as $method) {
        if ($method->getDeclaringClass()->getName() !== $class || ! str_starts_with($method->getName(), 'send')) {
            continue;
        }

        $body = implode("\n", array_slice(
            $source,
            (int) $method->getStartLine() - 1,
            (int) $method->getEndLine() - (int) $method->getStartLine() + 1,
        ));

        $type = $method->getReturnType();

        // Either it asks the loader itself, or it forwards to the one that
        // does — a facade that swallowed the answer is the same silence one
        // call deeper, and the screen is the caller in both cases.
        $found[] = [
            'name' => $method->getName(),
            'returns' => $type instanceof ReflectionNamedType ? $type->getName() : null,
            'readsIdentity' => str_contains($body, 'identityLoader->load(')
                || str_contains($body, 'peerLink->send'),
        ];
    }

    return $found;
}

/** @return list<string> the method names both seams answer to */
function pairingSendNames(): array
{
    $names = [];

    foreach (PAIRING_SEND_SEAMS as $class) {
        foreach (pairingSendMethods($class) as $method) {
            $names[$method['name']] = true;
        }
    }

    return array_keys($names);
}

it('lets no pairing send that can find no identity answer with a bare return', function (): void {
    $offenders = [];
    $inspected = 0;

    foreach (PAIRING_SEND_SEAMS as $class) {
        foreach (pairingSendMethods($class) as $method) {
            if (! $method['readsIdentity']) {
                continue;
            }

            $inspected++;

            if ($method['returns'] !== PairingFrameSend::class) {
                $offenders[] = $class.'::'.$method['name'].'() returns '.($method['returns'] ?? 'nothing declared');
            }
        }
    }

    // A walk that matched nothing reports exactly what a clean tree reports,
    // and both seams renaming their sends is how that happens.
    expect($inspected)->toBeGreaterThanOrEqual(4);

    expect($offenders)->toBe([], 'a pairing send that reads this device\'s own identity can find none — the '
        .'app-lock holds the key, or sync was never enabled here — and a caller re-emitting on a three-second '
        .'poll cannot tell that apart from a frame that went out. Return '.PairingFrameSend::class.' so the '
        .'screen above can stop claiming a ceremony is under way: '.implode(' | ', $offenders));
});

it('lets no caller drop the answer on the floor', function (): void {
    $names = pairingSendNames();

    expect($names)->not->toBe([]);

    $discards = [];

    foreach (SonarSourceFiles::all() as $path) {
        $relative = str_replace(base_path().'/', '', $path);
        $notTheSeam = PAIRING_SEND_OTHER_RECEIVERS[$relative] ?? null;

        foreach (explode("\n", (string) file_get_contents($path)) as $index => $line) {
            $statement = trim($line);

            // A call that opens a statement and closes it — or opens one and
            // runs on to its own closing line — is a call whose answer goes
            // nowhere. Assigned, returned or passed as an argument, the line
            // never begins with the variable the call hangs off.
            if (! str_ends_with($statement, ');') && ! str_ends_with($statement, '(')) {
                continue;
            }

            foreach ($names as $name) {
                if (preg_match('/^\$\w+(->\w+)*->'.$name.'\(/', $statement) !== 1) {
                    continue;
                }

                if ($notTheSeam !== null && str_contains($statement, $notTheSeam)) {
                    continue;
                }

                $discards[] = $relative.':'.($index + 1).' — '.trim($line);
            }
        }
    }

    expect($discards)->toBe([], 'the answer to "did this device\'s half of the ceremony leave" was thrown away '
        .'here, which is the silence the typed return replaced. Render it, or hand it to something that will: '
        .implode(' | ', $discards));
});

it('keeps every pin pointing at the send it excuses', function (): void {
    foreach (PAIRING_SEND_OTHER_RECEIVERS as $relative => $receiver) {
        $reaches = str_contains((string) file_get_contents(base_path($relative)), $receiver.'send');

        expect($reaches)->toBeTrue($relative.' no longer reaches '.$receiver.'send, so its pin excuses nothing '
            .'and would hide whatever takes its place');
    }
});
