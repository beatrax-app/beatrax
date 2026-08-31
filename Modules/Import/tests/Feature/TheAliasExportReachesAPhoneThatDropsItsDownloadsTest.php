<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Core\Models\User;
use Modules\Import\Internal\Http\Livewire\AliasesSettingsPage;
use Modules\Mobile\Public\Enums\FileExportOutcome;
use Modules\Mobile\Public\Services\ShareSheetExport;

uses(RefreshDatabase::class);

// Export aliases.yaml streamed a download and asked nothing about the shell it
// was streaming into. On Android that response has nowhere to go, so the button
// wrote no file and said nothing — and the import half of the same screen was
// still offered, so the reader could take an alias file in but never get one out.

final class AliasExportShareSheet extends ShareSheetExport
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

function aliasExportReader(): User
{
    return User::query()->create([
        'username' => 'alias-export-'.bin2hex(random_bytes(4)),
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('hands aliases.yaml to the share sheet on a shell that drops downloads', function (): void {
    $sheet = new AliasExportShareSheet;
    $this->app->instance(ShareSheetExport::class, $sheet);

    Livewire::actingAs(aliasExportReader())->test(AliasesSettingsPage::class)
        ->call('exportYaml')
        ->assertSet('flashMessage', FileExportOutcome::Shared->message());

    expect(array_keys($sheet->handed))->toBe(['aliases.yaml']);
});

it('leaves the alias download alone where the WebView saves it', function (): void {
    $sheet = new AliasExportShareSheet(dropsDownloads: false);
    $this->app->instance(ShareSheetExport::class, $sheet);

    Livewire::actingAs(aliasExportReader())->test(AliasesSettingsPage::class)
        ->call('exportYaml')
        ->assertSet('flashMessage', '');

    expect($sheet->handed)->toBe([]);
});
