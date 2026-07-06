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
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Step 1 of the wizard (Req 1/11): the user picks a source product (YNAB4 /
 * nYNAB / Actual Budget), uploads a ZIP export, and on submit the file is
 * extracted (`ZipExtractor` — built in Plan 03 but never wired until this
 * plan, exactly per `StartMigrationRun`'s own docblock: "extracting an
 * uploaded ZIP ... happens upstream of this action, in the wizard's own
 * upload-handling step (Plan 08)"), parsed, and staged. Nothing is written
 * to a domain table by this component — either `StartMigrationRun` (first
 * import) or `CheckForUpdates` (reconciliation) owns that boundary.
 *
 * Mirrors `Modules\Import\Internal\Http\Livewire\UploadWizard`'s shape:
 * `WithFileUploads`, a locked-format-on-reconcile `<select>`, `rules()`
 * enforcing size + extension, a `submit()` that logs the full trace on any
 * Throwable and surfaces the verbatim Req 1 rose-banner copy, never the raw
 * exception message.
 *
 * Reconciliation entry (Req 10): when mounted with `?reconcile_of={run}`,
 * the query param is resolved against THIS user's own confirmed runs only
 * (T-13.5-24) — a foreign, unconfirmed, or missing run id is silently
 * ignored (falls back to a first-time-import mount) rather than erroring,
 * since this route carries no per-entity id and must stay reachable by any
 * authenticated user (T-13.5-04 precedent). On submit with a resolved
 * `$reconcileOf`, this calls `CheckForUpdates($reconcileOf, $user,
 * $sourceProduct, $extractedPath)` directly — matching the ACTUAL Plan 07
 * interface (`CheckForUpdates` stages the newer export itself internally),
 * not the plan's own now-stale prose describing a separate pre-staged
 * `$newRunId` argument (see 13.5-07-SUMMARY.md's documented "Interface
 * Deviation from Plan Prose").
 */
final class NewMigration extends Component
{
    use WithFileUploads;

    /**
     * Upload-size ceiling for a migration export ZIP. `ZipExtractor` itself
     * enforces the post-extraction decompression cap (200 MB total
     * uncompressed, T-13.5-05 zip-bomb guard); this rule rejects an
     * obviously-oversized upload before it ever reaches disk.
     */
    private const MAX_UPLOAD_KB = 204800;

    public ?TemporaryUploadedFile $file = null;

    #[Validate('required|in:ynab4,nynab,actual')]
    public string $sourceProduct = 'ynab4';

    /**
     * Set (and the format `<select>` locked) when mounted with a
     * `?reconcile_of={run}` query parameter that resolves to one of this
     * user's own CONFIRMED runs. Null for a first-time import.
     */
    public ?int $reconcileOf = null;

    public bool $formatLocked = false;

    /**
     * One-shot error surfaced inline when parse-or-stage raises (corrupt
     * file, unknown format, zip-bomb/zip-slip guard trip). Mirrors
     * `UploadWizard::$uploadError`'s shape; the exact Req 1 Copywriting
     * Contract string, never the raw exception message.
     */
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
            ->where('status', 'confirmed')
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
            'file' => ['required', 'file', 'max:'.self::MAX_UPLOAD_KB, 'extensions:zip'],
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
            'file.extensions' => "This doesn't look like a YNAB4, nYNAB, or Actual export we can read. Check the file and try again.",
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
            $extractedPath = $extractor->extract($tmp);

            try {
                $run = $this->reconcileOf !== null
                    ? $checkForUpdates($this->reconcileOf, $user, $this->sourceProduct, $extractedPath)
                    : $startMigrationRun($user, $this->sourceProduct, $extractedPath, $originalFilename);
            } finally {
                // The extracted directory is scratch — parse-or-stage reads
                // it synchronously inside this single call; nothing needs it
                // afterward, on success or failure alike.
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
            $this->uploadError = "This doesn't look like a YNAB4, nYNAB, or Actual export we can read. Check the file and try again.";

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

    /**
     * Sanitises an uploaded filename to a safe `[A-Za-z0-9_-]+\.zip` shape —
     * the user-supplied original name is never used to construct disk paths
     * directly. Mirrors `UploadWizard::sanitiseFilename()`.
     */
    private function sanitiseFilename(string $original): string
    {
        $stem = pathinfo($original, PATHINFO_FILENAME);
        $safe = preg_replace('/[^A-Za-z0-9_-]+/', '_', $stem);
        $stemPart = ($safe === null || $safe === '') ? 'upload' : $safe;

        return $stemPart.'.zip';
    }
}
