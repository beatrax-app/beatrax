<?php

declare(strict_types=1);

use Modules\Core\Internal\Enums\OsFamily;
use Modules\Core\Public\Enums\UpdateChannel;
use Modules\Core\Public\Support\PatternScan;

// Two channels are declared and only one was ever published. The release
// pipeline wrote the `latest` set for every tag shape, release candidates
// included, so a bundle on preview asked for `beta-mac.yml` and got nothing
// back for as long as it asked — a channel that existed in the enum, in the
// fetcher and in the settings screen, and nowhere on a release page.
//
// The channels are read from the enum rather than listed here, so a third one
// cannot be added without the pipeline being told how to publish it.

function previewChannelWorkflow(): string
{
    foreach (['.github/workflows/release.yml', '../.github/workflows/release.yml'] as $candidate) {
        $path = base_path($candidate);

        if (is_file($path)) {
            return (string) file_get_contents($path);
        }
    }

    return '';
}

/**
 * Steps are named at twelve spaces of indentation and run to the next one.
 *
 * @return array<string, string>
 */
function previewChannelSteps(string $source): array
{
    $steps = [];
    $current = null;

    foreach (explode("\n", $source) as $line) {
        $named = PatternScan::first('/^ {12}- name: (.+)$/', $line);

        if ($named !== []) {
            $current = trim($named[1]);
            $steps[$current] = '';

            continue;
        }

        if ($current !== null) {
            $steps[$current] .= $line."\n";
        }
    }

    return $steps;
}

/**
 * Keyed by step name, so an offender can be reported as the step a reader can
 * find rather than as the first line of its body.
 *
 * @param  array<string, string>  $steps
 * @return array<string, string>
 */
function previewChannelStepsContaining(array $steps, string $needle): array
{
    return array_filter($steps, static fn (string $body): bool => str_contains($body, $needle));
}

/** @return list<string> every manifest file name the fetcher can ask for */
function previewChannelManifestNames(): array
{
    $names = [];

    foreach (UpdateChannel::cases() as $channel) {
        foreach (OsFamily::cases() as $family) {
            $names[] = $channel->manifestPrefix().$family->updateManifestSuffix().'.yml';
        }
    }

    return $names;
}

it('emits, on every platform, a manifest under each name a bundle can ask for', function (): void {
    $workflow = previewChannelWorkflow();

    expect($workflow)->not->toBe('', 'release.yml was not found from either composer root');

    $emitting = previewChannelStepsContaining(previewChannelSteps($workflow), 'openssl dgst -sha512');

    expect($emitting)->toHaveCount(
        count(OsFamily::cases()),
        'One manifest-emitting step per platform is what makes each platform\'s digest its own. '
        .'A platform that stopped emitting one would publish an installer nothing describes.',
    );

    $emitted = implode("\n", array_values($emitting));

    $absent = array_values(array_filter(
        previewChannelManifestNames(),
        static fn (string $name): bool => ! str_contains($emitted, $name),
    ));

    expect($absent)->toBe([], implode("\n  ", [
        'HttpPublisherManifestFetcher will ask for these file names, and no build job writes them.',
        'A bundle on that channel fetches a 404 for as long as it stays there, which reads exactly',
        'like a project that has published nothing — the reader is given no way to tell the two apart.',
        'Missing:',
        ...$absent,
    ]));
});

it('lets the tag shape decide the channel, rather than writing one set for every shape', function (): void {
    $emitting = previewChannelStepsContaining(previewChannelSteps(previewChannelWorkflow()), 'openssl dgst -sha512');

    $unconditional = [];
    foreach ($emitting as $step => $body) {
        // `*-*` is a semver prerelease identifier, which is the only thing that
        // separates a preview tag from a stable one (releasing.md).
        if (! str_contains($body, '*-*)')) {
            $unconditional[] = $step;
        }
    }

    expect($unconditional)->toBe([], implode("\n  ", [
        'A build job writes its manifests without asking what shape the tag has. A release candidate',
        'published under the stable channel\'s own name is offered to readers who never opted into one,',
        'and it was that single unconditional write that left the preview channel with nothing at all.',
        'Offenders:',
        ...$unconditional,
    ]));
});

it('carries every channel through one signing key and one re-verification', function (): void {
    $steps = previewChannelSteps(previewChannelWorkflow());

    $signing = previewChannelStepsContaining($steps, 'sodium_crypto_sign_detached');
    $verifying = previewChannelStepsContaining($steps, 'sodium_crypto_sign_verify_detached');
    $downloading = previewChannelStepsContaining($steps, 'gh release download');

    expect($signing)->toHaveCount(1)
        ->and($verifying)->toHaveCount(1)
        ->and($downloading)->toHaveCount(1);

    $stages = [
        'signed' => implode('', $signing),
        'downloaded' => implode('', $downloading),
        're-verified' => implode('', $verifying),
    ];

    // The glob each pass would have to carry to cover a channel, rather than
    // the per-platform names: one shape covers all three suffixes.
    $unguarded = [];
    foreach (UpdateChannel::cases() as $channel) {
        $glob = $channel->manifestPrefix().'*.yml';

        foreach ($stages as $stage => $body) {
            if (! str_contains($body, $glob)) {
                $unguarded[] = $glob.' is never '.$stage;
            }
        }
    }

    expect($unguarded)->toBe([], implode("\n  ", [
        'F6 puts both channels on one chain: the manifest is signed with the project key and the',
        'application verifies that signature before it reads a hash. A channel the publish job does',
        'not sign is a channel whose manifest verifies against nothing, and one the verify job never',
        're-downloads is published on the word of the job that uploaded it.',
        'Offenders:',
        ...$unguarded,
    ]));
});
