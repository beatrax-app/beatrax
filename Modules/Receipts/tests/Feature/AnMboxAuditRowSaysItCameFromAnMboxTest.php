<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Receipts\Internal\Jobs\ScanInboxDropFolderJob;
use Modules\Receipts\Public\Actions\RecordReceipt;

// 'mbox' is one of exactly two values the file_imports.source_kind trigger
// allows, and every message carved out of an archive was stamped 'eml' — so
// the only writer of the other value was the demo seeder, and the audit row
// contradicted the filename beside it.

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 5, 17, 12, 0, 0));

    $this->user = User::create([
        'username' => 'mbox-audit',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);

    $this->baseDir = storage_path('app/inbox-drop/'.$this->user->id);
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

it('stamps source_kind from the dropped archive, not from the message shape', function (): void {
    $files = new Filesystem;
    $files->ensureDirectoryExists($this->baseDir, 0700, recursive: true);
    $files->put(
        $this->baseDir.'/archive.mbox',
        (string) file_get_contents(base_path('Modules/Receipts/tests/fixtures/mbox/small.mbox')),
    );

    $job = new ScanInboxDropFolderJob($this->user->id);
    Container::getInstance()->call([$job, 'handle']);

    $kinds = DB::table('file_imports')
        ->where('user_id', $this->user->id)
        ->pluck('source_kind')
        ->unique()
        ->values()
        ->all();

    expect($kinds)->toBe([SourceFormat::Mbox->value]);
});

it('stamps source_kind eml for a single dropped message', function (): void {
    /** @var RecordReceipt $record */
    $record = $this->app->make(RecordReceipt::class);
    $bytes = (string) file_get_contents(base_path('Modules/Receipts/tests/fixtures/paypal/current-receipt.eml'));

    $record($bytes, $this->user, 'paypal-1.eml');

    expect((string) DB::table('file_imports')->where('user_id', $this->user->id)->value('source_kind'))
        ->toBe(SourceFormat::Eml->value);
});
