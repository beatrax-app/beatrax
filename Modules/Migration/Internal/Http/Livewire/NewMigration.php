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
use Modules\Migration\Internal\Parsers\Support\ZipExtractor;
use Modules\Migration\Models\MigrationRun;
use Modules\Migration\Public\Actions\CheckForUpdates;
use Modules\Migration\Public\Actions\StartMigrationRun;
use Modules\Migration\Public\Enums\MigrationRunStatus;
use Modules\Migration\Public\Enums\MigrationSourceProduct;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @link ../../../../../.docs/features/migration/architecture.md
 */
final class NewMigration extends Component
{
    private const UNRECOGNISED_EXPORT_MESSAGE = 'This doesn\'t look like a YNAB4, nYNAB, or Actual export we can read. Check the file and try again.';

    use WithFileUploads;

    // Rejects an obviously-oversized upload before it ever reaches disk;
    // ZipExtractor itself separately enforces the post-extraction
    // decompression cap.
    private const MAX_UPLOAD_KB = 204800;

    public ?TemporaryUploadedFile $file = null;

    #[Validate('required|in:ynab4,nynab,actual')]
    public string $sourceProduct = MigrationSourceProduct::Ynab4->value;

    // Set (and the format <select> locked) when mounted with a
    // ?reconcile_of={run} query parameter that resolves to one of this
    // user's own CONFIRMED runs. Null for a first-time import.
    public ?int $reconcileOf = null;

    public bool $formatLocked = false;

    // One-shot error surfaced inline when parse-or-stage raises (corrupt
    // file, unknown format, zip-bomb/zip-slip guard trip) — a fixed
    // user-facing string, never the raw exception message.
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
            // extensions:zip checks the client-supplied filename extension;
            // mimes:zip additionally sniffs the real content via finfo — see
            // the architecture doc's "Wizard pages" section for why both
            // run as defence-in-depth against a renamed non-ZIP file.
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
            'file.max' => 'That file is too large for a migration export.',
            'file.extensions' => self::UNRECOGNISED_EXPORT_MESSAGE,
            'file.mimes' => self::UNRECOGNISED_EXPORT_MESSAGE,
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
            'ynab4' => "Export your full budget as a ZIP file from YNAB4's File → Export menu.",
            'nynab' => 'Export your budget from nYNAB via File → Export Budget, then zip up the exported CSV files.',
            'actual' => "Export your budget as a ZIP file from Actual Budget's Settings → Export data.",
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
                // Wraps extract() too (not just parse-or-stage), so cleanup()
                // still runs even when extract() itself threw partway — safe
                // to call when nothing was extracted, and safe twice.
                $extractor->cleanup();
            }
        } catch (Throwable $e) {
            $logger->error('NewMigration: parse/stage failed.', [
                'source_product' => $this->sourceProduct,
                'reconcile_of' => $this->reconcileOf,
                'filename' => $originalFilename,
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
                'exception_trace' => $e->getTraceAsString(),
            ]);
            $this->uploadError = self::UNRECOGNISED_EXPORT_MESSAGE;

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

    private function sanitiseFilename(string $original): string
    {
        // Reduces to a safe [A-Za-z0-9_-]+.zip shape — the user-supplied
        // original name is never used to construct disk paths directly.
        $stem = pathinfo($original, PATHINFO_FILENAME);
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $stem);
        $stemPart = ($safe === null || $safe === '') ? 'upload' : $safe;

        return $stemPart.'.zip';
    }
}
