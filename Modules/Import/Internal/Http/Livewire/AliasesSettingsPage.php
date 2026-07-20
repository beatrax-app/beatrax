<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Community\Public\Dto\CorpusEntryDto;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Import\Internal\Services\AliasYamlExporter;
use Modules\Import\Internal\Services\AliasYamlImporter;
use Modules\Import\Internal\Services\LongestCommonPrefix;
use Modules\Import\Public\Actions\MergeMerchantAliases;
use Modules\Import\Public\Services\AliasMatchPreviewQuery;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * The power-user surface at `/settings/aliases` — the consolidated
 * editor + bulk-merge + YAML export/import view over the per-user
 * `merchant_aliases` table.
 *
 * The per-row rename UX (the click-italic flow at preview time) lives
 * in `RenameCounterpartyPopover`; this page is the place users go when
 * they want to see, edit, merge, export, or import their accumulated
 * aliases without going through a fresh statement import.
 *
 * Feature surface:
 *
 *   - Paginated list (25/page) of the user's `merchant_aliases` rows
 *     with read-only `pattern`, editable `generalized_pattern`, and a
 *     live "Test against my transactions" preview pane (400ms debounce,
 *     bounded 500-row scan, LIMIT 5 + COUNT).
 *   - Per-row Save / Delete actions (both 404-not-403 on cross-user
 *     attempts).
 *   - Checkbox bulk-merge: select ≥2 rows → confirm dialog prefills
 *     `friendly_name` + LCP of the patterns → MergeMerchantAliases
 *     preserves `merged_from` provenance.
 *   - YAML export: downloads `aliases.yaml` in the community-corpus
 *     schema so a round-trip (export → import → diff) yields no
 *     changes.
 *   - YAML import: file upload (extensions:yaml,yml, max:1024 KB) →
 *     parse + diff (new / unchanged / conflicts) → conflict-resolution
 *     UI (keep / replace) → commit.
 *
 * Strict-rules posture:
 *   - Constructor-DI is forbidden on Livewire Component subclasses;
 *     every collaborator arrives as a method parameter on the action
 *     that needs it.
 *   - Forbidden global helpers (`abort`, `response`, `now`, `redirect`,
 *     `auth`, `request`, `event`) are NEVER used — concrete contracts
 *     are method-DI'd instead.
 *   - Every read / write that touches `merchant_aliases` carries an
 *     explicit `where('user_id', $currentUser->user()->id)`; the
 *     ownership guard is structural, not advisory.
 *   - Cross-user attempts hit `NotFoundHttpException` (404-not-403).
 */
#[Layout('layouts.app')]
final class AliasesSettingsPage extends Component
{
    use WithFileUploads;

    /**
     * Per-page row count for the aliases table. The table is dense so
     * 25/page keeps the page footprint manageable on a laptop screen
     * without forcing the user into a long scroll.
     */
    public int $perPage = 25;

    /**
     * Identifier of the alias row currently in inline-edit mode. `0`
     * means no row is being edited; otherwise the row's primary key.
     * Stored as int rather than ?int so the Blade can branch on a
     * single typed comparison without nullability handling.
     */
    public int $editingId = 0;

    /**
     * The current value of the inline-edited `generalized_pattern`.
     * Bound via `wire:model.live.debounce.400ms` so each accepted
     * change fires `updatedEditingPattern()` which in turn runs the
     * "Test against my transactions" preview probe.
     */
    public string $editingPattern = '';

    /**
     * Result envelope of the most recent live preview probe stored as
     * a primitive-only array shape so Livewire's wire payload
     * round-tripping survives. Empty array outside an active edit;
     * populated on every accepted update of `editingPattern`.
     *
     * Shape:
     *   - `total` (int)
     *   - `first5` (list<array{description, counterparty_name, booked_at}>)
     *   - `emptyMessage` (?string)
     *
     * @var array{total: int, first5: list<array{description: string, counterparty_name: string, booked_at: string}>, emptyMessage: ?string}|array{}
     */
    public array $previewResult = [];

    /**
     * Selected row ids for the bulk-merge dialog. The page enables the
     * "Merge selected" action only when the array carries at least two
     * unique ids (the LCP service itself rejects size-1 inputs, but
     * surfacing the gate in the UI prevents the empty dialog).
     *
     * @var list<int>
     */
    public array $selectedIds = [];

    /**
     * Bulk-merge confirm-dialog visibility flag.
     */
    public bool $showMergeModal = false;

    /**
     * Bulk-merge confirm-dialog: the consolidated friendly name. The
     * dialog initialises this with the first selected row's existing
     * friendly name, but the user can edit before confirming.
     */
    public string $mergeFriendlyName = '';

    /**
     * Bulk-merge confirm-dialog: the prefilled longest-common-prefix
     * of the selected rows' `generalized_pattern` values. Stays an
     * empty string when the LCP service rejects the set (no 4-char
     * shared prefix); the dialog then forces the user to type a
     * pattern manually before the Confirm button enables.
     */
    public string $mergeGeneralizedPattern = '';

    /**
     * Inline flash message surfaced beneath the page header after a
     * Save / Delete / Merge / Import succeeds. Cleared on the next
     * action.
     */
    public string $flashMessage = '';

    /**
     * Pending YAML upload. `WithFileUploads` populates this property
     * from the file input; `parseUpload()` reads the temp file via
     * `getRealPath()` and pipes the body into AliasYamlImporter.
     */
    public ?TemporaryUploadedFile $importFile = null;

    /**
     * Diff payload of the most recent YAML upload — stored as a
     * primitive-only array shape so Livewire's wire payload
     * round-tripping survives. Empty array outside an active import.
     *
     * Shape (each `entry` is the CorpusEntryDto flattened into a
     * primitive-only mapping that `confirmImport` rehydrates):
     *
     *   new:        list<array{pattern,generalized_pattern,name,category,region,contributor}>
     *   unchanged:  list<array{pattern,generalized_pattern,name,category,region,contributor}>
     *   conflicts:  list<array{entry: array{...}, existing_name: string, existing_generalized_pattern: string}>
     *
     * @var array{new?: list<array<string, mixed>>, unchanged?: list<array<string, mixed>>, conflicts?: list<array{entry: array<string, mixed>, existing_name: string, existing_generalized_pattern: string}>}
     */
    public array $importDiff = [];

    /**
     * Per-conflict resolution map: `pattern => 'keep' | 'replace'`.
     * Populated by `parseUpload()` with 'keep' defaults; the conflict
     * preview UI binds each select to a key on this array.
     *
     * @var array<string, string>
     */
    public array $conflictResolutions = [];

    /**
     * Inline error surfaced beneath the file input when the uploaded
     * YAML fails to parse. Cleared on every fresh `parseUpload()`.
     */
    public string $importError = '';

    /**
     * Livewire validation rules. The file-size cap is in KB
     * (matching Livewire's `max` rule semantics); the extensions
     * allow-list is `.yaml` / `.yml` only, matching the community-
     * corpus filename convention.
     *
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'importFile' => ['nullable', 'file', 'extensions:yaml,yml', 'max:1024'],
        ];
    }

    /**
     * Enters inline-edit mode for the given alias row.
     *
     * Loads the row's current `generalized_pattern` into the editing
     * buffer + clears any prior preview result so the side pane is
     * empty until the user types.
     */
    public function startEdit(int $aliasId, CurrentUser $currentUser, DatabaseManager $db): void
    {
        $row = $db->connection()
            ->table('merchant_aliases')
            ->where('user_id', $currentUser->user()->id)
            ->where('id', $aliasId)
            ->first(['id', 'generalized_pattern']);

        if ($row === null) {
            $this->flashMessage = 'Alias not found (it may have been deleted in another tab).';

            return;
        }

        $this->editingId = $aliasId;
        $this->editingPattern = isset($row->generalized_pattern) && is_string($row->generalized_pattern)
            ? $row->generalized_pattern
            : '';
        $this->previewResult = [];
    }

    /**
     * Cancels inline-edit mode without persisting the change. Clears
     * the preview pane.
     */
    public function cancelEdit(): void
    {
        $this->editingId = 0;
        $this->editingPattern = '';
        $this->previewResult = [];
    }

    /**
     * Livewire `updated*` hook for `editingPattern`. Fires on every
     * accepted (debounced) update of the inline-edit input.
     *
     * The 400ms debounce + 500-row scan cap together bound the cost of
     * the live preview so a noisy keystroke stream cannot launch a
     * near-unbounded LIKE-style scan against the user's history.
     * Patterns shorter than three characters are short-circuited at
     * the DTO layer (`withoutMatches(...)`) so the side pane shows a
     * calm explanatory line instead of running the scan at all.
     */
    public function updatedEditingPattern(AliasMatchPreviewQuery $previewQuery, CurrentUser $currentUser): void
    {
        $value = trim($this->editingPattern);
        if (mb_strlen($value) < 3) {
            $this->previewResult = [
                'total' => 0,
                'first5' => [],
                'emptyMessage' => 'Pattern is too short to test.',
            ];

            return;
        }

        $dto = $previewQuery->preview($value, $currentUser->user()->id);
        $first5 = [];
        foreach ($dto->first5 as $row) {
            /** @var \stdClass $row */
            $first5[] = [
                'description' => isset($row->description) && is_string($row->description) ? $row->description : '',
                'counterparty_name' => isset($row->counterparty_name) && is_string($row->counterparty_name) ? $row->counterparty_name : '',
                'booked_at' => isset($row->booked_at) && is_string($row->booked_at) ? $row->booked_at : '',
            ];
        }
        $this->previewResult = [
            'total' => $dto->total,
            'first5' => $first5,
            'emptyMessage' => $dto->emptyMessage,
        ];
    }

    /**
     * Persists the inline-edited `generalized_pattern` to the row.
     * Always re-fetches the row with `where('user_id', current)` so a
     * tampered Livewire payload pointing at another user's id hits the
     * 404-not-403 boundary rather than silently mutating that row.
     */
    public function saveAlias(int $aliasId, CurrentUser $currentUser, DatabaseManager $db, Clock $clock): void
    {
        $value = trim($this->editingPattern);
        if ($value === '') {
            $this->flashMessage = 'Generalized pattern cannot be empty.';

            return;
        }

        // Cross-user attempts return 0 affected rows; surface a calm
        // "not found" flash and clear editing state, the same message
        // shown for a legitimately-stale row. The where('user_id', …)
        // clause is the structural guard — the flash is only UI.
        $affected = $db->connection()
            ->table('merchant_aliases')
            ->where('user_id', $currentUser->user()->id)
            ->where('id', $aliasId)
            ->update([
                'generalized_pattern' => $value,
                'updated_at' => $clock->now()->toDateTimeString(),
            ]);

        if ($affected === 0) {
            $this->editingId = 0;
            $this->editingPattern = '';
            $this->previewResult = [];
            $this->flashMessage = 'Alias not found (it may have been deleted in another tab).';

            return;
        }

        $this->editingId = 0;
        $this->editingPattern = '';
        $this->previewResult = [];
        $this->flashMessage = 'Alias updated.';
    }

    /**
     * Deletes the alias row. User-scoped — cross-user attempts hit
     * 404-not-403 via NotFoundHttpException.
     */
    public function deleteAlias(int $aliasId, CurrentUser $currentUser, DatabaseManager $db): void
    {
        // The where('user_id', current) clause is the structural
        // ownership guard; cross-user ids hit zero affected rows and
        // surface a calm "not found" flash instead of throwing — a
        // tampered payload sees the same calm UI a stale row would.
        $affected = $db->connection()
            ->table('merchant_aliases')
            ->where('user_id', $currentUser->user()->id)
            ->where('id', $aliasId)
            ->delete();

        if ($affected === 0) {
            $this->flashMessage = 'Alias not found (it may have been deleted in another tab).';

            return;
        }

        $this->selectedIds = array_values(array_filter(
            $this->selectedIds,
            static fn (int $id): bool => $id !== $aliasId,
        ));
        $this->flashMessage = 'Alias deleted.';
    }

    /**
     * Opens the bulk-merge confirm dialog. Loads the selected rows
     * user-scoped, computes the LCP of their generalized patterns, and
     * prefills the dialog inputs. The LCP service refuses to surface a
     * 1-3 char prefix; in that case the dialog opens with an empty
     * `mergeGeneralizedPattern` and the user must type a longer
     * pattern before Confirm enables.
     */
    public function openMergeModal(LongestCommonPrefix $lcp, CurrentUser $currentUser, DatabaseManager $db): void
    {
        $uniqueIds = array_values(array_unique(array_map('intval', $this->selectedIds)));
        if (count($uniqueIds) < 2) {
            $this->flashMessage = 'Select at least two aliases to merge.';

            return;
        }

        $rows = $db->connection()
            ->table('merchant_aliases')
            ->where('user_id', $currentUser->user()->id)
            ->whereIn('id', $uniqueIds)
            ->orderBy('id')
            ->get(['id', 'generalized_pattern', 'friendly_name']);

        if ($rows->count() !== count($uniqueIds)) {
            // Cross-user / stale id surface — calm flash, no modal,
            // clear the selection so the user can pick rows again.
            // The structural guard is the user_id clause; the flash
            // is only the UI affordance.
            $this->selectedIds = [];
            $this->flashMessage = 'One or more selected aliases were not found.';

            return;
        }

        $patterns = [];
        $firstFriendly = '';
        foreach ($rows as $row) {
            /** @var \stdClass $row */
            $generalized = isset($row->generalized_pattern) && is_string($row->generalized_pattern)
                ? $row->generalized_pattern : '';
            $friendly = isset($row->friendly_name) && is_string($row->friendly_name)
                ? $row->friendly_name : '';
            $patterns[] = $generalized;
            if ($firstFriendly === '' && $friendly !== '') {
                $firstFriendly = $friendly;
            }
        }

        try {
            $prefix = $lcp->compute($patterns);
        } catch (InvalidArgumentException) {
            $prefix = '';
        }

        $this->mergeGeneralizedPattern = $prefix;
        $this->mergeFriendlyName = $firstFriendly;
        $this->showMergeModal = true;
    }

    /**
     * Cancels the bulk-merge dialog without writing anything.
     */
    public function cancelMerge(): void
    {
        $this->showMergeModal = false;
        $this->mergeGeneralizedPattern = '';
        $this->mergeFriendlyName = '';
    }

    /**
     * Commits the bulk-merge via MergeMerchantAliases. On failure
     * (cross-user id, validation error) the dialog clears + a calm
     * flash surfaces the cause without crashing the page. The
     * structural ownership guard is the action's user-scoped row
     * loader; the flash is only the UI surface.
     */
    public function confirmMerge(MergeMerchantAliases $merge, CurrentUser $currentUser, LoggerInterface $logger): void
    {
        $friendly = trim($this->mergeFriendlyName);
        $generalized = trim($this->mergeGeneralizedPattern);
        if ($friendly === '' || $generalized === '') {
            $this->flashMessage = 'Friendly name and generalized pattern are both required.';

            return;
        }

        $uniqueIds = array_values(array_unique(array_map('intval', $this->selectedIds)));

        try {
            ($merge)($currentUser->user(), $uniqueIds, $friendly, $generalized);
        } catch (NotFoundHttpException) {
            $this->showMergeModal = false;
            $this->selectedIds = [];
            $this->flashMessage = 'One or more aliases were not found (they may have been deleted in another tab).';

            return;
        } catch (Throwable $e) {
            $logger->error('AliasesSettingsPage: bulk-merge failed.', [
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
            ]);
            $this->showMergeModal = false;
            $this->flashMessage = sprintf('Merge failed (%s).', $e::class);

            return;
        }

        $this->showMergeModal = false;
        $this->selectedIds = [];
        $this->mergeFriendlyName = '';
        $this->mergeGeneralizedPattern = '';
        $this->flashMessage = 'Aliases merged.';
    }

    /**
     * Streams the user's aliases as a downloadable `aliases.yaml`. The
     * ResponseFactory is method-DI'd so the page never hits the global
     * `response()` helper (rule C-1).
     */
    public function exportYaml(
        AliasYamlExporter $exporter,
        CurrentUser $currentUser,
        ResponseFactory $responses,
    ): StreamedResponse {
        $user = $currentUser->user();

        return $responses->streamDownload(
            static function () use ($exporter, $user): void {
                echo $exporter->export($user);
            },
            'aliases.yaml',
            ['Content-Type' => 'application/x-yaml'],
        );
    }

    /**
     * Parses the uploaded YAML file + computes the diff against the
     * user's current `merchant_aliases` table. Populates `$importDiff`
     * and seeds `$conflictResolutions` with safe 'keep' defaults.
     */
    public function parseUpload(AliasYamlImporter $importer, CurrentUser $currentUser): void
    {
        $this->validate();

        $this->importError = '';
        $this->importDiff = [];
        $this->conflictResolutions = [];

        if ($this->importFile === null) {
            $this->importError = 'No file uploaded.';

            return;
        }

        $path = $this->importFile->getRealPath();
        $contents = file_get_contents($path);
        if ($contents === false) {
            $this->importError = 'Could not read the uploaded file.';

            return;
        }

        try {
            $entries = $importer->parse($contents);
        } catch (InvalidArgumentException $e) {
            $this->importError = $e->getMessage();

            return;
        }

        $diff = $importer->diff($currentUser->user(), $entries);

        // Flatten the CorpusEntryDto instances into primitive-only
        // arrays so Livewire's wire payload synthesizer can round-trip
        // the property without a custom synth.
        $this->importDiff = [
            'new' => array_map([$this, 'flattenEntry'], $diff['new']),
            'unchanged' => array_map([$this, 'flattenEntry'], $diff['unchanged']),
            'conflicts' => array_map(
                fn (array $conflict): array => [
                    'entry' => $this->flattenEntry($conflict['entry']),
                    'existing_name' => $conflict['existing_name'],
                    'existing_generalized_pattern' => $conflict['existing_generalized_pattern'],
                ],
                $diff['conflicts'],
            ),
        ];

        $resolutions = [];
        foreach ($diff['conflicts'] as $conflict) {
            $resolutions[$conflict['entry']->pattern] = 'keep';
        }
        $this->conflictResolutions = $resolutions;
    }

    /**
     * Flattens a CorpusEntryDto into a primitive-only array so the
     * importDiff property survives Livewire's wire round-trip.
     *
     * @return array{pattern: string, generalized_pattern: string, name: string, category: ?string, region: ?string, contributor: string}
     */
    private function flattenEntry(CorpusEntryDto $entry): array
    {
        return [
            'pattern' => $entry->pattern,
            'generalized_pattern' => $entry->generalizedPattern,
            'name' => $entry->name,
            'category' => $entry->category,
            'region' => $entry->region,
            'contributor' => $entry->contributor,
        ];
    }

    /**
     * Commits the diffed entries to the user's `merchant_aliases`
     * table — both the new rows and the conflict rows whose resolution
     * the user set to 'replace'. Clears the import state.
     */
    public function confirmImport(AliasYamlImporter $importer, CurrentUser $currentUser): void
    {
        $newFlat = $this->importDiff['new'] ?? [];
        $conflictFlat = array_map(
            static fn (array $conflict): array => $conflict['entry'],
            $this->importDiff['conflicts'] ?? [],
        );

        $flatEntries = array_merge($newFlat, $conflictFlat);
        if ($flatEntries === []) {
            $this->flashMessage = 'Nothing to import.';
            $this->resetImportState();

            return;
        }

        $entries = array_map(
            static fn (array $flat): CorpusEntryDto => new CorpusEntryDto(
                pattern: is_string($flat['pattern'] ?? null) ? $flat['pattern'] : '',
                generalizedPattern: is_string($flat['generalized_pattern'] ?? null) ? $flat['generalized_pattern'] : '',
                name: is_string($flat['name'] ?? null) ? $flat['name'] : '',
                category: isset($flat['category']) && is_string($flat['category']) ? $flat['category'] : null,
                region: isset($flat['region']) && is_string($flat['region']) ? $flat['region'] : null,
                contributor: is_string($flat['contributor'] ?? null) ? $flat['contributor'] : 'user',
            ),
            $flatEntries,
        );

        $changed = $importer->apply($currentUser->user(), $entries, $this->conflictResolutions);
        $this->flashMessage = sprintf('Imported %d aliases.', $changed);
        $this->resetImportState();
    }

    /**
     * Cancels the import preview without writing anything.
     */
    public function cancelImport(): void
    {
        $this->resetImportState();
    }

    /**
     * Clears the post-write flash message — bound to the toast's
     * dismiss handler.
     */
    public function clearFlash(): void
    {
        $this->flashMessage = '';
    }

    /**
     * Renders the aliases list paginated 25/page. Every query is
     * user-scoped via the injected CurrentUser; the pattern column is
     * loaded so the inline-edit panel can show the immutable raw
     * description alongside the editable generalized pattern.
     */
    public function render(ViewFactory $views, CurrentUser $currentUser, DatabaseManager $db): View
    {
        $userId = $currentUser->user()->id;

        $paginator = $db->connection()
            ->table('merchant_aliases')
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->paginate(
                perPage: $this->perPage,
                columns: ['id', 'pattern', 'generalized_pattern', 'friendly_name', 'merged_from', 'created_at'],
            );

        $view = $views->make('import::livewire.aliases-settings-page', [
            'aliases' => $paginator,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'Aliases · beatrax']);

        return $view;
    }

    private function resetImportState(): void
    {
        $this->importFile = null;
        $this->importDiff = [];
        $this->conflictResolutions = [];
        $this->importError = '';
    }
}
