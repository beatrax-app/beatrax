<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\SonarSourceFiles;

// Two pairing screens flashed "This code is invalid or has expired. Ask the
// other device to generate a new one" on a branch reached only by the issuing
// device answering for that very code — and one of those branches is the
// documented retry path. The line is true of exactly one ending, so it may be
// reached only through the enum that carries which ending happened.
/**
 * @link ../../.docs/features/sync/pairing-handshake.md
 */

/** @var list<string> the lines that call a pairing code unknown or expired */
const UNKNOWN_CODE_KEYS = [
    'sync::pairing.invalid_code',
    'mobile::pairing.errors.invalid_code',
];

// The only shape allowed to name one: a match arm keyed on a classified
// refusal. A bare Lang::get() names the cause without having asked anything.
const UNKNOWN_CODE_ARM = "/PairingAcceptRefusal::\\w+\\s*=>\\s*'[a-z:.]*invalid_code'/";

it('keeps the line it guards in the copy it is guarding', function (): void {
    /** @var array<string, mixed> $sync */
    $sync = require base_path('Modules/Sync/Resources/lang/en/pairing.php');

    /** @var array<string, mixed> $mobile */
    $mobile = require base_path('Modules/Mobile/Resources/lang/en/pairing.php');

    // A rename would leave the scan below hunting a string nothing writes, and
    // an empty result reads exactly like a clean tree.
    expect($sync['invalid_code'] ?? null)->toBeString()
        ->and(is_array($mobile['errors'] ?? null) ? ($mobile['errors']['invalid_code'] ?? null) : null)->toBeString();
});

it('lets no screen call a code unknown or expired without classifying the refusal', function (): void {
    $files = SonarSourceFiles::all();

    expect($files)->not->toBe([]);

    $named = 0;
    $offenders = [];

    foreach ($files as $path) {
        foreach (explode("\n", (string) file_get_contents($path)) as $index => $line) {
            $namesTheLine = false;

            foreach (UNKNOWN_CODE_KEYS as $key) {
                $namesTheLine = $namesTheLine || str_contains($line, $key);
            }

            if (! $namesTheLine) {
                continue;
            }

            $named++;

            if (! PatternScan::matches(UNKNOWN_CODE_ARM, $line)) {
                $offenders[] = str_replace(base_path().'/', '', $path).':'.($index + 1).' — '.trim($line);
            }
        }
    }

    expect($named)->toBeGreaterThanOrEqual(
        2,
        'the walk found neither client naming the line at all, which is the same answer a clean tree gives',
    );

    expect($offenders)->toBe([], 'a pairing code may be called unknown or expired only where the refusal was '
        .'classified first: the reader who is told it, and told to fetch a replacement, abandons a ceremony that '
        .'was still live on the other device. Route the line through PairingAcceptRefusal: '
        .implode(' | ', $offenders));
});
