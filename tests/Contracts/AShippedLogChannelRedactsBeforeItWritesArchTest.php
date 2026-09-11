<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Modules\DevMode\Internal\Logging\PushRedactProcessor;
use Tests\Contracts\Support\RepoTree;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#redaction-that-was-a-property-of-three-channels
 */

/**
 * The channels that may carry no tap, each with the reason it is refused. A
 * name here that config/logging.php no longer defines reads as stale below,
 * so the list cannot outlive the channel it excuses.
 *
 * @var array<string, string>
 */
const REDACTING_CHANNEL_DECLINES = [
    'null' => 'discards by construction, and NullHandler is not processable, so the tap would resolve and then be skipped',
    'emergency' => 'LogManager::createEmergencyLogger() builds its handler from a path and a level and never calls tap(), so the key would read as a decision and change nothing',
];

/**
 * Every environment template this repository ships, from either Composer root.
 * `../` is how the mobile root reaches the desktop's, and deploy/server is the
 * documented Docker recipe — the one that names a channel none of the others do.
 *
 * @var list<string>
 */
const REDACTING_CHANNEL_ENV_TEMPLATES = [
    '.env.example',
    '.env.bundled',
    'mobile-app/.env.example',
    'mobile-app/.env.bundled',
    'deploy/server/.env.example',
];

/** The keys a template selects a channel with. LOG_STACK is comma-separated. */
const REDACTING_CHANNEL_ENV_KEYS = ['LOG_CHANNEL', 'LOG_STACK', 'LOG_DEPRECATIONS_CHANNEL'];

/**
 * @param  array<string, mixed>  $channels
 * @return list<string> the channels that can write somewhere and tap no redactor
 */
function redactingChannelOffendersIn(array $channels): array
{
    $offenders = [];

    foreach ($channels as $name => $channel) {
        if (array_key_exists((string) $name, REDACTING_CHANNEL_DECLINES)) {
            continue;
        }

        $tap = is_array($channel) ? ($channel['tap'] ?? []) : [];

        if (! is_array($tap) || ! in_array(PushRedactProcessor::class, $tap, true)) {
            $offenders[] = (string) $name;
        }
    }

    sort($offenders);

    return $offenders;
}

/** @return list<string> the channels $env names, in the order the keys are read */
function redactingChannelNamesIn(string $env): array
{
    $named = [];

    foreach (REDACTING_CHANNEL_ENV_KEYS as $key) {
        $match = PatternScan::first('/^'.$key.'=(.*)$/m', $env);

        if ($match === []) {
            continue;
        }

        foreach (PatternScan::split('/,/', trim((string) $match[1])) as $one) {
            if (trim($one) !== '') {
                $named[] = trim($one);
            }
        }
    }

    return array_values(array_unique($named));
}

/** @return array<string, mixed> */
function redactingChannelConfig(): array
{
    /** @var array<string, mixed> $channels */
    $channels = config('logging.channels', []);

    return $channels;
}

it('taps the redacting processor on every channel that can write somewhere', function (): void {
    $channels = redactingChannelConfig();

    // Read before the verdict: an empty config makes the offender list empty
    // because nothing was looked at, which reads exactly like a clean one.
    expect(count($channels))->toBeGreaterThan(
        5,
        'config/logging.php resolved '.count($channels).' channels, which is too few to be this application.',
    );

    expect(redactingChannelOffendersIn($channels))->toBe([], implode("\n", [
        'A channel with an empty `tap` writes whatever a caller hands it, verbatim.',
        'Redaction is not a property of a file on disk — it is a property of a',
        'channel — and LOG_CHANNEL is a deployment decision this repository does',
        'not make: deploy/server names `stderr`, and `docker compose logs` is then',
        'readable by anyone who can reach the host. Add PushRedactProcessor::class',
        'to the channel\'s `tap`, or, if the channel genuinely cannot write',
        'anywhere, name it in REDACTING_CHANNEL_DECLINES with the reason.',
    ]));
});

it('ships no environment template naming a channel that redacts nothing', function (): void {
    $offenders = redactingChannelOffendersIn(redactingChannelConfig());
    $channels = redactingChannelConfig();
    $read = 0;
    $named = [];

    foreach (REDACTING_CHANNEL_ENV_TEMPLATES as $relative) {
        $path = RepoTree::root().'/'.$relative;

        if (! is_file($path)) {
            continue;
        }

        foreach (redactingChannelNamesIn((string) file_get_contents($path)) as $channel) {
            $read++;

            if (! array_key_exists($channel, $channels)) {
                $named[] = $relative.' → '.$channel.' (no such channel)';
            } elseif (in_array($channel, $offenders, true)) {
                $named[] = $relative.' → '.$channel.' (taps no redactor)';
            }
        }
    }

    expect($read)->toBeGreaterThan(
        4,
        'This rule read '.$read.' channel selections across '.count(REDACTING_CHANNEL_ENV_TEMPLATES)
        .' templates. Every one of them was missing or silent, so the verdict below is about nothing.',
    );

    expect($named)->toBe([], "A shipped template selects a channel that writes unredacted:\n  - ".implode("\n  - ", $named));
});

it('excuses no channel config/logging.php no longer defines', function (): void {
    $channels = redactingChannelConfig();
    $stale = array_values(array_diff(array_keys(REDACTING_CHANNEL_DECLINES), array_keys($channels)));

    expect($stale)->toBe([], implode("\n", [
        'REDACTING_CHANNEL_DECLINES names a channel this application no longer has: '.implode(', ', $stale).'.',
        'An exemption nothing needs is one nobody is auditing, and it would silently',
        'absorb a future channel that reused the name.',
    ]));
});

// The tree has no untapped channel left, so the rule above reports on what it
// cannot find. This drives the same reader over both answers.
it('tells a channel that taps the redactor from one that taps nothing', function (): void {
    $tapped = ['single' => ['driver' => 'single', 'tap' => [PushRedactProcessor::class]]];
    $bare = ['stderr' => ['driver' => 'monolog', 'tap' => []]];
    $silent = ['syslog' => ['driver' => 'syslog']];
    $declined = ['null' => ['driver' => 'monolog']];

    expect(redactingChannelOffendersIn($tapped))->toBe([])
        ->and(redactingChannelOffendersIn($bare))->toBe(['stderr'])
        ->and(redactingChannelOffendersIn($silent))->toBe(['syslog'])
        ->and(redactingChannelOffendersIn($declined))->toBe([]);

    expect(redactingChannelNamesIn("APP_ENV=production\nLOG_CHANNEL=stderr\n"))->toBe(['stderr'])
        ->and(redactingChannelNamesIn("LOG_CHANNEL=stack\nLOG_STACK=daily,single\n"))->toBe(['stack', 'daily', 'single'])
        ->and(redactingChannelNamesIn("APP_ENV=local\n# LOG_CHANNEL is unset\n"))->toBe([]);
});
