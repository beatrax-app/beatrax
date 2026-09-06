<?php

declare(strict_types=1);

use Tests\Contracts\Support\BackendSourceFiles;

// A `Z` suffix is an assertion, not decoration: it tells the reader on the
// other side that the digits in front of it are UTC. The app clock runs at
// APP_TIMEZONE, so `->format('Y-m-d\TH:i:s\Z')` on a Clock instant ships the
// Amsterdam wall clock under a UTC label and the receiver reads it two hours
// out. Instant::zulu() converts first and asserts the shape after, so the
// label can only ever be true.
// @link ../../.docs/conventions/invariants-from-shipped-failures.md#an-instant-rendered-in-a-frame-it-was-not-produced-in

/**
 * The renderings that stamp a UTC label onto whatever digits they are given.
 * `gmdate`/`gmstrftime` are here because they are the other way to produce the
 * shape, and a second producer is how a column comes to hold two conventions.
 *
 * @return list<string>
 */
function utcLabellingCalls(): array
{
    return ['gmdate', 'gmstrftime'];
}

/**
 * Files that render a Zulu-suffixed stamp without going through the seam.
 *
 * @param  list<string>  $paths
 * @return list<string> one relative path per offender
 */
function zuluStampsRenderedPastTheSeam(array $paths): array
{
    $labelling = utcLabellingCalls();
    $offenders = [];

    foreach ($paths as $path) {
        $tokens = BackendSourceFiles::codeTokens($path);

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            $isFormatCall = $token[1] === 'format' || $token[1] === 'date';
            $isLabelling = in_array($token[1], $labelling, true);
            if (! $isFormatCall && ! $isLabelling) {
                continue;
            }

            $arguments = BackendSourceFiles::callArguments($tokens, $index);
            if ($isLabelling || str_contains($arguments, '\Z')) {
                $offenders[] = str_replace(base_path().'/', '', $path);

                break;
            }
        }
    }

    sort($offenders);

    return array_values(array_unique($offenders));
}

it('renders a Zulu stamp only through the seam that converts to UTC first', function (): void {
    $files = BackendSourceFiles::all();

    // Six thousand backend files stand behind the pin below. A run that read a
    // handful would report the seam alone and look exactly like a clean tree.
    expect(count($files))->toBeGreaterThan(
        2_000,
        'The walk read almost nothing, so the comparison below is about a tree nobody opened.',
    );

    // Shrinks only. Each entry states why it renders the shape itself.
    $pinned = [
        // The seam. It converts to UTC and then asserts the result matches the
        // Zulu shape, so this is the one place the literal may appear.
        'Modules/Core/Public/Support/Instant.php',
    ];

    expect(zuluStampsRenderedPastTheSeam($files))->toBe(
        $pinned,
        "A trailing Z asserts UTC. Clock::now() runs at APP_TIMEZONE, so a \\Z\n".
        "format on one of its instants labels an Amsterdam wall clock as UTC and\n".
        "the receiver reads it an offset out — a Graph window opened 7200s late.\n".
        'Instant::zulu() converts first. Files rendering the shape themselves:',
    );
});

it('sees a Zulu stamp rendered without a conversion, and passes the seam', function (): void {
    // Without this the walk above could match nothing at all and the guard
    // would report a clean tree it never read.
    //
    // A scratch directory rather than tempnam(): tempnam CREATES the file it
    // names, and appending `.php` names a second one, so every run left four
    // empty seeds behind in the shared temp directory.
    $scratch = sys_get_temp_dir().'/zulu-seam-'.bin2hex(random_bytes(6));
    mkdir($scratch, 0o700, true);

    $planted = $scratch.'/PlantedZuluWrites.php';
    file_put_contents($planted, <<<'PHP'
        <?php
        final class PlantedZuluWrites
        {
            public function windowStart(): string
            {
                return $this->clock->now()->toDateTimeImmutable()->format('Y-m-d\TH:i:s\Z');
            }
        }
        PHP);

    $labelled = $scratch.'/PlantedGmdateWrites.php';
    file_put_contents($labelled, <<<'PHP'
        <?php
        final class PlantedGmdateWrites
        {
            public function stamp(int $seconds): string
            {
                return gmdate('Y-m-d\TH:i:s\Z', $seconds);
            }
        }
        PHP);

    $clean = $scratch.'/PlantedCleanWrites.php';
    file_put_contents($clean, <<<'PHP'
        <?php
        final class PlantedCleanWrites
        {
            public function windowStart(): string
            {
                return Instant::zulu($this->clock->now());
            }
        }
        PHP);

    $unrelated = $scratch.'/PlantedUnrelatedWrites.php';
    file_put_contents($unrelated, <<<'PHP'
        <?php
        final class PlantedUnrelatedWrites
        {
            public function day(): string
            {
                return $this->clock->now()->format('Y-m-d');
            }
        }
        PHP);

    try {
        $found = zuluStampsRenderedPastTheSeam([$planted, $labelled, $clean, $unrelated]);
    } finally {
        foreach ([$planted, $labelled, $clean, $unrelated] as $path) {
            unlink($path);
        }

        rmdir($scratch);
    }

    $names = array_map(static fn (string $path): string => basename($path), $found);
    sort($names);

    $expected = [basename($planted), basename($labelled)];
    sort($expected);

    expect($names)->toBe($expected, implode("\n  ", [
        'The reader has to see both a \\Z format written on an unconverted instant and a gmdate() that',
        'stamps the same label, and see neither the seam call nor an unrelated format.',
    ]));
});
