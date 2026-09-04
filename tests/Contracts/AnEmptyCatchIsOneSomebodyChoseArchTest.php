<?php

declare(strict_types=1);

use Tests\Contracts\Support\BackendSourceFiles;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-catch-body-that-says-nothing
 */

// The shape behind most of the defects this tree has shipped: a failure caught
// and turned into nothing at all. A capture listener dropped 4,925 mutations, a
// merge strategy returned an empty set two devices then disagreed over, and a
// CLI reset stranded the app-lock recovery wrap — each one a catch whose body
// said nothing, in a path where silence and success look identical.

// Tolerating a failure is often right here, and every entry below is a case
// where it is. What must not happen is a NEW one arriving unnoticed, so the
// twenty-odd that exist are named with the reason they are correct and
// anything else is a build failure.
/**
 * @return array<string, array{count: int, why: string}>
 */
function catchBodiesLeftEmptyOnPurpose(): array
{
    return [
        'Modules/Anomaly/Internal/Jobs/ReviveExpiredAnomalySnoozesJob.php' => [
            'count' => 1,
            'why' => 'The sweep re-reads the row and checks its state under the lock, so the transition exception IS the lost race and skipping is the answer.',
        ],
        'Modules/Anomaly/Public/Http/Livewire/AnomalySettingsSection.php' => [
            'count' => 1,
            'why' => 'A rule that vanished between render and click is already in the state the click asked for.',
        ],
        'Modules/Auth/Internal/Lock/WebAuthnBiometricService.php' => [
            'count' => 1,
            'why' => 'One base64 variant of a credential id failing is how the next variant gets tried; the caller answers for all of them.',
        ],
        'Modules/Core/Internal/Console/Probes/BackupFreshnessProbe.php' => [
            'count' => 1,
            'why' => 'The probe result carries the verdict; the alert write is the second channel and must not take the first one down with it.',
        ],
        'Modules/Core/Public/Http/Livewire/SystemAlertsBanner.php' => [
            'count' => 1,
            'why' => 'The banner is mounted on every page, so two tabs dismiss one alert and the second click names a row already retired.',
        ],
        'Modules/DevMode/Internal/Actions/SettleFinishedRun.php' => [
            'count' => 1,
            'why' => 'It wraps the logger call that IS the report of a failure; nothing is left to try, and the only other channel is a live SSE stream a rethrow would kill.',
        ],
        'Modules/DevMode/Internal/Audit/SpatieAuditWriter.php' => [
            'count' => 1,
            'why' => 'A console or queue caller has no authenticated user; the audit row is written without a causer rather than dropped.',
        ],
        'Modules/DevMode/Internal/Logging/LogFileStats.php' => [
            'count' => 1,
            'why' => 'A mid-rotation filesystem state is transient, and the counts read so far beat a hard error on a developer dashboard.',
        ],
        'Modules/DevMode/Internal/Services/OAuthScrubSet.php' => [
            'count' => 1,
            'why' => 'The alert write runs inside a logger call, so raising there would crash every request that emits a log line.',
        ],
        'Modules/EmailScan/Internal/Http/Livewire/InboxesPage.php' => [
            'count' => 1,
            'why' => 'A concurrent acknowledge from another surface retires the row between the id list and this call.',
        ],
        'Modules/EmailScan/Internal/Jobs/IncrementalScanJob.php' => [
            'count' => 1,
            'why' => 'Runs in failed(); an invalid status transition there must not escalate into a hard queue-worker error, and the hourly schedule re-enters.',
        ],
        'Modules/Forecasting/Internal/Http/Livewire/ScenarioEditorSidebar.php' => [
            'count' => 2,
            'why' => 'A concurrent removal and a stale id reach the same asked-for outcome, and the refresh and toast below still have to run.',
        ],
        'Modules/Import/Internal/Http/Livewire/RenameCounterpartyPopover.php' => [
            'count' => 2,
            'why' => 'The alias the user asked for already persisted; the rule is an undisclosed convenience whose failure reports against a field this form does not render.',
        ],
        'Modules/Migration/Internal/Http/Livewire/MigrationResults.php' => [
            'count' => 1,
            'why' => 'A discarded run reaching this page directly has its staging already truncated, so the unmapped section is omitted rather than errored.',
        ],
        'Modules/Onboarding/Internal/Http/Livewire/Steps/BudgetsStep.php' => [
            'count' => 1,
            'why' => 'A category the user does not own can only arrive in a tampered payload, and the wizard refuses it rather than reporting it.',
        ],
        'Modules/Sync/Internal/Crypto/GdkKeyringService.php' => [
            'count' => 1,
            'why' => 'A keyring left at the old KDF cost is read correctly; the next write upgrades it, so a failed rewrite costs nothing but time.',
        ],
        'Modules/Sync/Internal/Crypto/RewrapGdkOnPassphraseChange.php' => [
            'count' => 1,
            'why' => 'Last resort around a SystemAlert write: propagating would re-break a passphrase change that has already committed.',
        ],
        'Modules/Sync/Internal/Http/Livewire/PairingFlowModal.php' => [
            'count' => 1,
            'why' => 'Nothing in the tail after a completed pairing may undo it, and the steps it guards are all best-effort follow-ups.',
        ],
        'Modules/Sync/Internal/Merge/OpLogQuarantine.php' => [
            'count' => 1,
            'why' => 'The quarantine row is the audit of a refusal; replay must continue whether or not that audit lands.',
        ],
        'Modules/Sync/Internal/Merge/SearchIndexRefresher.php' => [
            'count' => 1,
            'why' => 'FTS freshness runs outside the merge transaction by construction, and an index hiccup must never break merge determinism.',
        ],
        'Modules/Sync/Internal/Merge/SelfReferenceDeferral.php' => [
            'count' => 1,
            'why' => 'The self-referential link is optional: the row is applied and usable without it, so a refusal costs the link and never the replay.',
        ],
        'Modules/Sync/Internal/OpLog/OpLogRebuilder.php' => [
            'count' => 1,
            'why' => 'One unindexable row must not stop the rest being indexed — a stale index recovers, a half-indexed sweep does not.',
        ],
        'Modules/Sync/Internal/Support/DevicesScreenOpening.php' => [
            'count' => 1,
            'why' => 'The recovery markers are left where they were so the next open retries; what this must not do is turn the devices screen into a 500.',
        ],
    ];
}

// A Blade file carries no opening PHP tag, so token_get_all reads the whole of
// it as inline HTML and a scan built on that alone cannot see the PHP inside an
// @php block. 151 files here have one. The blocks are lifted into a synthetic
// source so this guard reads them like any other file.
/**
 * @return list<array{0:int,1:string,2:int}|string>
 */
function catchScanTokens(string $path): array
{
    if (! str_ends_with($path, '.blade.php')) {
        return BackendSourceFiles::codeTokens($path);
    }

    $blocks = [];

    // preg_match_all answers false on a backtrack or recursion limit, and a
    // guard that reads that as "no blocks here" reports a clean tree it never
    // scanned. It is the failure this whole guard is about, one level up.
    if (preg_match_all('/@php\b(?!\s*\()(.*?)@endphp/s', (string) file_get_contents($path), $blocks) === false) {
        throw new RuntimeException('Could not read the @php blocks of '.$path.': '.preg_last_error_msg());
    }

    return array_values(array_filter(
        token_get_all('<?php '.implode(";\n", $blocks[1])),
        static fn (array|string $token): bool => ! is_array($token) || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true),
    ));
}

// Comments are stripped before the scan, so a body holding only prose is read
// as the empty body it is — that is the spelling every one of the twenty-five
// already uses, and a guard fooled by it would report a clean tree.
/**
 * @return array<string, int> path relative to the repo root => empty catch bodies in it
 */
function emptyCatchBodiesByFile(): array
{
    $counts = [];

    foreach (BackendSourceFiles::all() as $path) {
        $tokens = catchScanTokens($path);
        $found = 0;

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_CATCH) {
                continue;
            }

            if (catchBodyIsEmpty($tokens, $index)) {
                $found++;
            }
        }

        if ($found > 0) {
            $counts[str_replace(base_path().'/', '', $path)] = $found;
        }
    }

    ksort($counts);

    return $counts;
}

// Walks the catch's own parentheses to their match before looking for the
// brace, so a caught type spelled with a namespace separator or a union never
// puts the scan on the wrong token.
/**
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 */
function catchBodyIsEmpty(array $tokens, int $catchIndex): bool
{
    $count = count($tokens);
    $index = $catchIndex + 1;
    $depth = 0;

    for (; $index < $count; $index++) {
        $text = is_array($tokens[$index]) ? $tokens[$index][1] : $tokens[$index];

        if ($text === '(') {
            $depth++;
        } elseif ($text === ')') {
            $depth--;

            if ($depth === 0) {
                break;
            }
        }
    }

    $brace = nextSignificantToken($tokens, $index + 1);

    if ($brace === null || (is_array($tokens[$brace]) ? $tokens[$brace][1] : $tokens[$brace]) !== '{') {
        return false;
    }

    $body = nextSignificantToken($tokens, $brace + 1);

    return $body !== null && (is_array($tokens[$body]) ? $tokens[$body][1] : $tokens[$body]) === '}';
}

/**
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 */
function nextSignificantToken(array $tokens, int $from): ?int
{
    for ($index = $from, $count = count($tokens); $index < $count; $index++) {
        $token = $tokens[$index];

        if (is_array($token) && $token[0] === T_WHITESPACE) {
            continue;
        }

        return $index;
    }

    return null;
}

it('leaves no catch body empty that is not declared with its reason', function (): void {
    $found = emptyCatchBodiesByFile();
    $declared = catchBodiesLeftEmptyOnPurpose();

    expect($found)->not->toBe([]);

    $offenders = [];

    foreach ($found as $file => $count) {
        $entry = $declared[$file] ?? null;

        if ($entry === null) {
            $offenders[] = "{$file} — {$count} catch body/bodies with nothing in them, and no entry saying why that is correct.";

            continue;
        }

        if ($entry['count'] !== $count) {
            $offenders[] = "{$file} — declares {$entry['count']} deliberately empty catch body/bodies but holds {$count}.";
        }
    }

    expect($offenders)->toBe([]);
});

// An entry that outlives the catch it excused is the way a pinned list rots
// into a list of names nobody checks, so a stale one fails as loudly as a new
// offender does.
it('leaves no declared entry that no longer names an empty catch', function (): void {
    $found = emptyCatchBodiesByFile();
    $stale = [];

    foreach (catchBodiesLeftEmptyOnPurpose() as $file => $entry) {
        if (! isset($found[$file])) {
            $stale[] = "{$file} — declared as deliberately empty, but holds no empty catch body. Remove the entry.";
        }
    }

    expect($stale)->toBe([]);
});

// The scan above is only worth its result if it still reads what it claims to.
// A Blade @php block holds exactly one PHP catch in this tree; finding it is
// how this guard proves it has not gone blind to the 151 files that could hold
// the next one.
it('still reads the PHP inside a Blade template', function (): void {
    $tokens = catchScanTokens(base_path('Modules/Forecasting/Resources/views/livewire/forecast-highlights-tile.blade.php'));

    $catches = array_filter($tokens, static fn (array|string $token): bool => is_array($token) && $token[0] === T_CATCH);

    expect($catches)->not->toBe([]);
});
