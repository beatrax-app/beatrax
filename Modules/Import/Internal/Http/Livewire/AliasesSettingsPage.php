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
 * @link ../../../../../.docs/features/import/architecture.md#merchant-aliases
 */
#[Layout('layouts.app')]
final class AliasesSettingsPage extends Component
{
    use WithFileUploads;

    public int $perPage = 25;

    // 0 = no row in edit mode; stored as int (not ?int) so the Blade
    // template can branch on a single typed comparison.
    public int $editingId = 0;

    // Bound via wire:model.live.debounce.400ms; each accepted change
    // fires updatedEditingPattern() to run the live preview probe.
    public string $editingPattern = '';

    /**
     * @var array{total: int, first5: list<array{description: string, counterparty_name: string, booked_at: string}>, emptyMessage: ?string}|array{}
     */
    public array $previewResult = [];

    // "Merge selected" enables only when ≥2 unique ids are selected (the
    // LCP service itself rejects size-1 inputs; this is the UI gate).
    /**
     * @var list<int>
     */
    public array $selectedIds = [];

    public bool $showMergeModal = false;

    // Prefilled from the first selected row's existing friendly name;
    // the user can edit before confirming.
    public string $mergeFriendlyName = '';

    // Prefilled LCP of the selected rows' generalized_pattern; stays
    // empty when the LCP service rejects the set (no 4-char shared
    // prefix), forcing the user to type a pattern manually.
    public string $mergeGeneralizedPattern = '';

    public string $flashMessage = '';

    // Populated by WithFileUploads from the file input; parseUpload()
    // reads it via getRealPath() into AliasYamlImporter.
    public ?TemporaryUploadedFile $importFile = null;

    /**
     * @var array{new?: list<array<string, mixed>>, unchanged?: list<array<string, mixed>>, conflicts?: list<array{entry: array<string, mixed>, existing_name: string, existing_generalized_pattern: string}>}
     */
    public array $importDiff = [];

    // Populated by parseUpload() with 'keep' defaults; the conflict
    // preview UI binds each select to a key on this array.
    /**
     * @var array<string, string>
     */
    public array $conflictResolutions = [];

    public string $importError = '';

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'importFile' => ['nullable', 'file', 'extensions:yaml,yml', 'max:1024'],
        ];
    }

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

    public function cancelEdit(): void
    {
        $this->editingId = 0;
        $this->editingPattern = '';
        $this->previewResult = [];
    }

    // 400ms debounce + 500-row scan cap bound the cost of the live
    // preview so a noisy keystroke stream can't launch a near-unbounded
    // scan; patterns <3 chars short-circuit via withoutMatches() before
    // the scan runs at all.
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

    public function cancelMerge(): void
    {
        $this->showMergeModal = false;
        $this->mergeGeneralizedPattern = '';
        $this->mergeFriendlyName = '';
    }

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

    // ResponseFactory is method-DI'd so the page never hits the global
    // response() helper.
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

    public function cancelImport(): void
    {
        $this->resetImportState();
    }

    public function clearFlash(): void
    {
        $this->flashMessage = '';
    }

    // `pattern` is loaded (read-only) so the inline-edit panel can show
    // the immutable raw description alongside the editable
    // generalized_pattern.
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
