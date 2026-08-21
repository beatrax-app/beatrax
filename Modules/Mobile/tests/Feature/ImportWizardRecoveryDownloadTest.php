<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Desktop\Internal\Http\Middleware\EnsureDatabaseReady;
use Modules\Mobile\Internal\Http\Livewire\MobileImportBootstrap;
use Modules\Mobile\Internal\Identity\RecoveryCodesExportBridge;

uses(RefreshDatabase::class);

// The import wizard shows the same ten codes as /recovery-codes and offers the
// same Download as .txt, so it has to make the same choice about where the file
// goes. On iOS the export endpoint keeps a copy in the app's private container
// and hands it to Share::file(), whose Share.File bridge function nativephp/
// mobile 4.1.0 registers in neither shell — no sheet, no error, and a copy the
// reader cannot open. The WebView download is the route that shell really has.

// The bridge answers false in this composer root, because nativephp/mobile is
// installed only under mobile-app/. A double that says yes is what makes the
// platform gate observable here instead of only in the mobile-app job.
function alwaysAvailableExportBridge(): RecoveryCodesExportBridge
{
    return new class extends RecoveryCodesExportBridge
    {
        public function isAvailable(): bool
        {
            return true;
        }
    };
}

it('leaves the iOS download to the WebView on the import wizard too', function (): void {
    $this->withoutMiddleware(EnsureDatabaseReady::class);

    $this->app->instance(RecoveryCodesExportBridge::class, alwaysAvailableExportBridge());

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

    $this->app->instance(RecoveryCodesExportBridge::class, alwaysAvailableExportBridge());

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
