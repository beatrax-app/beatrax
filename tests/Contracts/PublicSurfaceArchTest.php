<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

/**
 * @link ../../.docs/architecture/module-boundaries.md
 */

// A module's Public\ namespace is its cross-module contract surface and
// Internal\ is private to it. A Public class that nothing outside its own
// module names is not a contract, and once enough of them collect the split
// stops telling a reviewer which is which. The list below is the residue left
// when this rule landed: it may shrink, never grow.
//
// Public\Http\Livewire\ is deliberately out of scope. A component is mounted
// by registered alias from a Blade view, so no file names the class and this
// scan could only ever call it dead; that edge is pinned by
// pinnedCrossModuleLivewireMounts in BoundaryArchTest instead.

/**
 * @return array<string, string> repo-relative path => contents
 */
function publicSurfaceSources(): array
{
    // mobile-app/ is a second composer root whose Modules/ is a symlink onto
    // this tree, so resolving it gives the one root both roots agree on.
    $repoRoot = dirname((string) realpath(base_path('Modules')));

    $sources = [];
    // `nativephp` is not in this list: it is a gitignored build output, absent
    // here and absent in CI, so it was a covered root naming nothing — and in a
    // built tree it would answer for a Public class with a copy of the app.
    foreach (['Modules', 'app', 'routes', 'config', 'tests', 'database', 'bootstrap', 'resources', 'lang', 'mobile-app', 'scripts'] as $directory) {
        if (! is_dir($repoRoot.'/'.$directory)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($repoRoot.'/'.$directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $relative = str_replace($repoRoot.'/', '', $file->getPathname());
            if (str_contains($relative, '/vendor/') || str_contains($relative, '/node_modules/') || str_contains($relative, '/storage/')) {
                continue;
            }
            // Markdown is excluded on purpose: a page describing a class is not
            // a caller, and counting prose would keep dead contracts alive.
            if (! in_array(strtolower($file->getExtension()), ['php', 'neon', 'json', 'xml', 'yaml', 'yml'], true)) {
                continue;
            }
            $sources[$relative] = (string) file_get_contents($file->getPathname());
        }
    }

    return $sources;
}

it('does not allow a Public class without a consumer outside its own module (pinnedUnconsumedPublicClasses)', function (): void {
    // Consumption is measured by fully-qualified name, in any file the app
    // ships or tests with — a `use`, an inline `\Modules\...::class`, a
    // container binding, a config array, a Blade view, a phpstan.neon path.
    // A consumer that names the class some other way is invisible here and
    // belongs on the list with its reason written above the line; every entry
    // seeded below was checked for one and has none, so the list as it stands
    // is dead surface rather than exemptions.
    $pinnedUnconsumed = [
        'Modules/Anomaly/Public/Dto/AnomalyAlertDto.php',
        'Modules/Anomaly/Public/Dto/AnomalySuppressionRuleDto.php',
        'Modules/Anomaly/Public/Enums/AnomalyAlertState.php',
        'Modules/Anomaly/Public/Events/AnomalyAlertAcknowledged.php',
        'Modules/Anomaly/Public/Events/AnomalyAlertDismissed.php',
        'Modules/Anomaly/Public/Events/AnomalyAlertOpened.php',
        'Modules/Anomaly/Public/Events/AnomalyAlertSnoozed.php',
        'Modules/Anomaly/Public/Services/AnomalySuppressionRuleQuery.php',
        'Modules/Auth/Public/Actions/AddUserAction.php',
        'Modules/Auth/Public/Actions/LoginAction.php',
        'Modules/Auth/Public/Actions/RegenerateRecoveryCodesAction.php',
        'Modules/Auth/Public/Actions/ResetPasswordAction.php',
        'Modules/Budgets/Public/Dto/EnvelopeMoveRow.php',
        'Modules/Budgets/Public/Dto/EnvelopeRow.php',
        // The declared type of EnvelopeMoveRow::$kind. That DTO is Public and
        // a Public class may not expose an Internal type, so the enum lives
        // here even though the vocabulary is read and written inside Budgets.
        'Modules/Budgets/Public/Enums/EnvelopeMoveKind.php',
        'Modules/Budgets/Public/Enums/OverspendMode.php',
        'Modules/Budgets/Public/Services/EnvelopeBalanceQuery.php',
        'Modules/Categorization/Public/Actions/Concerns/NormalisesRuleInput.php',
        'Modules/Categorization/Public/Actions/DeleteCategorizationRule.php',
        'Modules/Categorization/Public/Actions/UpdateCategorizationRule.php',
        'Modules/Categorization/Public/Dto/AutoCategorizationOutcomeDto.php',
        'Modules/Categorization/Public/Dto/CategorizationRuleDto.php',
        'Modules/Categorization/Public/Dto/CategoryOption.php',
        'Modules/Categorization/Public/Dto/MerchantMemoryDto.php',
        'Modules/Categorization/Public/Dto/RuleActionDto.php',
        'Modules/Categorization/Public/Dto/RuleConditionDto.php',
        'Modules/Categorization/Public/Dto/TriageBatch.php',
        'Modules/Categorization/Public/Dto/TriageRow.php',
        'Modules/Categorization/Public/Enums/ActionType.php',
        'Modules/Categorization/Public/Enums/ConditionOperator.php',
        'Modules/Categorization/Public/Enums/ConditionValueType.php',
        'Modules/Categorization/Public/Enums/RuleCombinator.php',
        'Modules/Categorization/Public/Services/CategorizationRuleQuery.php',
        'Modules/Categorization/Public/Services/UncategorizedTriageQuery.php',
        'Modules/Chains/Public/Actions/ConfirmChainLink.php',
        'Modules/Chains/Public/Actions/RejectChainLink.php',
        'Modules/Chains/Public/Dto/ChainLinkHintRow.php',
        'Modules/Chains/Public/Dto/ChainLinkRow.php',
        'Modules/Chains/Public/Dto/ChainTree.php',
        'Modules/Chains/Public/Dto/ChainTreeNode.php',
        'Modules/Chains/Public/Dto/SeriesFunderLink.php',
        'Modules/Chains/Public/Dto/StatementSettlement.php',
        'Modules/Chains/Public/Enums/CardStatementCreditReason.php',
        // The declared type of ChainTreeNode::$confidenceTier. That DTO is
        // Public and a Public class may not expose an Internal type, so the
        // enum has to live here even though the tier is derived, rendered and
        // compared entirely inside Chains.
        'Modules/Chains/Public/Enums/ConfidenceTier.php',
        'Modules/Community/Public/Dto/MerchantContactDto.php',
        'Modules/Community/Public/Dto/SuggestMappingDto.php',
        'Modules/Community/Public/Events/MysteryMerchantSubmitted.php',
        'Modules/Core/Public/Enums/TransitionActor.php',
        'Modules/Core/Public/Exceptions/BackupCorruptException.php',
        'Modules/Core/Public/Exceptions/BackupNotSupportedException.php',
        'Modules/Core/Public/Exceptions/LockStoreNotConfiguredException.php',
        'Modules/Core/Public/Exceptions/RestoreFailedException.php',
        'Modules/Core/Public/Exceptions/UnsafeBackupPathException.php',
        'Modules/Core/Public/Services/Concerns/ProvidesInstancePathAccessors.php',
        'Modules/Core/Public/Services/CurrentUserService.php',
        'Modules/Core/Public/Services/PassthroughSecretShield.php',
        'Modules/Core/Public/Support/AppChrome.php',
        'Modules/Counterparties/Public/Dto/CounterpartyResolutionDto.php',
        'Modules/Counterparties/Public/Enums/CounterpartyType.php',
        'Modules/Counterparties/Public/Events/CounterpartyResolved.php',
        'Modules/Counterparties/Public/Queries/ChainSummary.php',
        'Modules/Counterparties/Public/Queries/CounterpartyIndexQuery.php',
        'Modules/Counterparties/Public/Queries/CounterpartyIndexRow.php',
        'Modules/Counterparties/Public/Queries/CounterpartyProfileDto.php',
        'Modules/Counterparties/Public/Queries/TriageSuggestion.php',
        'Modules/DevMode/Public/Contracts/AppActionRegistry.php',
        'Modules/DevMode/Public/Contracts/AuditWriter.php',
        'Modules/DevMode/Public/Dto/AppAction.php',
        'Modules/DevMode/Public/Dto/CommandRunAudit.php',
        'Modules/DriftAlerts/Public/Actions/SnoozeDriftAlert.php',
        'Modules/DriftAlerts/Public/Dto/CancellationImpactDto.php',
        'Modules/DriftAlerts/Public/Dto/DriftAlertDto.php',
        'Modules/DriftAlerts/Public/Dto/SavingsInsight.php',
        'Modules/DriftAlerts/Public/Dto/SubscriptionDriftRow.php',
        'Modules/DriftAlerts/Public/Events/DriftAlertAcknowledged.php',
        'Modules/DriftAlerts/Public/Events/DriftAlertSnoozed.php',
        'Modules/DriftAlerts/Public/Services/CancellationImpactQuery.php',
        'Modules/DriftAlerts/Public/Services/DriftAlertQuery.php',
        'Modules/DriftAlerts/Public/Services/SubscriptionDriftWatchQuery.php',
        'Modules/EmailScan/Public/Actions/DisconnectInbox.php',
        'Modules/EmailScan/Public/Actions/DismissDiscoveredSender.php',
        'Modules/EmailScan/Public/Actions/PromoteDiscoveredSender.php',
        'Modules/EmailScan/Public/Dto/DiscoveredSenderDto.php',
        'Modules/EmailScan/Public/Dto/InboxCredentials.php',
        'Modules/EmailScan/Public/Dto/InboxHealthDto.php',
        'Modules/EmailScan/Public/Dto/KnownSenderDto.php',
        'Modules/EmailScan/Public/Dto/ScanCursor.php',
        'Modules/EmailScan/Public/Enums/DiscoveredSenderState.php',
        'Modules/EmailScan/Public/Exceptions/EmlBlobWriteException.php',
        'Modules/EmailScan/Public/Services/DiscoveredSenderQuery.php',
        'Modules/EmailScan/Public/Services/InboxQuery.php',
        'Modules/EmailScan/Public/Services/InboxesBadgeCount.php',
        'Modules/EmailScan/Public/Services/KnownSenderQuery.php',
        'Modules/EmailScan/Public/Services/OAuthSecretsRepository.php',
        'Modules/EmailScan/Public/Services/SecretsWriteFailed.php',
        'Modules/FX/Public/Contracts/RateProvider.php',
        // Returned by FxRefreshStatus::lastFailure(), which Shell's settings
        // page calls to say why a refresh gave up.
        'Modules/FX/Public/Dto/FxRefreshFailure.php',
        'Modules/FX/Public/Exceptions/RateFetchException.php',
        'Modules/Forecasting/Public/Actions/SetAccountForecastBuffer.php',
        'Modules/Forecasting/Public/Actions/SetAccountOpeningBalance.php',
        'Modules/Forecasting/Public/Dto/BalanceAnchorDto.php',
        'Modules/Forecasting/Public/Dto/ForecastDto.php',
        'Modules/Forecasting/Public/Dto/ForecastHighlightsDto.php',
        'Modules/Forecasting/Public/Dto/ForecastPointDto.php',
        'Modules/Forecasting/Public/Dto/ScenarioDto.php',
        'Modules/Forecasting/Public/Dto/ScenarioMutationDto.php',
        'Modules/Forecasting/Public/Dto/ScenarioMutationPayload/ScenarioMutationPayload.php',
        'Modules/Forecasting/Public/Dto/SeriesConfidenceDto.php',
        'Modules/Forecasting/Public/Dto/ShortfallWindowDto.php',
        'Modules/Forecasting/Public/Enums/ShiftScope.php',
        'Modules/Goals/Public/Dto/GoalAttributionRow.php',
        'Modules/Goals/Public/Dto/GoalProgressRow.php',
        // The vocabulary of GoalProgressRow::$progressState, which is a Public
        // DTO field: a reader outside Goals has to be able to name the values
        // it compares against.
        'Modules/Goals/Public/Enums/GoalProgressState.php',
        'Modules/Goals/Public/Exceptions/GoalNotFoundException.php',
        'Modules/Goals/Public/Exceptions/InvalidGoalAmountException.php',
        'Modules/Goals/Public/Exceptions/InvalidGoalNameException.php',
        // The documented @throws of GoalWriter::save()/update(), which is
        // Public: a caller outside Goals has to be able to catch it by type.
        'Modules/Goals/Public/Exceptions/InvalidGoalTargetDateException.php',
        'Modules/Goals/Public/Services/GoalProgressQuery.php',
        'Modules/Goals/Public/Services/GoalProjectionService.php',
        'Modules/Import/Public/Actions/ConfirmImport.php',
        'Modules/Import/Public/Actions/RunImport.php',
        'Modules/Import/Public/Contracts/NamesAccounts.php',
        'Modules/Import/Public/Dto/AliasMatchPreviewResultDto.php',
        'Modules/Import/Public/Dto/DuplicateDisposition.php',
        'Modules/Import/Public/Dto/EnrichedDisposition.php',
        'Modules/Import/Public/Dto/FingerprintDisposition.php',
        'Modules/Import/Public/Dto/NewRowDisposition.php',
        'Modules/Import/Public/Dto/PaymentTypeHint.php',
        'Modules/Import/Public/Services/AccountNamer.php',
        'Modules/Import/Public/Services/AliasMatchPreviewQuery.php',
        'Modules/Import/Public/Services/EloquentAccountResolver.php',
        'Modules/Import/Public/Services/SourceRefRanker.php',
        'Modules/Ingestion/Public/Contracts/SourceAdapter.php',
        'Modules/Ingestion/Public/Dto/SniffResult.php',
        'Modules/Ingestion/Public/Exceptions/UnsupportedFormatException.php',
        // The declared return of PaypalCsvEventTypeMap::classify(), which is
        // Public. Import's ClassifyTransactionType holds the map but only ever
        // calls transactionType(), so the enum is named nowhere outside here.
        'Modules/Ingestion/Public/Paypal/PaypalEventAction.php',
        'Modules/Ingestion/Public/Services/HeaderSniffer.php',
        // The four write actions behind Ledger's Public contracts. Their
        // callers name the contract, never the action.
        'Modules/Ledger/Public/Actions/DeleteTransaction.php',
        'Modules/Ledger/Public/Actions/ReassignCounterparty.php',
        'Modules/Ledger/Public/Actions/SetTransactionNote.php',
        'Modules/Ledger/Public/Actions/UpdateTransactionCategory.php',
        'Modules/Ledger/Public/Dto/CategoryDelta.php',
        'Modules/Ledger/Public/Dto/PeriodResolution.php',
        'Modules/Ledger/Public/Dto/SpendTrend.php',
        'Modules/Ledger/Public/Dto/TransactionListPage.php',
        'Modules/Ledger/Public/Dto/TransactionRowDto.php',
        'Modules/Ledger/Public/Exceptions/CurrencyMismatchException.php',
        'Modules/Ledger/Public/Services/ReconciliationWriter.php',
        'Modules/Ledger/Public/Services/StatementSummaryWriter.php',
        'Modules/Ledger/Public/Services/TransactionListQuery.php',
        'Modules/Notifications/Public/Actions/DismissNotification.php',
        'Modules/Notifications/Public/Actions/MarkNotificationRead.php',
        'Modules/Notifications/Public/Actions/UndoDismissNotification.php',
        'Modules/Notifications/Public/Dto/DeliveryDecision.php',
        'Modules/Notifications/Public/Dto/NotificationDto.php',
        'Modules/Notifications/Public/Dto/NotificationPreferencesDto.php',
        'Modules/Notifications/Public/Enums/NotificationState.php',
        'Modules/Notifications/Public/NotificationCopy.php',
        // The type NotificationsSettingsSection names in its mount(), save()
        // and render() signatures. That component is mounted by alias from
        // Shell's settings page, so the consumer is real and outside this
        // scan — a Blade mount never names the types the component injects.
        'Modules/Notifications/Public/Services/NotificationPreferenceQuery.php',
        'Modules/Notifications/Public/Services/NotificationQuery.php',
        'Modules/Pots/Public/Dto/PotMovementRow.php',
        'Modules/Pots/Public/Dto/PotRow.php',
        'Modules/Pots/Public/Dto/ReconciliationRow.php',
        'Modules/Pots/Public/Exceptions/InsufficientUnallocatedException.php',
        // Documented @throws of PotWriter, which is Public and whose
        // transfer() Sync already calls. A caller outside Pots that wants to
        // tell the refusals apart has to name them, so an Internal one would
        // be a Public method throwing a type nobody is allowed to catch.
        'Modules/Pots/Public/Exceptions/CrossAccountTransferException.php',
        'Modules/Pots/Public/Exceptions/GoalAlreadyLinkedException.php',
        'Modules/Pots/Public/Exceptions/InvalidPotAmountException.php',
        'Modules/Pots/Public/Exceptions/SelfTransferException.php',
        // Narrows the Public PotNotFoundException, which Goals catches. An
        // Internal subclass would reach that catch under a name the catching
        // module is not allowed to write.
        'Modules/Pots/Public/Exceptions/TargetPotNotFoundException.php',
        'Modules/Receipts/Public/Actions/ApplyReceiptConflictResolution.php',
        'Modules/Receipts/Public/Dto/MatcherInputDto.php',
        'Modules/Receipts/Public/Dto/ParsedReceiptDto.php',
        'Modules/Receipts/Public/Exceptions/MboxReadException.php',
        'Modules/Receipts/Public/Pipeline/EmlMimeReader.php',
        'Modules/Receipts/Public/Pipeline/FileDropEmlBlobStore.php',
        'Modules/Receipts/Public/Pipeline/ParsedMimeMessage.php',
        'Modules/Recurring/Public/Actions/EditRecurringSeriesName.php',
        'Modules/Recurring/Public/Actions/EditRecurringSeriesVarianceTolerance.php',
        'Modules/Recurring/Public/Actions/RejectRecurringSeries.php',
        'Modules/Recurring/Public/Actions/SnoozeRecurringSeries.php',
        'Modules/Recurring/Public/Actions/UnRejectRecurringSeries.php',
        // FixedPaymentsCard hands this to its Blade view as `$totals`, and the
        // card is mounted from Shell's dashboard, so the consumer is real but
        // outside this scan: a view variable never names its own type.
        'Modules/Recurring/Public/Dto/MonthlyEquivalentTotals.php',
        'Modules/Recurring/Public/Dto/RecurringSeriesAmountTrendDto.php',
        'Modules/Recurring/Public/Events/RecurringSeriesDetected.php',
        'Modules/Recurring/Public/Services/FixedPaymentsViewQuery.php',
        'Modules/Sync/Public/Services/ImportSyncCapture.php',
        'Modules/Sync/Public/Services/SyncStatusService.php',
        'Modules/Tax/Public/Dto/BatchTagSuggestion.php',
        'Modules/Tax/Public/Dto/TaxTagData.php',
        'Modules/Tax/Public/Dto/TaxYearData.php',
        'Modules/Tax/Public/Dto/TaxYearSummary.php',
    ];

    $sources = publicSurfaceSources();

    // Both denominators, before either verdict. A walk that read nothing finds
    // no Public class, so every one of them is consumed, so nothing was added —
    // a clean answer the tree never earned.
    expect(count($sources))->toBeGreaterThan(5000, 'The walk read almost nothing, so the consumer scan below has no corpus to find a consumer in.');

    $declared = [];
    $referenced = [];
    foreach ($sources as $relative => $contents) {
        $owner = preg_match('#^Modules/([^/]+)/#', $relative, $ownerMatch) === 1 ? $ownerMatch[1] : null;

        if (
            $owner !== null
            && str_contains($relative, '/Public/')
            && ! str_contains($relative, '/Public/Http/Livewire/')
            && preg_match('/^namespace\s+([^;]+);/m', $contents, $namespace) === 1
            && preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+([A-Za-z0-9_]+)/m', $contents, $name) === 1
        ) {
            $declared[trim($namespace[1]).'\\'.$name[1]] = $relative;
        }

        // A name written into a PHP string, a JSON key or a neon path arrives
        // doubled; collapsing first lets one pattern read every spelling.
        $normalised = str_replace('\\\\', '\\', $contents);
        if (! str_contains($normalised, '\\Public\\')) {
            continue;
        }
        $hits = PatternScan::sets('/Modules\\\\([A-Za-z0-9_]+)\\\\Public\\\\[A-Za-z0-9_\\\\]+/', $normalised);
        foreach ($hits as $hit) {
            if ($hit[1] !== $owner) {
                $referenced[rtrim($hit[0], '\\')] = true;
            }
        }
    }
    ksort($declared);

    expect(count($declared))->toBeGreaterThan(400, 'The declaration scan found almost no Public class, so the pinned list below is being measured against nothing.');

    $unconsumed = [];
    foreach ($declared as $fqcn => $relative) {
        if (! isset($referenced[$fqcn])) {
            $unconsumed[] = $relative;
        }
    }
    sort($unconsumed);

    $added = array_values(array_diff($unconsumed, $pinnedUnconsumed));

    expect($added)->toBe(
        [],
        'A new class under Modules/<X>/Public/ has no consumer outside Modules/<X>/, so nothing about '
        .'it is public. Put it in Internal/ and let a neighbour that actually needs it pull a contract '
        .'up, or — if a consumer exists in a shape a fully-qualified-name scan cannot see — add the path '
        ."below with the reason written above the line. The list only shrinks.\n  "
        .implode("\n  ", $added),
    );

    // The list only shrinks, and this is what makes that true rather than
    // aspirational. Fifty-four of these lines had found a consumer and excused
    // nothing, which reads as considered: a reviewer cannot tell dead surface
    // from surface that came alive while nobody was looking.
    $consumedNow = array_values(array_diff($pinnedUnconsumed, $unconsumed));

    expect($consumedNow)->toBe(
        [],
        'These pinned classes now have a consumer outside their own module, so the pin excuses nothing. '
        ."Delete the lines — the surface is public after all:\n  ".implode("\n  ", $consumedNow),
    );

    $repoRoot = dirname((string) realpath(base_path('Modules')));
    $vanished = array_values(array_filter(
        $pinnedUnconsumed,
        static fn (string $path): bool => ! is_file($repoRoot.'/'.$path),
    ));

    expect($vanished)->toBe(
        [],
        "These pinned paths no longer exist. Delete the lines:\n  ".implode("\n  ", $vanished),
    );
});
