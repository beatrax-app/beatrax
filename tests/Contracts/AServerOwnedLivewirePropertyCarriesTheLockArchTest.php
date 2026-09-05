<?php

declare(strict_types=1);

use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\WireCallableMethods;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-denomination-the-browser-could-choose
 */

// Every spelling a template or a script can use to WRITE a Livewire property.
// A property any of them names is the client's and must stay unlocked; the
// guard below is only ever asking about the rest.
const SERVER_OWNED_BINDING_PATTERNS = [
    '/wire:model[.\w]*\s*=\s*["\']\s*([A-Za-z_][A-Za-z0-9_]*)/',
    '/x-model[.\w]*\s*=\s*["\']\s*([A-Za-z_][A-Za-z0-9_]*)/',
    '/\$wire\.\$?set\(\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)/',
    '/(?<!\w)\$set\(\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)/',
    '/entangle\(\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)/',
    '/\$wire\.\$?toggle\(\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)/',
    '/(?<!\w)\$toggle\(\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)/',
    '/\$wire\.([A-Za-z_][A-Za-z0-9_]*)\s*=(?!=)/',
];

// A property no binding names, read back by an action, and not under #[Locked]
// is one the browser chooses for the server. Most of the tree is not that: the
// entries below say, per property, what makes the client's value harmless.
// A new one belongs under #[Locked] far more often than it belongs here.
/**
 * @return array<string, string> Class::$property => the reason it needs no lock
 */
function serverOwnedPropertyExemptions(): array
{
    return [
        // Read back only to choose a message or a screen state. Nothing is
        // stored, no row is named, and the worst a forged value buys is a
        // sentence the reader could have read anyway.
        'Modules\\Auth\\Internal\\Http\\Livewire\\ManageUserPage::$regeneratedCodes' => 'the codes offered for download are the ones the payload itself supplied',
        'Modules\\Budgets\\Internal\\Http\\Livewire\\BudgetsPage::$thresholdErrors' => 'per-row validation text, rewritten from the validator on every write',
        'Modules\\Core\\Public\\Http\\Livewire\\EncryptedBackupDownload::$error' => 'an error line, read back only to decide whether to re-render it',
        'Modules\\Mobile\\Internal\\Http\\Livewire\\MobileRestoreFromBackup::$error' => 'an error line, read back only to decide whether to re-render it',
        'Modules\\Pots\\Internal\\Http\\Livewire\\PotsPage::$errorAmountLimitMinor' => 'the figure quoted in a refusal message, re-derived on every refusal',
        'Modules\\Shell\\Internal\\Http\\Livewire\\SettingsPage::$fxRefreshBaseline' => 'a poll watermark compared against the live table, never written back',
        'Modules\\Shell\\Internal\\Http\\Livewire\\SettingsPage::$fxRefreshPolls' => 'a poll counter whose only effect is giving up sooner',
        'Modules\\Shell\\Internal\\Http\\Livewire\\SettingsPage::$fxRefreshing' => 'a poll flag; forging it polls a refresh that is not running',

        // Overwritten by the reading method before it is read. The client's
        // value cannot survive to the statement that uses it.
        'Modules\\Core\\Public\\Http\\Livewire\\EncryptedBackupRestore::$snapshotPath' => 'restore() blanks it and re-fills it from the restore action before flashing it',

        // Names a row, and every method that reads it re-reads that row scoped
        // to the reader in the same method — the standard
        // UserScopeDropRequiresUserIdArchTest already holds elsewhere.
        'Modules\\Categorization\\Internal\\Http\\Livewire\\RuleFormModal::$editingRuleId' => 'the update action re-scopes on user_id and answers a stale id with the rule-unavailable message',
        'Modules\\EmailScan\\Internal\\Http\\Livewire\\BackfillWindowModal::$inboxId' => 'submit() re-reads the inbox on user_id and refuses before it writes or dispatches',
        'Modules\\Forecasting\\Internal\\Http\\Livewire\\ScenarioEditorSidebar::$editingMutationId' => 'EditScenarioMutation re-reads the row on user_id and 404s',
        'Modules\\Forecasting\\Internal\\Http\\Livewire\\ScenarioEditorSidebar::$mutations' => 'a summary list; editMutation() only takes an id out of it, and the action re-reads the row',
        'Modules\\Ledger\\Internal\\Http\\Livewire\\TransactionDetail::$transactionId' => 'every action re-reads the transaction on user_id in its own method and 404s',
        'Modules\\Migration\\Internal\\Http\\Livewire\\NewMigration::$reconcileOf' => 'the update check takes the user and resolves the prior run against it',

        // A key into a set the server just built, or a hint the writer
        // re-validates. The set is the authority, not the key.
        'Modules\\Budgets\\Internal\\Http\\Livewire\\BudgetsPage::$moveFromCategoryId' => 'only a key into the fold rows, and EnvelopeWriter::move() re-scopes on the user',
        'Modules\\Import\\Internal\\Http\\Livewire\\AliasesSettingsPage::$importDiff' => 'a diff of the reader\'s own aliases against a file the reader chose',
        'Modules\\Import\\Internal\\Http\\Livewire\\RenameCounterpartyPopover::$categoryHint' => 'a category hint the rule writer re-validates against the reader\'s own categories',
        'Modules\\Import\\Internal\\Http\\Livewire\\RenameCounterpartyPopover::$raw' => 'the raw counterparty string the rule is written from, re-normalised by the writer',
        'Modules\\Import\\Internal\\Http\\Livewire\\RenameCounterpartyPopover::$rowIndex' => 'a row index echoed back in the dispatched event so the caller can find its own row',
        'Modules\\Onboarding\\Internal\\Http\\Livewire\\Steps\\FirstImportStep::$balanceConfirmations' => 'persistCommit() re-filters every account id on user_id before it writes',

        // Narrowed rather than refused, because the client legitimately writes
        // a neighbouring shape and the reader's own set is the ceiling.
        'Modules\\Calendar\\Internal\\Http\\Livewire\\CalendarPage::$balanceAccountIds' => 'sanitizeAccountIds() intersects against the reader\'s own accounts on render and on persist',
        'Modules\\Calendar\\Internal\\Http\\Livewire\\CalendarPage::$visibleAccountIds' => 'sanitizeAccountIds() intersects against the reader\'s own accounts on render and on persist',

        // A view window or a page counter. Both ends are bounded by the code
        // that reads it, so a forged value buys a different slice of the
        // reader's own rows and nothing else.
        'Modules\\Chains\\Public\\Http\\Livewire\\ChainDrawer::$fanoutPage' => 'a page counter over the reader\'s own chain, bounded by the query',
        'Modules\\DevMode\\Internal\\Http\\Livewire\\QueueInspectorPage::$expandedRowId' => 'which payload row is expanded on the dev console',
        'Modules\\DevMode\\Internal\\Http\\Livewire\\QueueInspectorPage::$tab' => 'which queue table the dev console is showing; both tabs are this installation own queue state',
        'Modules\\Recurring\\Internal\\Http\\Livewire\\RecurringPage::$transfersExpanded' => 'whether the transfers group is open',
        'Modules\\Recurring\\Internal\\Http\\Livewire\\RecurringSeriesDetailPage::$showAllPoints' => 'chooses between two fixed point counts, 24 or 1000',
        'Modules\\Shell\\Internal\\Http\\Livewire\\Dashboard::$periodStartStr' => 'the dashboard only navigates and renders; no method here writes anything anchored to it',
        'Modules\\Shell\\Internal\\Http\\Livewire\\NetWorthCard::$expanded' => 'whether the card is open',

        // The reader's own preference, on their own account. A forged value
        // writes what tapping the control twice would write, and gates nothing
        // but what this reader sees.
        'Modules\\Core\\Public\\Http\\Livewire\\AutoImportSettingsSection::$enabled' => 'the drop-folder preference, reachable from the switch beside it',
        'Modules\\Core\\Public\\Http\\Livewire\\UpdateCheckSettingsSection::$enabled' => 'the update-check preference, reachable from the switch beside it',
        'Modules\\Mobile\\Internal\\Http\\Livewire\\SyncScreen::$pauseOnCellular' => 'the cellular-pause preference, reachable from the switch beside it',
        'Modules\\Shell\\Internal\\Http\\Livewire\\SettingsPage::$confirmingPeriodMove' => 'a self-confirmation on the reader\'s own settings save, which the confirm button reaches anyway',
        'Modules\\Shell\\Internal\\Http\\Livewire\\SettingsPage::$fxOnlineEnabled' => 'the online-FX preference, reachable from the switch beside it',
        'Modules\\Shell\\Internal\\Http\\Livewire\\SettingsPage::$locale' => 'the reader\'s own language, validated against Locale::codes() before it is stored',
        'Modules\\Shell\\Internal\\Http\\Livewire\\SettingsPage::$theme' => 'the reader\'s own theme, validated against the Theme enum before it is stored',

        // Prefills an input the reader then edits and submits. The submitted
        // value is what is parsed and stored; this one only seeds the box.
        'Modules\\Forecasting\\Internal\\Http\\Livewire\\ScenarioEditorSidebar::$scenarioName' => 'seeds the rename box, which the reader then submits',
        'Modules\\Forecasting\\Public\\Http\\Livewire\\ModelWhatIfDropdown::$currentAmountMinor' => 'seeds the amount box, which the reader then submits',
        'Modules\\Forecasting\\Public\\Http\\Livewire\\OpeningBalanceEditor::$beatraxsNumberMinor' => 'seeds the opening-balance box, which the reader then submits',
        'Modules\\Reports\\Internal\\Http\\Livewire\\ReportBuilder::$loadedReportName' => 'seeds the save-as box, which the reader then submits',

        // A capability, peer or wizard flag whose real gate is downstream: the
        // forged value reaches a check that throws, refuses or is idempotent.
        'Modules\\EmailScan\\Public\\Http\\Livewire\\OAuthClientWizardModal::$provider' => 'the wizard writes the reader\'s own OAuth secret and the provider is re-derived from the enum',
        'Modules\\Mobile\\Internal\\Http\\Livewire\\ColdStartBiometricSettingsSection::$available' => 'the key vault itself refuses on hardware without one; this flag only picks the message',
        'Modules\\Mobile\\Internal\\Http\\Livewire\\SyncScreen::$hasPeers' => 'forging it starts a sync that finds no peer',
        'Modules\\OpenBanking\\Internal\\Http\\Livewire\\OpenBankingWizardModal::$publicKeyPem' => 'read only for emptiness; the key itself is re-read from storage',
        'Modules\\Sync\\Public\\Http\\Livewire\\DevicesAndSyncSettingsSection::$appLockConfigured' => 'enableSync() reaches a real re-check that throws when the lock is not configured',
        'Modules\\Sync\\Public\\Http\\Livewire\\DevicesAndSyncSettingsSection::$identityUnreadable' => 'replaceUnreadableIdentity() re-reads the identity and is idempotent when it is readable',
        'Modules\\Sync\\Public\\Http\\Livewire\\DevicesAndSyncSettingsSection::$syncEnabled' => 'both readers reach a real re-check of the stored sync state',

        // The reader's own upload, held across the two requests Livewire needs
        // to receive it. A forged value names a temporary file of their own.
        'Modules\\Onboarding\\Internal\\Http\\Livewire\\Steps\\ConnectBankStep::$csvLayoutPicked' => 'whether the layout question has been answered for the reader\'s own upload',
        'Modules\\Onboarding\\Internal\\Http\\Livewire\\Steps\\ConnectBankStep::$selectedFormat' => 'the parser chosen for the reader\'s own upload, re-derived by the importer from the file',
        'Modules\\Onboarding\\Internal\\Http\\Livewire\\Steps\\ConnectCardStep::$statements' => 'the reader own staged uploads, each re-read from the temporary path',
        'Modules\\Onboarding\\Internal\\Http\\Livewire\\Steps\\ConnectPaypalStep::$activityCsv' => 'the reader own staged upload, re-read from the temporary path',
        'Modules\\Forecasting\\Internal\\Http\\Livewire\\ScenarioEditorSidebar::$selectedKind' => 'the mutation kind, re-checked against the stored kind by EditScenarioMutation',

        // A cursor or a window over the reader's own ledger. Every query behind
        // them is scoped to the reader, so a forged value buys a different
        // slice of rows they can already page to.
        'Modules\\Ledger\\Internal\\Http\\Livewire\\TransactionsList::$fullHistory' => 'whether the list shows the whole ledger or the recent window',
        'Modules\\Ledger\\Internal\\Http\\Livewire\\TransactionsList::$nextCursorId' => 'a keyset cursor into the reader\'s own transactions',
        'Modules\\Ledger\\Internal\\Http\\Livewire\\TransactionsList::$nextCursorPostedAt' => 'a keyset cursor into the reader\'s own transactions',
        'Modules\\Ledger\\Internal\\Http\\Livewire\\TransactionsList::$preSearchFullHistory' => 'the window to restore when the search box is cleared',
        'Modules\\Onboarding\\Internal\\Http\\Livewire\\StartingBalanceCard::$isConfirmed' => 'which of two labels the card draws after an edit is cancelled',
        'Modules\\Sync\\Public\\Http\\Livewire\\DevicesAndSyncSettingsSection::$encryptionProgress' => 'a poll percentage, read only to decide whether to stop polling',
    ];
}

/** @return array<string, true> every property name a template or script writes */
function clientBoundPropertyNames(): array
{
    $names = [];

    foreach ([base_path('Modules'), base_path('resources')] as $root) {
        if (! is_dir($root)) {
            continue;
        }

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());
            if (! $file->isFile() || preg_match('/\.(blade\.php|js|vue|php)$/', $path) !== 1) {
                continue;
            }
            if (str_contains($path, '/tests/') || str_contains($path, '/Tests/')) {
                continue;
            }

            $source = (string) file_get_contents($path);
            foreach (SERVER_OWNED_BINDING_PATTERNS as $pattern) {
                $matches = PatternScan::all($pattern, $source);

                foreach ($matches[1] as $name) {
                    $names[$name] = true;
                }
            }
        }
    }

    return $names;
}

// An action method runs BEFORE render(), so a property render() rewrites is
// still whatever the payload said for the whole of the action that reads it.
// A method that only assigns the property is not reading the client's value,
// which is what keeps every error line and screen flag out of this.
/**
 * @param  list<ReflectionMethod>  $methods
 * @return list<string>
 */
function methodsReadingProperty(string $file, array $methods, string $property): array
{
    $lines = file($file) ?: [];
    $readers = [];

    foreach ($methods as $method) {
        if ($method->getFileName() !== $file) {
            continue;
        }

        $body = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
        if (preg_match('/\$this->'.preg_quote($property, '/').'\b(?!\s*=(?!=))/', $body) === 1) {
            $readers[] = $method->getName();
        }
    }

    return $readers;
}

/** @return array<string, list<string>> Class::$property => the methods that read it */
function serverOwnedUnlockedProperties(): array
{
    $bound = clientBoundPropertyNames();
    $found = [];

    foreach (WireCallableMethods::components() as $component) {
        $reflection = new ReflectionClass($component);
        $file = (string) $reflection->getFileName();
        $methods = WireCallableMethods::invokableOn($component);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic() || $property->isReadOnly()) {
                continue;
            }

            $declaredIn = str_replace(DIRECTORY_SEPARATOR, '/', (string) $property->getDeclaringClass()->getFileName());
            if ($declaredIn === '' || str_contains($declaredIn, '/vendor/')) {
                continue;
            }
            if ($property->getAttributes(Locked::class) !== [] || $property->getAttributes(Url::class) !== []) {
                continue;
            }
            if (isset($bound[$property->getName()])) {
                continue;
            }

            $readers = methodsReadingProperty($file, $methods, $property->getName());
            if ($readers !== []) {
                $found[$component.'::$'.$property->getName()] = $readers;
            }
        }
    }

    ksort($found);

    return $found;
}

it('locks every public Livewire property the server writes and an action reads', function (): void {
    $exempt = serverOwnedPropertyExemptions();
    $found = serverOwnedUnlockedProperties();

    expect($found)->not->toBe([]);

    $offenders = [];
    foreach ($found as $property => $readers) {
        if (isset($exempt[$property])) {
            continue;
        }
        $offenders[] = $property.' — read by '.implode(', ', $readers);
    }

    expect($offenders)->toBe([], implode("\n", array_merge(
        ['A public Livewire property no wire:model, $wire.set, entangle, $toggle or x-model names is written by the server '.
            'alone, and an action method runs BEFORE render() — so a replayed wire:snapshot chooses it for the whole of the '.
            'action that reads it. Give each of these #[Locked], or add it to serverOwnedPropertyExemptions() with the reason '.
            'the client\'s value is harmless:'],
        $offenders,
    )));
});

// An exemption outlives the property it excuses. Renamed or deleted, the entry
// stays green and silently stops meaning anything, which is how a reasoned list
// turns into a list. Locking a listed property is fine and makes its entry
// redundant rather than wrong.
it('keeps every exemption pointing at a property that still exists', function (): void {
    $stale = [];

    foreach (array_keys(serverOwnedPropertyExemptions()) as $entry) {
        [$class, $property] = explode('::$', $entry, 2);

        if (! class_exists($class) || ! property_exists($class, $property)) {
            $stale[] = $entry;
        }
    }

    expect($stale)->toBe([], 'These exemptions name a property that no longer exists. Delete the entry rather than leaving it: '.implode(', ', $stale));
});

it('gives every exemption a reason a reader can weigh', function (): void {
    foreach (serverOwnedPropertyExemptions() as $entry => $reason) {
        expect(strlen($reason))->toBeGreaterThan(20, "{$entry} is exempt without saying why the client's value is harmless.");
    }
});
