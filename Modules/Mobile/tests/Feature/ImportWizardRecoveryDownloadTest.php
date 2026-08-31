<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Desktop\Internal\Http\Middleware\EnsureDatabaseReady;
use Modules\Mobile\Internal\Http\Livewire\MobileImportBootstrap;
use Modules\Mobile\Public\Services\ShareSheetExport;

uses(RefreshDatabase::class);

// The import wizard shows the same ten codes as /recovery-codes and offers the
// same Download as .txt, so it has to make the same choice about where the file
// goes. iOS answers the blob navigation with .download and a share sheet of its
// own, so the WebView download is the route that shell really has; Android
// drops it, and the endpoint is the only route there.

// One question decides it now — does this shell save what its WebView downloads
// — where the step used to require an available bridge as well. A build missing
// Share.File gets a 503 and the "could not save" line, where the blob fallback
// it used to take wrote nothing and said nothing.
function alwaysAvailableExportBridge(): ShareSheetExport
{
    return new class extends ShareSheetExport
    {
        public function isAvailable(): bool
        {
            return true;
        }
    };
}

it('leaves the iOS download to the WebView on the import wizard too', function (): void {
    $this->withoutMiddleware(EnsureDatabaseReady::class);

    $this->app->instance(ShareSheetExport::class, alwaysAvailableExportBridge());

    $_SERVER['NATIVEPHP_PLATFORM'] = 'ios';

    try {
        $html = Livewire::test(MobileImportBootstrap::class)
            ->set('username', 'phone-owner-ios')
            ->set('password', 'a-genuinely-long-password')
            ->set('passwordConfirmation', 'a-genuinely-long-password')
            ->set('pin', '426900')
            ->set('confirmPin', '426900')
            ->call('submit')
            ->assertSet('step', 'recovery_codes')
            ->html();
    } finally {
        unset($_SERVER['NATIVEPHP_PLATFORM']);
    }

    // The step's Alpine save() branches on this literal before falling through
    // to URL.createObjectURL, which the iOS shell answers with .download and a
    // UIActivityViewController.
    expect($html)->toContain('if (false)')
        ->and($html)->toContain('URL.createObjectURL');
});

it('still asks the endpoint on a shell that drops WebView downloads', function (): void {
    $this->withoutMiddleware(EnsureDatabaseReady::class);

    $this->app->instance(ShareSheetExport::class, alwaysAvailableExportBridge());

    $_SERVER['NATIVEPHP_PLATFORM'] = 'android';

    try {
        $html = Livewire::test(MobileImportBootstrap::class)
            ->set('username', 'phone-owner-android')
            ->set('password', 'a-genuinely-long-password')
            ->set('passwordConfirmation', 'a-genuinely-long-password')
            ->set('pin', '426900')
            ->set('confirmPin', '426900')
            ->call('submit')
            ->assertSet('step', 'recovery_codes')
            ->html();
    } finally {
        unset($_SERVER['NATIVEPHP_PLATFORM']);
    }

    expect($html)->toContain('if (true)');
});

it('still asks the endpoint on a shell that drops downloads and cannot share either', function (): void {
    $this->withoutMiddleware(EnsureDatabaseReady::class);

    $this->app->instance(ShareSheetExport::class, new class extends ShareSheetExport
    {
        public function isAvailable(): bool
        {
            return false;
        }
    });

    $_SERVER['NATIVEPHP_PLATFORM'] = 'android';

    try {
        $html = Livewire::test(MobileImportBootstrap::class)
            ->set('username', 'phone-owner-android-no-share')
            ->set('password', 'a-genuinely-long-password')
            ->set('passwordConfirmation', 'a-genuinely-long-password')
            ->set('pin', '426900')
            ->set('confirmPin', '426900')
            ->call('submit')
            ->assertSet('step', 'recovery_codes')
            ->html();
    } finally {
        unset($_SERVER['NATIVEPHP_PLATFORM']);
    }

    // Falling back to the blob here is the defect, not the safety net: the
    // shell that cannot share is the same shell that cannot download.
    expect($html)->toContain('if (true)');
});
