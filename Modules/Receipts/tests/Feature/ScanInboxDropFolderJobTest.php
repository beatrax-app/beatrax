<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Account;
use Modules\Ledger\Models\Transaction;
use Modules\Receipts\Internal\Jobs\ScanInboxDropFolderJob;
use Modules\Receipts\Public\Actions\RecordReceipt;
use Modules\Receipts\Public\Enums\MatchOutcomeKind;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 5, 17, 12, 0, 0));

    $this->user = User::create([
        'username' => 'drop',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);

    // The matcher consumer bridges parsed receipts into the canonical
    // pipeline via the per-provider synthetic IBAN — seed the PayPal
    // account so the parsed receipt finds an Account row.
    Account::create([
        'user_id' => $this->user->id,
        'name' => 'PayPal',
        'slug' => 'paypal-drop',
        'kind' => 'paypal',
        'iban' => 'PAYPAL',
        'default_currency' => 'EUR',
    ]);

    $this->baseDir = storage_path('app/inbox-drop/'.$this->user->id);
    // Clean slate for the test directory tree.
    $files = new Filesystem;
    if ($files->isDirectory($this->baseDir)) {
        $files->deleteDirectory($this->baseDir);
    }
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();

    $files = new Filesystem;
    if (isset($this->baseDir) && $files->isDirectory($this->baseDir)) {
        $files->deleteDirectory($this->baseDir);
    }
});

function seedDroppedEml(string $baseDir, string $filename, string $fixture): string
{
    $files = new Filesystem;
    $files->ensureDirectoryExists($baseDir, 0700, recursive: true);
    $contents = (string) file_get_contents(base_path('Modules/Receipts/tests/fixtures/'.$fixture));
    $target = $baseDir.'/'.$filename;
    $files->put($target, $contents);

    return $target;
}

function runScanJob(int $userId): void
{
    $job = new ScanInboxDropFolderJob($userId);
    Container::getInstance()->call([$job, 'handle']);
}

it('constructor stores userId and uniqueId returns the string form', function (): void {
    $job = new ScanInboxDropFolderJob(42);
    expect($job->userId)->toBe(42);
    expect($job->uniqueId())->toBe('42');
    expect($job->uniqueFor())->toBe(600);
});

it('processes a dropped .eml file and moves it to processed/{YYYY-MM}/', function (): void {
    $sourcePath = seedDroppedEml($this->baseDir, 'paypal-1.eml', 'paypal/current-receipt.eml');

    runScanJob($this->user->id);

    expect(file_exists($sourcePath))->toBeFalse();
    $processedPath = $this->baseDir.'/processed/2026-05/paypal-1.eml';
    expect(file_exists($processedPath))->toBeTrue();

    // A file_imports row landed for the dropped receipt.
    expect(
        DB::table('file_imports')
            ->where('user_id', $this->user->id)
            ->where('source_filename', 'paypal-1.eml')
            ->exists()
    )->toBeTrue();
});

it('moves a parser-failing .eml to failed/{YYYY-MM}/ with a sibling .error.txt', function (): void {
    // Make a file unreadable so $files->get() returns false; the
    // string-typed __invoke on RecordReceipt then trips a TypeError
    // which the job's try/catch quarantines via failed/.
    if (posix_geteuid() === 0) {
        // root bypasses chmod, this test only works as a non-root user.
        $this->markTestSkipped('chmod-based unreadable-file test requires non-root euid.');
    }
    $sourcePath = $this->baseDir.'/broken.eml';
    $files = new Filesystem;
    $files->ensureDirectoryExists($this->baseDir, 0700, recursive: true);
    // Seed valid content; then chmod the file so reading fails.
    $files->put($sourcePath, 'valid content');
    chmod($sourcePath, 0000);

    try {
        runScanJob($this->user->id);

        expect(file_exists($sourcePath))->toBeFalse();
        $failedPath = $this->baseDir.'/failed/2026-05/broken.eml';
        $errorPath = $this->baseDir.'/failed/2026-05/broken.eml.error.txt';
        expect(file_exists($failedPath))->toBeTrue();
        expect(file_exists($errorPath))->toBeTrue();
    } finally {
        // Restore permissions before teardown so the cleanup works.
        if (file_exists($sourcePath)) {
            chmod($sourcePath, 0600);
        }
        $failedPath = $this->baseDir.'/failed/2026-05/broken.eml';
        if (file_exists($failedPath)) {
            chmod($failedPath, 0600);
        }
    }
});

it('is idempotent: re-running on the same processed folder is a no-op', function (): void {
    seedDroppedEml($this->baseDir, 'paypal-2.eml', 'paypal/current-receipt.eml');

    runScanJob($this->user->id);

    $countAfterFirst = DB::table('file_imports')
        ->where('user_id', $this->user->id)
        ->count();

    // Run a second time — no new top-level files, processed subfolder
    // is excluded from the scan loop.
    runScanJob($this->user->id);

    $countAfterSecond = DB::table('file_imports')
        ->where('user_id', $this->user->id)
        ->count();

    expect($countAfterSecond)->toBe($countAfterFirst);
});

it('does NOT touch files under another user\'s inbox-drop folder', function (): void {
    $other = User::create([
        'username' => 'other-drop',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);

    $otherDir = storage_path('app/inbox-drop/'.$other->id);
    $otherSourcePath = seedDroppedEml($otherDir, 'foreign.eml', 'paypal/current-receipt.eml');

    try {
        runScanJob($this->user->id);

        expect(file_exists($otherSourcePath))->toBeTrue();
        $otherProcessedPath = $otherDir.'/processed/2026-05/foreign.eml';
        expect(file_exists($otherProcessedPath))->toBeFalse();
    } finally {
        $files = new Filesystem;
        if ($files->isDirectory($otherDir)) {
            $files->deleteDirectory($otherDir);
        }
    }
});

it('returns silently when the user has been deleted between dispatch and worker pickup', function (): void {
    seedDroppedEml($this->baseDir, 'paypal-3.eml', 'paypal/current-receipt.eml');

    $job = new ScanInboxDropFolderJob(99999); // bogus userId
    Container::getInstance()->call([$job, 'handle']);

    // The file is still in the original (this->baseDir is for $this->user)
    // — bogus user has no folder so the job exits early.
    expect(file_exists($this->baseDir.'/paypal-3.eml'))->toBeTrue();
});

it('returns silently when the inbox-drop folder does not exist', function (): void {
    // No seeded files; the base folder was wiped in beforeEach.
    runScanJob($this->user->id);

    expect(true)->toBeTrue(); // The job didn't throw.
});

it('skips top-level files with non-eml/mbox extensions', function (): void {
    $files = new Filesystem;
    $files->ensureDirectoryExists($this->baseDir, 0700, recursive: true);
    $readmePath = $this->baseDir.'/README.txt';
    $files->put($readmePath, 'just notes');

    runScanJob($this->user->id);

    // README.txt remains in place (not moved to processed/ or failed/).
    expect(file_exists($readmePath))->toBeTrue();
});

// The scan discarded RecordReceipt's outcome, and RecordReceipt says itself it
// never writes to transactions. The file moved to processed/, the audit row
// read `parsed`, and the ledger stayed empty with no screen to notice on.
it('lands the canonical transaction for a dropped .eml, not just the audit row', function (): void {
    seedDroppedEml($this->baseDir, 'paypal-1.eml', 'paypal/current-receipt.eml');

    runScanJob($this->user->id);

    expect(DB::table('file_imports')->where('user_id', $this->user->id)->value('status'))->toBe('parsed');

    $transactions = Transaction::query()->where('user_id', $this->user->id)->get();
    expect($transactions)->toHaveCount(1);
    expect($transactions->first()->counterparty_name)->toBe('Netflix BV');
    expect($transactions->first()->amount_minor)->toBe(-1299);
    expect($transactions->first()->source_ref)->toBe('PAYPALTXN17052026');
});

it('lands the receipt-bearing message of a dropped .mbox and leaves the rest alone', function (): void {
    seedDroppedEml($this->baseDir, 'mixed.mbox', 'mbox/paypal-mixed.mbox');

    runScanJob($this->user->id);

    // paypal-mixed.mbox carries one receipt, one non-PayPal bill and one
    // sign-in notice, so exactly one of the three reaches the ledger.
    $transactions = Transaction::query()->where('user_id', $this->user->id)->get();
    expect($transactions)->toHaveCount(1);
    expect($transactions->first()->source_ref)->toBe('PAYPALTXN17052026');
});

// The drop-folder scan flips the file_imports row to `parsed`, and RecordReceipt
// used to answer a second pass over the same bytes with unmatched(): the wizard
// then built a run with no rows and refused to confirm it, so the same receipt
// was importable through neither path.
it('leaves the same file importable through the wizard after the drop folder took it', function (): void {
    seedDroppedEml($this->baseDir, 'paypal-1.eml', 'paypal/current-receipt.eml');
    runScanJob($this->user->id);

    expect(Transaction::query()->where('user_id', $this->user->id)->count())->toBe(1);

    $bytes = (string) file_get_contents(base_path('Modules/Receipts/tests/fixtures/paypal/current-receipt.eml'));
    /** @var RecordReceipt $record */
    $record = app(RecordReceipt::class);
    $second = $record($bytes, $this->user, 'paypal-1.eml');

    expect($second->kind)->toBe(MatchOutcomeKind::Parsed);
    expect($second->parsed?->referenceId)->toBe('PAYPALTXN17052026');

    // And the second pass is a dedup, not a second charge.
    expect(DB::table('file_imports')->where('user_id', $this->user->id)->count())->toBe(1);
    expect(Transaction::query()->where('user_id', $this->user->id)->count())->toBe(1);
});

it('streams a dropped .mbox archive through the matcher and moves it to processed/{YYYY-MM}/', function (): void {
    // small.mbox holds 5 synthetic messages — none are receipts, so the
    // matcher returns unmatched for each, but recordMboxFile still walks
    // every entry and reports success, so the archive is moved to
    // processed/ rather than quarantined.
    $sourcePath = seedDroppedEml($this->baseDir, 'archive.mbox', 'mbox/small.mbox');

    runScanJob($this->user->id);

    expect(file_exists($sourcePath))->toBeFalse();
    $processedPath = $this->baseDir.'/processed/2026-05/archive.mbox';
    expect(file_exists($processedPath))->toBeTrue();
});

// The opt-in read and the dispatch moved out of routes/console.php into an
// artisan command: a Schedule::call() closure has no name for a device's
// background runner to invoke, so the scan the settings copy promised a phone
// never entered its manifest.
it('registers the routes/console.php Schedule entry under the receipts.scan-drop-folder name', function (): void {
    $contents = (string) file_get_contents(base_path('routes/console.php'));
    expect($contents)->toContain('receipts.scan-drop-folder');
    expect($contents)->toContain('everyFiveMinutes');

    $command = (string) file_get_contents(
        base_path('Modules/Receipts/Internal/Console/ScanInboxDropFolderCommand.php'),
    );
    expect($command)->toContain('auto_import_drop_folder');
    expect($command)->toContain(ScanInboxDropFolderJob::class);
});

it('routes ScanInboxDropFolderJob unique lock through the LockStore carve-out helper', function (): void {
    $job = (string) file_get_contents(base_path('Modules/Receipts/Internal/Jobs/ScanInboxDropFolderJob.php'));
    expect($job)->toContain('LockStore::forUniqueJobs()')
        ->and($job)->not->toContain("Cache::driver('redis')");

    $archTest = (string) file_get_contents(base_path('tests/Contracts/BoundaryArchTest.php'));
    expect($archTest)->toContain('Modules\\\\Core\\\\Public\\\\Support\\\\LockStore');
});
