<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Http\Livewire;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Community\Public\Dto\CorpusEntryDto;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\HoldsFlashMessage;
use Modules\Core\Public\Support\Brand;
use Modules\Core\Public\Support\DerivedRowId;
use Modules\Core\Public\Support\Fmt;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Import\Internal\Exceptions\AliasFileRejectedException;
use Modules\Import\Internal\Services\AliasYamlExporter;
use Modules\Import\Internal\Services\AliasYamlImporter;
use Modules\Import\Internal\Services\LongestCommonPrefix;
use Modules\Import\Public\Actions\MergeMerchantAliases;
use Modules\Import\Public\Services\AliasMatchPreviewQuery;
use Modules\Import\Public\Services\MerchantNameResolver;
use Modules\Mobile\Public\Services\ShareSheetExport;
use Modules\Sync\Public\Events\EntityMutated;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class AliasesSettingsPage extends Component
{
    use HoldsFlashMessage;
    use WithFileUploads;

    private const int PER_PAGE = 25;

    // A SQL LIMIT, and no control on this page offers to change it: the list
    // is one fixed page size with a paginator under it. Locked rather than
    // clamped, because a clamp would legitimise a client-chosen limit that
    // nothing here ever asks the reader to pick. Zero divided the paginator.
    #[Locked]
    public int $perPage = self::PER_PAGE;

    // 0 means no row is in edit mode; int rather than ?int so the blade
    // branches on one typed comparison.
    public int $editingId = 0;

    public string $editingPattern = '';

    /**
     * @var array{total: int, first5: list<array{description: string, counterparty_name: string, postedAt: string}>, emptyMessage: ?string}|array{}
     */
    public array $previewResult = [];

    // The UI gate for "Merge selected"; LongestCommonPrefix rejects a
    // size-1 input on its own.
    /**
     * @var list<int>
     */
    public array $selectedIds = [];

    public bool $showMergeModal = false;

    public string $mergeFriendlyName = '';

    // Empty when the rows share no 4-character prefix, which forces the
    // user to type a pattern by hand.
    public string $mergeGeneralizedPattern = '';

    public ?TemporaryUploadedFile $importFile = null;

    /**
     * @var array{new?: list<array<string, mixed>>, unchanged?: list<array<string, mixed>>, conflicts?: list<array{entry: array<string, mixed>, existing_name: string, existing_generalized_pattern: string}>}
     */
    public array $importDiff = [];

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

    public function startEdit(int|string $aliasId, CurrentUser $currentUser, DatabaseManager $db): void
    {
        $aliasId = DerivedRowId::fromWire($aliasId);

        $row = $db->connection()
            ->table('merchant_aliases')
            ->where('user_id', $currentUser->user()->id)
            ->where('id', $aliasId)
            ->first(['id', 'generalized_pattern']);

        if ($row === null) {
            $this->flashMessage = Lang::get('import::aliases.errors.not_found');

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

    // The 400ms debounce and the 500-row scan cap together bound what a fast
    // keystroke stream can cost.
    public function updatedEditingPattern(AliasMatchPreviewQuery $previewQuery, CurrentUser $currentUser): void
    {
        $value = trim($this->editingPattern);
        if (mb_strlen($value) < AliasMatchPreviewQuery::MIN_PATTERN_LENGTH) {
            $this->previewResult = [
                'total' => 0,
                'first5' => [],
                'emptyMessage' => Lang::get('import::aliases.errors.too_short'),
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
                'postedAt' => isset($row->posted_at) && is_string($row->posted_at) ? Fmt::shortDate($row->posted_at) : '',
            ];
        }
        $this->previewResult = [
            'total' => $dto->total,
            'first5' => $first5,
            'emptyMessage' => $dto->emptyMessage,
        ];
    }

    public function saveAlias(int|string $aliasId, CurrentUser $currentUser, DatabaseManager $db, Clock $clock, Dispatcher $events, MerchantNameResolver $resolver): void
    {
        $aliasId = DerivedRowId::fromWire($aliasId);

        $value = trim($this->editingPattern);
        if ($value === '') {
            $this->flashMessage = Lang::get('import::aliases.errors.pattern_empty');

            return;
        }

        // The same floor the preview enforces. Below it the reader is shown
        // "too short to test" and could still save, so the one pattern nobody
        // has ever seen the effect of was the only one that could be saved
        // blind.
        if (mb_strlen($value) < AliasMatchPreviewQuery::MIN_PATTERN_LENGTH) {
            $this->flashMessage = Lang::get('import::aliases.errors.too_short');

            return;
        }

        // The where('user_id', …) clause is the structural guard: a cross-user
        // id affects 0 rows and gets the same flash a stale row would.
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
            $this->flashMessage = Lang::get('import::aliases.errors.not_found');

            return;
        }

        // Renaming a counterparty is one of the commonest edits in the app and
        // none of these three writes reached the op log, so it never left the
        // device it was made on.
        $events->dispatch(new EntityMutated(
            table: 'merchant_aliases',
            pk: $aliasId,
            userId: $currentUser->user()->id,
            mutationType: 'edit',
            dirtyFields: ['generalized_pattern' => $value],
        ));

        // The resolver memoises this reader's aliases for the life of the
        // container, so an edit it is not told about leaves the next resolve
        // answering with the pattern the reader just replaced.
        $resolver->forget($currentUser->user()->id);

        $this->editingId = 0;
        $this->editingPattern = '';
        $this->previewResult = [];
        $this->flashMessage = Lang::get('import::aliases.flash.updated');
    }

    public function deleteAlias(int|string $aliasId, CurrentUser $currentUser, DatabaseManager $db, Dispatcher $events, MerchantNameResolver $resolver): void
    {
        $aliasId = DerivedRowId::fromWire($aliasId);

        $affected = $db->connection()
            ->table('merchant_aliases')
            ->where('user_id', $currentUser->user()->id)
            ->where('id', $aliasId)
            ->delete();

        if ($affected === 0) {
            $this->flashMessage = Lang::get('import::aliases.errors.not_found');

            return;
        }

        $events->dispatch(new EntityMutated(
            table: 'merchant_aliases',
            pk: $aliasId,
            userId: $currentUser->user()->id,
            mutationType: 'delete',
        ));

        $resolver->forget($currentUser->user()->id);

        $this->selectedIds = array_values(array_filter(
            $this->selectedIds,
            static fn (int $id): bool => $id !== $aliasId,
        ));
        $this->flashMessage = Lang::get('import::aliases.flash.deleted');
    }

    public function openMergeModal(LongestCommonPrefix $lcp, CurrentUser $currentUser, DatabaseManager $db): void
    {
        $uniqueIds = array_values(array_unique(array_map('intval', $this->selectedIds)));
        if (count($uniqueIds) < 2) {
            $this->flashMessage = Lang::get('import::aliases.errors.select_two');

            return;
        }

        $rows = $db->connection()
            ->table('merchant_aliases')
            ->where('user_id', $currentUser->user()->id)
            ->whereIn('id', $uniqueIds)
            ->orderBy('id')
            ->get(['id', 'generalized_pattern', 'friendly_name']);

        if ($rows->count() !== count($uniqueIds)) {
            $this->selectedIds = [];
            $this->flashMessage = Lang::get('import::aliases.errors.some_not_found');

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
            $this->flashMessage = Lang::get('import::aliases.errors.both_required');

            return;
        }

        $uniqueIds = array_values(array_unique(array_map('intval', $this->selectedIds)));

        try {
            ($merge)($currentUser->user(), $uniqueIds, $friendly, $generalized);
        } catch (NotFoundHttpException) {
            $this->showMergeModal = false;
            $this->selectedIds = [];
            $this->flashMessage = Lang::get('import::aliases.errors.merge_not_found');

            return;
        } catch (Throwable $e) {
            $logger->error('AliasesSettingsPage: bulk-merge failed.', SafeExceptionContext::describe($e));
            $this->showMergeModal = false;
            $this->flashMessage = Lang::get('import::aliases.errors.merge_failed', [
                'class' => SafeExceptionContext::shortName($e),
            ]);

            return;
        }

        $this->showMergeModal = false;
        $this->selectedIds = [];
        $this->mergeFriendlyName = '';
        $this->mergeGeneralizedPattern = '';
        $this->flashMessage = Lang::get('import::aliases.flash.merged');
    }

    public function exportYaml(
        AliasYamlExporter $exporter,
        CurrentUser $currentUser,
        ResponseFactory $responses,
        ShareSheetExport $shareSheet,
    ): ?StreamedResponse {
        $user = $currentUser->user();

        // Import is offered on every shell; export was offered on every shell
        // and delivered on only some. Where the WebView drops the download the
        // file leaves through the OS share sheet instead.
        if ($shareSheet->replacesWebViewDownload()) {
            $this->flashMessage = $shareSheet->export('aliases.yaml', $exporter->export($user))->message();

            return null;
        }

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
            $this->importError = Lang::get('import::aliases.errors.no_file');

            return;
        }

        $path = $this->importFile->getRealPath();
        $contents = file_get_contents($path);
        if ($contents === false) {
            $this->importError = Lang::get('import::aliases.errors.unreadable');

            return;
        }

        try {
            $entries = $importer->parse($contents);
        } catch (AliasFileRejectedException $rejected) {
            $this->importError = $rejected->sentence();

            return;
        }

        $diff = $importer->diff($currentUser->user(), $entries);

        // Primitives only, or the wire payload needs a custom Livewire synth
        // to round-trip the DTOs.
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
            $this->flashMessage = Lang::get('import::aliases.flash.nothing');
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
        $this->flashMessage = Lang::choice('import::aliases.flash.imported', $changed);
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

    // `pattern` is read-only; the edit panel shows it beside the editable
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

        $view->extends('layouts.app', ['title' => Lang::get('import::aliases.page_title').Brand::TITLE_SUFFIX]);

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
