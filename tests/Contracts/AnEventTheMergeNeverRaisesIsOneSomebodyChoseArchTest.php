<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\BackendSourceFiles;

/**
 * @link ../../.docs/features/sync/op-log-merge-rules.md#what-an-arriving-row-announces
 */

// A row a peer wrote reaches this database through the query builder, so no
// model event fires and no domain event is raised. Every listener that keeps
// derived state or enforces a cross-row rule is therefore skipped on arrival,
// and the two devices disagree with nothing anywhere reporting it.
//
// "Raise all of them on the applier path" is false: an unlock happened HERE, a
// notification the origin already delivered would ring twice, and the capture
// events would send the peer's own row straight back to it. So the rule this
// guard can hold is the weaker true one — each of them is a decision somebody
// made, written down, and re-checked where a machine can re-check it.

/**
 * Every event under a module's `Events` namespace that a service provider
 * names. Over-collecting is deliberate: a provider that mentions an event
 * class is a provider wiring something to it, and a spurious entry costs one
 * pinned line while a missed one costs the guard.
 *
 * @return array<string, list<string>> event FQCN => the providers naming it
 */
function eventsAProviderWiresUp(): array
{
    $found = [];

    foreach (BackendSourceFiles::all() as $path) {
        if (! str_ends_with($path, 'ServiceProvider.php')) {
            continue;
        }

        foreach (eventNamesIn($path) as $event) {
            $found[$event][] = basename($path);
        }
    }

    ksort($found);

    return array_map(static fn (array $files): array => array_values(array_unique($files)), $found);
}

/**
 * Both spellings a provider reaches an event by: an imported `X::class`, and
 * the fully-qualified string a class-string constant holds when the module
 * declaring the event may be absent from the build.
 *
 * @return list<string>
 */
function eventNamesIn(string $path): array
{
    $source = PatternScan::replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($path));

    $imports = [];

    foreach (PatternScan::all('/^use\s+([A-Za-z0-9_\\\\]+);/m', $source)[1] as $imported) {
        $imports[substr($imported, (int) strrpos($imported, '\\') + 1)] = $imported;
    }

    $events = [];

    foreach (PatternScan::all('/([A-Za-z_][A-Za-z0-9_]*)::class/', $source)[1] as $short) {
        $fqcn = $imports[$short] ?? null;

        if ($fqcn !== null && isAnEventClassName($fqcn)) {
            $events[] = $fqcn;
        }
    }

    foreach (PatternScan::all('/[\'"](Modules\\\\[A-Za-z]+\\\\(?:Public|Internal)\\\\Events\\\\[A-Za-z0-9_]+)[\'"]/', $source)[1] as $literal) {
        $events[] = $literal;
    }

    return array_values(array_unique($events));
}

function isAnEventClassName(string $fqcn): bool
{
    return PatternScan::matches('#^Modules\\\\[A-Za-z]+\\\\(?:Public|Internal)\\\\Events\\\\#', $fqcn);
}

/**
 * The events the two appliers and their collaborators construct. This is the
 * whole arrival path: OpLogEntryApplier writes the rows, PairingFrameApplier
 * applies a handshake frame, and OpLogReplayer drives both.
 *
 * @return list<string>
 */
function eventsTheMergeRaises(): array
{
    $raised = [];

    foreach (BackendSourceFiles::all() as $path) {
        if (! str_contains($path, '/Modules/Sync/Internal/Merge/') && ! str_contains($path, '/Modules/Sync/Internal/Pairing/')) {
            continue;
        }

        $source = PatternScan::replace('#/\*.*?\*/|//[^\n]*#s', '', (string) file_get_contents($path));

        $imports = [];

        foreach (PatternScan::all('/^use\s+([A-Za-z0-9_\\\\]+);/m', $source)[1] as $imported) {
            $imports[substr($imported, (int) strrpos($imported, '\\') + 1)] = $imported;
        }

        foreach (PatternScan::all('/new\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source)[1] as $short) {
            $fqcn = $imports[$short] ?? null;

            if ($fqcn !== null && isAnEventClassName($fqcn)) {
                $raised[] = $fqcn;
            }
        }
    }

    $raised = array_values(array_unique($raised));
    sort($raised);

    return $raised;
}

/**
 * Why an event stops at the device that raised it, in three shapes:
 *
 *   local    — it describes something that happened on THIS device, so there
 *              is nothing for an arriving row to have caused.
 *   delivery — the origin device already told the reader, and the row it wrote
 *              travels; raising it again notifies one household twice.
 *   covered  — the arriving row DOES have to be answered, and the merge path is
 *              not where the answer belongs: another seam owns it. `proves`
 *              names the file and the pattern that says so, re-run below, so
 *              the claim fails here when it stops holding. It says nothing
 *              about whether that other seam is itself whole.
 *
 * @var array<string, array{why: string, reason: string, proves?: array{0: string, 1: string}}>
 */
const AN_EVENT_THE_MERGE_NEVER_RAISES = [
    'Modules\Auth\Public\Events\AppLockPassphraseChanged' => [
        'why' => 'local',
        'reason' => 'the passphrase is a device-local column; a peer keeps its own and re-wraps nothing of ours',
    ],
    'Modules\Auth\Public\Events\AppLockUnlocked' => [
        'why' => 'local',
        'reason' => 'the reader unlocked THIS device; no row can arrive that unlocked it',
    ],
    'Modules\Budgets\Public\Events\BudgetThresholdCrossed' => [
        'why' => 'delivery',
        'reason' => 'a notification trigger: the origin raised it and the notification row travels',
    ],
    'Modules\Categorization\Public\Events\TransactionCategorized' => [
        'why' => 'covered',
        'reason' => 'MerchantMemoryWriter counts an occurrence, and merchants + merchant_memories both travel — re-running it on arrival would count the peer\'s categorization twice',
        'proves' => ['Modules/Sync/Internal/Config/MergeRulesRegistry.php', "/'merchant_memories' => \\[/"],
    ],
    'Modules\Core\Public\Events\UpdateInstallRequested' => [
        'why' => 'local',
        'reason' => 'the reader asked THIS device to install a build',
    ],
    'Modules\Core\Public\Events\UserCountryChanged' => [
        'why' => 'local',
        'reason' => 'users.country_code is asked of every joiner and travels to nobody',
        'proves' => ['Modules/Sync/Internal/Config/MergeRulesRegistry.php', '/ASKED_OF_EVERY_JOINER/'],
    ],
    'Modules\Core\Public\Events\UserInstalled' => [
        'why' => 'local',
        'reason' => 'an install is a device\'s own first boot, and the merge never mints the reader',
    ],
    'Modules\Desktop\Public\Events\FileOpenedFromOs' => [
        'why' => 'local',
        'reason' => 'the operating system handed a file to this process',
    ],
    'Modules\Desktop\Public\Events\NotificationDeepLink' => [
        'why' => 'local',
        'reason' => 'the reader clicked a notification on this device',
    ],
    'Modules\DriftAlerts\Public\Events\DriftAlertDismissedCancelled' => [
        'why' => 'covered',
        'reason' => 'the forecast it invalidates is re-projected from PeerRowsApplied, which lists drift_alerts',
        'proves' => ['Modules/Forecasting/Internal/Listeners/ProjectForecastOnPeerRowsApplied.php', "/'drift_alerts'/"],
    ],
    'Modules\DriftAlerts\Public\Events\DriftAlertOpened' => [
        'why' => 'delivery',
        'reason' => 'a notification trigger: the device that opened the alert notified its reader',
    ],
    'Modules\DriftAlerts\Public\Events\SavingsPromptDue' => [
        'why' => 'local',
        'reason' => 'raised by this device\'s own scheduled sweep, from rows it already holds',
    ],
    'Modules\EmailScan\Public\Events\IcsStatementReady' => [
        'why' => 'delivery',
        'reason' => 'a notification trigger for a mailbox scan this device ran',
    ],
    'Modules\EmailScan\Public\Events\InboxTokenFailed' => [
        'why' => 'local',
        'reason' => 'this device\'s OAuth token failed; inbox rows never leave the device that holds them',
    ],
    'Modules\Forecasting\Public\Events\ForecastShortfallDetected' => [
        'why' => 'local',
        'reason' => 'the projection is device-local, so each device detects its own shortfall from its own run',
    ],
    'Modules\Forecasting\Public\Events\ScenarioCreated' => [
        'why' => 'covered',
        'reason' => 'an arriving scenario re-projects through PeerRowsApplied, which lists forecast_scenarios',
        'proves' => ['Modules/Forecasting/Internal/Listeners/ProjectForecastOnPeerRowsApplied.php', "/'forecast_scenarios'/"],
    ],
    'Modules\Forecasting\Public\Events\ScenarioDeleted' => [
        'why' => 'covered',
        'reason' => 'an arriving scenario tombstone re-projects through PeerRowsApplied',
        'proves' => ['Modules/Forecasting/Internal/Listeners/ProjectForecastOnPeerRowsApplied.php', "/'forecast_scenarios'/"],
    ],
    'Modules\Forecasting\Public\Events\ScenarioMutated' => [
        'why' => 'covered',
        'reason' => 'an arriving scenario mutation re-projects through PeerRowsApplied',
        'proves' => ['Modules/Forecasting/Internal/Listeners/ProjectForecastOnPeerRowsApplied.php', "/'forecast_scenario_mutations'/"],
    ],
    'Modules\Import\Public\Events\TransactionImported' => [
        'why' => 'covered',
        'reason' => 'none of its three listeners wants re-running on arrival: SearchDocumentRows rebuilds the index here, a receipt\'s chain_links row travels, and transfer pairing rides the whole-row create op — where pairing has a hole it is on the capture side, which re-raising this would not reach',
        'proves' => ['Modules/Sync/Internal/Merge/SearchIndexRefresher.php', '/upsertForTransaction/'],
    ],
    'Modules\Ledger\Public\Events\TransactionBatchImported' => [
        'why' => 'delivery',
        'reason' => 'a notification trigger for an import run this device performed',
    ],
    'Modules\Notifications\Public\Events\NotificationDeliverable' => [
        'why' => 'delivery',
        'reason' => 'the origin device already showed it and the notifications row travels, so raising it here rings one household twice',
    ],
    'Modules\Notifications\Public\Events\NotificationPreferenceMutated' => [
        'why' => 'local',
        'reason' => 'a capture event: raising it on arrival re-authors the peer\'s row as ours and sends it back',
    ],
    'Modules\OpenBanking\Internal\Events\OpenBankingConsentFailed' => [
        'why' => 'local',
        'reason' => 'the consent belongs to a connector session on this device',
    ],
    'Modules\OpenBanking\Internal\Events\OpenBankingImportedNothing' => [
        'why' => 'local',
        'reason' => 'an import run this device made, which found nothing',
    ],
    'Modules\Position\Public\Events\PositionDigestDue' => [
        'why' => 'local',
        'reason' => 'raised by this device\'s own scheduled digest pass',
    ],
    'Modules\Receipts\Public\Events\ChainHintDetected' => [
        'why' => 'covered',
        'reason' => 'the chain_links row the hint creates travels, so a peer receives the link rather than re-deriving it',
        'proves' => ['Modules/Sync/Internal/Config/MergeRulesRegistry.php', "/'chain_links' => \\[/"],
    ],
    'Modules\Recurring\Public\Events\PaymentReminderDue' => [
        'why' => 'delivery',
        'reason' => 'a notification trigger raised by this device\'s reminder sweep',
    ],
    'Modules\Recurring\Public\Events\PaymentSettled' => [
        'why' => 'delivery',
        'reason' => 'it resolves a reminder the origin device raised, and the notification row travels',
    ],
    'Modules\Recurring\Public\Events\RecurringSeriesApproved' => [
        'why' => 'covered',
        'reason' => 'an arriving approval re-projects through PeerRowsApplied, which lists recurring_series',
        'proves' => ['Modules/Forecasting/Internal/Listeners/ProjectForecastOnPeerRowsApplied.php', "/'recurring_series'/"],
    ],
    'Modules\Recurring\Public\Events\RecurringSeriesCadenceFlipped' => [
        'why' => 'covered',
        'reason' => 'an arriving cadence flip re-projects through PeerRowsApplied',
        'proves' => ['Modules/Forecasting/Internal/Listeners/ProjectForecastOnPeerRowsApplied.php', "/'recurring_series'/"],
    ],
    'Modules\Recurring\Public\Events\RecurringSeriesMetricsRefreshed' => [
        'why' => 'covered',
        'reason' => 'the forecast half re-projects through PeerRowsApplied; the drift half writes drift_alerts, which travels from the device that detected it',
        'proves' => ['Modules/Forecasting/Internal/Listeners/ProjectForecastOnPeerRowsApplied.php', "/'recurring_series'/"],
    ],
    'Modules\Recurring\Public\Events\RecurringSeriesRejected' => [
        'why' => 'covered',
        'reason' => 'an arriving rejection re-projects through PeerRowsApplied',
        'proves' => ['Modules/Forecasting/Internal/Listeners/ProjectForecastOnPeerRowsApplied.php', "/'recurring_series'/"],
    ],
    'Modules\Sync\Public\Events\DeviceSyncEnabled' => [
        'why' => 'local',
        'reason' => 'this device turned sync on, which is what starts the arrival path rather than something on it',
    ],
    'Modules\Sync\Public\Events\EntityMutated' => [
        'why' => 'covered',
        'reason' => 'the capture event: raising it on arrival would re-author the peer\'s row as ours and send it back, so the invariant listener that rode on it hears PeerRowsApplied instead',
        'proves' => ['Modules/Categorization/Providers/CategorizationServiceProvider.php', '/PeerRowsApplied::class/'],
    ],
    'Modules\Sync\Public\Events\EnvelopeAssignmentMutated' => [
        'why' => 'local',
        'reason' => 'a capture event: raising it on arrival re-authors the peer\'s row as ours and sends it back',
    ],
    'Modules\Sync\Public\Events\EnvelopeMoveMutated' => [
        'why' => 'local',
        'reason' => 'a capture event: raising it on arrival re-authors the peer\'s row as ours and sends it back',
    ],
    'Modules\Sync\Public\Events\EnvelopeSettingMutated' => [
        'why' => 'local',
        'reason' => 'a capture event: raising it on arrival re-authors the peer\'s row as ours and sends it back',
    ],
    'Modules\Sync\Public\Events\GoalContributionMutated' => [
        'why' => 'local',
        'reason' => 'a capture event: raising it on arrival re-authors the peer\'s row as ours and sends it back',
    ],
    'Modules\Sync\Public\Events\GoalMutated' => [
        'why' => 'local',
        'reason' => 'a capture event: raising it on arrival re-authors the peer\'s row as ours and sends it back',
    ],
    'Modules\Sync\Public\Events\NotificationMutated' => [
        'why' => 'local',
        'reason' => 'a capture event: raising it on arrival re-authors the peer\'s row as ours and sends it back',
    ],
    'Modules\Sync\Public\Events\SavedReportMutated' => [
        'why' => 'local',
        'reason' => 'a capture event: raising it on arrival re-authors the peer\'s row as ours and sends it back',
    ],
    'Modules\Sync\Public\Events\SyncTransportCredentialsAvailable' => [
        'why' => 'local',
        'reason' => 'this device\'s own transport credentials became readable',
    ],
    'Modules\Sync\Public\Events\TransactionMutated' => [
        'why' => 'local',
        'reason' => 'a capture event: raising it on arrival re-authors the peer\'s row as ours and sends it back',
    ],
    'Modules\Sync\Public\Events\TransactionSplitMutated' => [
        'why' => 'local',
        'reason' => 'a capture event: raising it on arrival re-authors the peer\'s row as ours and sends it back',
    ],
    'Modules\Tax\Public\Events\TransactionTagged' => [
        'why' => 'covered',
        'reason' => 'its listener only forgets the nav-count cache, and ForgetNavCountsOnWrite reads the merge\'s own statement to do the same',
        'proves' => ['Modules/Core/Public/Services/NavCountsService.php', "/'tax_transaction_tags'/"],
    ],
    'Modules\Tax\Public\Events\TransactionUntagged' => [
        'why' => 'covered',
        'reason' => 'its listener only forgets the nav-count cache, and ForgetNavCountsOnWrite reads the merge\'s own statement to do the same',
        'proves' => ['Modules/Core/Public/Services/NavCountsService.php', "/'tax_transaction_tags'/"],
    ],
];

/** @var list<string> The three shapes a pin may claim. */
const AN_EVENT_THE_MERGE_NEVER_RAISES_SHAPES = ['local', 'delivery', 'covered'];

it('leaves every listened-for event either raised by the merge or pinned with a reason', function (): void {
    $wired = eventsAProviderWiresUp();
    $raised = eventsTheMergeRaises();

    // A walk that stopped reading would agree with an empty pin list.
    expect(count($wired))->toBeGreaterThan(30, 'no provider wirings were found — the walk read an empty tree');
    expect($raised)->not->toBe([], 'the merge raises no event at all — the arrival path announces nothing');

    $unraised = array_values(array_diff(array_keys($wired), $raised));
    sort($unraised);

    $pinned = array_keys(AN_EVENT_THE_MERGE_NEVER_RAISES);
    sort($pinned);

    expect($unraised)->toBe($pinned, implode("\n", [
        'A listener was wired to an event the merge never raises, or a pinned event',
        'lost its last listener. Compared in both directions on purpose: a pin that',
        'no longer describes anything is as wrong as a missing one.',
        '',
        'If the listener keeps derived state or enforces a rule across rows, the fix',
        'is to hear PeerRowsApplied as well — see ProjectForecastOnPeerRowsApplied.',
        'If it does not, add the event to AN_EVENT_THE_MERGE_NEVER_RAISES with the',
        'shape and the one line saying why.',
        '',
        'wired but never raised: '.implode(', ', $unraised),
        'pinned: '.implode(', ', $pinned),
    ]));
});

it('re-runs the pattern behind every pin that claims another seam covers it', function (): void {
    $stale = [];
    $checked = 0;

    foreach (AN_EVENT_THE_MERGE_NEVER_RAISES as $event => $pin) {
        expect(in_array($pin['why'], AN_EVENT_THE_MERGE_NEVER_RAISES_SHAPES, true))
            ->toBeTrue($event.' claims a shape that is not one of '.implode(', ', AN_EVENT_THE_MERGE_NEVER_RAISES_SHAPES));

        if (! isset($pin['proves'])) {
            expect($pin['why'])->not->toBe('covered', $event.' claims another seam covers it and names nothing that says so');

            continue;
        }

        [$file, $pattern] = $pin['proves'];
        $path = base_path($file);
        $checked++;

        if (! is_file($path) || ! PatternScan::matches($pattern, (string) file_get_contents($path))) {
            $stale[] = $event.' → '.$file.' no longer matches '.$pattern;
        }
    }

    expect($checked)->toBeGreaterThan(5, 'no pin was re-checked — the walk found no proves entry');

    expect($stale)->toBe([], implode("\n", [
        'These pins name the seam that answers an arriving row, and that seam has',
        'moved. The reason is prose for the reader; the pattern is the half a test',
        'can hold, and it stopped holding:',
        ...$stale,
    ]));
});

it('finds the event the merge does raise, so neither half of the walk is simply empty', function (): void {
    expect(eventsTheMergeRaises())->toContain('Modules\Sync\Public\Events\PeerRowsApplied')
        ->and(array_keys(eventsAProviderWiresUp()))->toContain('Modules\Sync\Public\Events\PeerRowsApplied')
        ->and(AN_EVENT_THE_MERGE_NEVER_RAISES)->not->toHaveKey('Modules\Sync\Public\Events\PeerRowsApplied');
});
