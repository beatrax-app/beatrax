<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\ManageUserPage;
use Modules\Core\Models\User;
use Modules\Mobile\Public\Enums\FileExportOutcome;
use Modules\Mobile\Public\Services\ShareSheetExport;

uses(RefreshDatabase::class);

// The same silence as /reports, one screen over. After an owner regenerates a
// partner's recovery codes, "Download as .txt" is a data: URL on an
// <a download> — the shape the Android shell drops without a file, an error or
// a console entry. The one-time display and the import wizard already ask the
// platform where their download can go; this screen never did.

final class ManageUserShareSheet extends ShareSheetExport
{
    /** @var array<string, string> */
    public array $handed = [];

    public function __construct(private readonly bool $dropsDownloads = true) {}

    public function replacesWebViewDownload(): bool
    {
        return $this->dropsDownloads;
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function export(
        string $filename,
        string $contents,
        ?string $shareTitle = null,
        ?string $shareMessage = null,
    ): FileExportOutcome {
        $this->handed[$filename] = $contents;

        return FileExportOutcome::Shared;
    }
}

function manageUserOwnerAndPartner(): User
{
    $owner = User::query()->create([
        'username' => 'codes-owner',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    User::query()->create([
        'username' => 'codes-partner',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    return $owner;
}

it('hands regenerated codes to the share sheet instead of a link that drops them', function (): void {
    $sheet = new ManageUserShareSheet;
    $this->app->instance(ShareSheetExport::class, $sheet);

    $page = Livewire::actingAs(manageUserOwnerAndPartner())
        ->test(ManageUserPage::class, ['username' => 'codes-partner'])
        ->call('regenerateCodes');

    $codes = $page->instance()->regeneratedCodes;
    expect($codes)->not->toBe([]);

    $page->call('downloadCodes')->assertSet('flashMessage', FileExportOutcome::Shared->message());

    expect($sheet->handed)->toHaveKey('beatrax-recovery-codes-codes-partner.txt')
        ->and($sheet->handed['beatrax-recovery-codes-codes-partner.txt'])->toBe(implode("\n", $codes));
});

it('keeps the plain link where the WebView saves what it is handed', function (): void {
    $sheet = new ManageUserShareSheet(dropsDownloads: false);
    $this->app->instance(ShareSheetExport::class, $sheet);

    $html = Livewire::actingAs(manageUserOwnerAndPartner())
        ->test(ManageUserPage::class, ['username' => 'codes-partner'])
        ->call('regenerateCodes')
        ->html();

    expect($html)->toContain('download="beatrax-recovery-codes-codes-partner.txt"')
        ->and($html)->not->toContain('wire:click="downloadCodes"');
});

it('offers the action rather than the link where the shell drops downloads', function (): void {
    $this->app->instance(ShareSheetExport::class, new ManageUserShareSheet);

    $html = Livewire::actingAs(manageUserOwnerAndPartner())
        ->test(ManageUserPage::class, ['username' => 'codes-partner'])
        ->call('regenerateCodes')
        ->html();

    expect($html)->toContain('wire:click="downloadCodes"')
        ->and($html)->not->toContain('download="beatrax-recovery-codes-codes-partner.txt"');
});
