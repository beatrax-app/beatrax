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

// Both denominators, so a reader that stopped recognising a send fails on what
// it found rather than reporting every caller clean: the two seams declare four
// identity-reading sends between them, and the analysed tree calls them five
// times. Both floors sit under those and above zero.
const PAIRING_SEND_METHOD_FLOOR = 4;

const PAIRING_SEND_CALL_SITE_FLOOR = 3;

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

    $found = [];

    foreach ($reflection->getMethods() as $method) {
        if ($method->getDeclaringClass()->getName() !== $class || ! str_starts_with($method->getName(), 'send')) {
            continue;
        }

        // The method's own file, not the class's: a trait method reports the
        // using class as its declaring class while its line numbers point into
        // the trait, so slicing the class file would read someone else's body.
        $source = explode("\n", (string) file_get_contents((string) $method->getFileName()));

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

/**
 * Every line of $source calling one of $names on an object and letting the
 * answer go nowhere, each marked with whether $excused — the other receiver
 * this file is pinned for — is the one being called on that line.
 *
 * A call that opens a statement and closes it, or opens one and runs on to its
 * own closing line, is a call whose answer goes nowhere. Assigned, returned or
 * passed as an argument, the line never begins with the variable it hangs off.
 *
 * @param  list<string>  $names
 * @return list<array{line: int, statement: string, excused: bool}>
 */
function pairingSendCallSites(string $source, array $names, ?string $excused): array
{
    $sites = [];

    foreach (explode("\n", $source) as $index => $line) {
        $statement = trim($line);

        if (! str_ends_with($statement, ');') && ! str_ends_with($statement, '(')) {
            continue;
        }

        foreach ($names as $name) {
            if (preg_match('/^\$\w+(->\w+)*->'.preg_quote($name, '/').'\(/', $statement) !== 1) {
                continue;
            }

            $sites[] = [
                'line' => $index + 1,
                'statement' => $statement,
                'excused' => $excused !== null && str_contains($statement, $excused),
            ];
        }
    }

    return $sites;
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
    expect($inspected)->toBeGreaterThanOrEqual(
        PAIRING_SEND_METHOD_FLOOR,
        'Reflection found '.$inspected.' sends that read this device\'s identity across '
        .count(PAIRING_SEND_SEAMS).' seams, so the verdict below is read off almost nothing.'
    );

    expect($offenders)->toBe([], 'a pairing send that reads this device\'s own identity can find none — the '
        .'app-lock holds the key, or sync was never enabled here — and a caller re-emitting on a three-second '
        .'poll cannot tell that apart from a frame that went out. Return '.PairingFrameSend::class.' so the '
        .'screen above can stop claiming a ceremony is under way: '.implode(' | ', $offenders));
});

it('lets no caller drop the answer on the floor', function (): void {
    $names = pairingSendNames();

    expect($names)->not->toBe(
        [],
        'Neither seam declares a send at all, so the scan below has no name to look for and reports every caller clean.'
    );

    $discards = [];
    $sites = 0;

    foreach (SonarSourceFiles::all() as $path) {
        $relative = str_replace(base_path().'/', '', $path);

        foreach (pairingSendCallSites((string) file_get_contents($path), $names, PAIRING_SEND_OTHER_RECEIVERS[$relative] ?? null) as $site) {
            $sites++;

            if (! $site['excused']) {
                $discards[] = $relative.':'.$site['line'].' — '.$site['statement'];
            }
        }
    }

    expect($sites)->toBeGreaterThanOrEqual(
        PAIRING_SEND_CALL_SITE_FLOOR,
        'The reader recognised '.$sites.' send call sites in the whole analysed tree, which is what a line scan '
        .'that stopped matching looks like: no call found is no call to judge.'
    );

    expect($discards)->toBe([], 'the answer to "did this device\'s half of the ceremony leave" was thrown away '
        .'here, which is the silence the typed return replaced. Render it, or hand it to something that will: '
        .implode(' | ', $discards));
});

it('keeps every pin pointing at the send it excuses', function (): void {
    expect(PAIRING_SEND_OTHER_RECEIVERS)->not->toBe(
        [],
        'The pin map is empty, so this rule proves nothing about it.'
    );

    $idle = [];

    foreach (PAIRING_SEND_OTHER_RECEIVERS as $relative => $receiver) {
        $excused = array_filter(
            pairingSendCallSites((string) file_get_contents(base_path($relative)), pairingSendNames(), $receiver),
            static fn (array $site): bool => $site['excused'],
        );

        if ($excused === []) {
            $idle[] = $relative.' waves on no '.$receiver.'send at all';
        }
    }

    expect($idle)->toBe(
        [],
        'A pin that excuses nothing reads as considered while it waves on whatever moves into the file it names. '
        ."Delete it, or point it at wherever the call went:\n  ".implode("\n  ", $idle)
    );
});

// A guard that cannot go red says nothing, and both verdicts above are read off
// this one reader. It is checked against the shapes it was written for rather
// than against the tree.
it('finds a send whose answer goes nowhere and leaves a read one alone', function (string $line, int $sites, bool $excused): void {
    $found = pairingSendCallSites('<?php'."\n".$line, ['sendConfirm'], 'frameCourier->');

    expect($found)->toHaveCount($sites);

    if ($sites === 1) {
        expect($found[0]['excused'])->toBe($excused);
    }
})->with([
    'a discarded send' => ['        $this->peerLink->sendConfirm($a, $b);', 1, false],
    'a send opening a multi-line call' => ['        $this->peerLink->sendConfirm(', 1, false],
    'the pinned other receiver' => ['        $this->frameCourier->sendConfirm($a, $b);', 1, true],
    'an assigned answer' => ['        $sent = $this->peerLink->sendConfirm($a, $b);', 0, false],
    'a returned answer' => ['        return $this->peerLink->sendConfirm($a, $b);', 0, false],
    'a send handed on as an argument' => ['        $this->render($this->peerLink->sendConfirm($a, $b));', 0, false],
    'a static call of the same name' => ['        Courier::sendConfirm($a, $b);', 0, false],
]);
