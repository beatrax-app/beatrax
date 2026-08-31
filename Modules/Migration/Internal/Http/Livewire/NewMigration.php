<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Http\Livewire;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Core\Public\Support\SafeTrace;
use Modules\Import\Public\Services\UploadFilename;
use Modules\Migration\Internal\Actions\CheckForUpdates;
use Modules\Migration\Internal\Actions\StartMigrationRun;
use Modules\Migration\Internal\Enums\MigrationRunStatus;
use Modules\Migration\Internal\Enums\MigrationSourceProduct;
use Modules\Migration\Internal\Exceptions\ArchiveReaderUnavailableException;
use Modules\Migration\Internal\Exceptions\UnrecognizedMigrationFileException;
use Modules\Migration\Internal\Parsers\Support\ZipExtractor;
use Modules\Migration\Models\MigrationRun;
use Psr\Log\LoggerInterface;
use Throwable;

final class NewMigration extends Component
{
    use WithFileUploads;

    // Rejects an oversized upload before it reaches disk; ZipExtractor
    // separately caps the post-extraction size.
    private const int MAX_UPLOAD_KB = 204800;

    public ?TemporaryUploadedFile $file = null;

    public string $sourceProduct = MigrationSourceProduct::Ynab4->value;

    // Set from a ?reconcile_of={run} parameter resolving to one of this user's
    // own confirmed runs; null for a first-time import.
    public ?int $reconcileOf = null;

    public bool $formatLocked = false;

    // One of three fixed user-facing lines when parse-or-stage raises, chosen
    // by what raised, never the raw exception message.
    public ?string $uploadError = null;

    public function mount(Request $request, CurrentUser $currentUser): void
    {
        $reconcileOfParam = $request->query('reconcile_of');
        if (! is_numeric($reconcileOfParam)) {
            return;
        }

        $priorRun = MigrationRun::query()
            ->where('id', (int) $reconcileOfParam)
            ->where('user_id', $currentUser->user()->id)
            ->where('status', MigrationRunStatus::Confirmed->value)
            ->first();

        if ($priorRun === null) {
            return;
        }

        $this->reconcileOf = $priorRun->id;
        $this->sourceProduct = $priorRun->source_product;
        $this->formatLocked = true;
    }

    /**
     * @return array<string, list<string|Enum>>
     */
    public function rules(): array
    {
        return [
            // extensions:zip trusts the filename; mimes:zip sniffs the content
            // via finfo. Both run, so a renamed non-ZIP fails either way.
            'file' => ['required', 'file', 'max:'.self::MAX_UPLOAD_KB, 'extensions:zip', 'mimes:zip'],
            'sourceProduct' => ['required', Rule::enum(MigrationSourceProduct::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.max' => Lang::get('migration::new.errors.file_too_large'),
            'file.extensions' => $this->unrecognisedExportMessage(),
            'file.mimes' => $this->unrecognisedExportMessage(),
        ];
    }

    public function formatLabel(string $sourceProduct): string
    {
        return MigrationSourceProduct::tryFrom($sourceProduct)?->label() ?? '';
    }

    public function formatHint(): string
    {
        return match (MigrationSourceProduct::tryFrom($this->sourceProduct)) {
            MigrationSourceProduct::Ynab4 => Lang::get('migration::new.hints.ynab4'),
            MigrationSourceProduct::Nynab => Lang::get('migration::new.hints.nynab'),
            MigrationSourceProduct::Actual => Lang::get('migration::new.hints.actual'),
            null => '',
        };
    }

    public function submit(
        StartMigrationRun $startMigrationRun,
        CheckForUpdates $checkForUpdates,
        ZipExtractor $extractor,
        CurrentUser $currentUser,
        UrlGenerator $urls,
        LoggerInterface $logger,
        Application $app,
    ): void {
        $this->uploadError = null;
        $this->validate();

        if ($this->file === null) {
            return;
        }

        $user = $currentUser->user();
        $tmp = $this->file->getRealPath();
        $originalFilename = UploadFilename::sanitise($this->file->getClientOriginalName(), '.zip');

        try {
            try {
                $extractedPath = $extractor->extract($tmp);

                $run = $this->reconcileOf !== null
                    ? $checkForUpdates($this->reconcileOf, $user, $this->sourceProduct, $extractedPath)
                    : $startMigrationRun($user, $this->sourceProduct, $extractedPath, $originalFilename);
            } finally {
                // Wraps extract() too, so a partway throw still cleans up;
                // safe when nothing was extracted, and safe twice.
                $extractor->cleanup();
            }
        } catch (Throwable $e) {
            $logger->error('NewMigration: parse/stage failed.', [
                'source_product' => $this->sourceProduct,
                'reconcile_of' => $this->reconcileOf,
                'filename' => $originalFilename,
                ...SafeExceptionContext::describe($e),
                'exception_trace' => SafeTrace::cap($e, $app->basePath()),
            ]);
            $this->uploadError = $this->messageFor($e);

            return;
        }

        $this->redirect(
            $urls->route('migrations.preview', ['id' => $run->id]),
            navigate: false,
        );
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('migration::livewire.new-migration');
    }

    // Three endings the reader has to be able to tell apart: a file that is not
    // an export we read, an export this build has no reader for, and a fault of
    // ours. Only the first is answered by choosing a different file, so the
    // other two said so is a screen blaming the reader for our own failure.
    private function messageFor(Throwable $e): string
    {
        return match (true) {
            $e instanceof ArchiveReaderUnavailableException => Lang::get('migration::new.errors.archive_reader_unavailable'),
            $e instanceof UnrecognizedMigrationFileException => $this->unrecognisedExportMessage(),
            default => Lang::get('migration::new.errors.internal_detail', [
                'code' => SafeExceptionContext::shortName($e),
            ]),
        };
    }

    private function unrecognisedExportMessage(): string
    {
        // One fixed line shared by the validation messages and the banner when
        // the export itself is unreadable, never the raw exception message.
        return Lang::get('migration::new.errors.unrecognised');
    }
}
