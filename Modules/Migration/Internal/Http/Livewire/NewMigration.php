<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Livewire\Attributes\Validate;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Migration\Internal\Parsers\Support\ZipExtractor;
use Modules\Migration\Models\MigrationRun;
use Modules\Migration\Public\Actions\CheckForUpdates;
use Modules\Migration\Public\Actions\StartMigrationRun;
use Modules\Migration\Public\Enums\MigrationRunStatus;
use Modules\Migration\Public\Enums\MigrationSourceProduct;
use Psr\Log\LoggerInterface;
use Throwable;

final class NewMigration extends Component
{
    use WithFileUploads;

    // Rejects an oversized upload before it reaches disk; ZipExtractor
    // separately caps the post-extraction size.
    private const MAX_UPLOAD_KB = 204800;

    public ?TemporaryUploadedFile $file = null;

    #[Validate('required|in:ynab4,nynab,actual')]
    public string $sourceProduct = MigrationSourceProduct::Ynab4->value;

    // Set from a ?reconcile_of={run} parameter resolving to one of this user's
    // own confirmed runs; null for a first-time import.
    public ?int $reconcileOf = null;

    public bool $formatLocked = false;

    // A fixed user-facing string when parse-or-stage raises, never the raw
    // exception message.
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
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // extensions:zip trusts the filename; mimes:zip sniffs the content
            // via finfo. Both run, so a renamed non-ZIP fails either way.
            'file' => ['required', 'file', 'max:'.self::MAX_UPLOAD_KB, 'extensions:zip', 'mimes:zip'],
            'sourceProduct' => ['required', 'in:ynab4,nynab,actual'],
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
        return match ($sourceProduct) {
            'ynab4' => 'YNAB4',
            'nynab' => 'New YNAB (nYNAB)',
            'actual' => 'Actual Budget',
            default => '',
        };
    }

    public function formatHint(): string
    {
        return match ($this->sourceProduct) {
            'ynab4' => Lang::get('migration::new.hints.ynab4'),
            'nynab' => Lang::get('migration::new.hints.nynab'),
            'actual' => Lang::get('migration::new.hints.actual'),
            default => '',
        };
    }

    public function submit(
        StartMigrationRun $startMigrationRun,
        CheckForUpdates $checkForUpdates,
        ZipExtractor $extractor,
        CurrentUser $currentUser,
        UrlGenerator $urls,
        LoggerInterface $logger,
    ): void {
        $this->uploadError = null;
        $this->validate();

        if ($this->file === null) {
            return;
        }

        $user = $currentUser->user();
        $tmp = $this->file->getRealPath();
        $originalFilename = $this->sanitiseFilename($this->file->getClientOriginalName());

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
                'exception_trace' => $e->getTraceAsString(),
            ]);
            $this->uploadError = $this->unrecognisedExportMessage();

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

    private function unrecognisedExportMessage(): string
    {
        // One fixed line shared by the validation messages and submit()'s
        // banner, never the raw exception message.
        return Lang::get('migration::new.errors.unrecognised');
    }

    private function sanitiseFilename(string $original): string
    {
        // The user-supplied name never reaches a disk path unreduced.
        $stem = pathinfo($original, PATHINFO_FILENAME);
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $stem);
        $stemPart = ($safe === null || $safe === '') ? 'upload' : $safe;

        return $stemPart.'.zip';
    }
}
