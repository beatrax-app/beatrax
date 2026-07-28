<?php

declare(strict_types=1);

use Modules\Community\Internal\Shell\NoOpShell;
use Modules\Community\Public\Actions\OpenExternalUrlAction;
use Native\Desktop\Contracts\Shell as ShellContract;

/**
 * Regression guard for the localhost:4000 ConnectionException (cURL error 7)
 * hit from Settings → "Open releases page".
 *
 * NativePHP's NativeServiceProvider unconditionally binds the real Shell
 * (which POSTs to the Electron bridge on :4000) during registration, so the
 * naive `! app()->bound(Shell)` fallback never wins when the package is
 * installed. Outside the live desktop runtime the container MUST resolve the
 * NoOpShell fallback instead, or every openExternal() throws.
 */
it('resolves Shell to the NoOp fallback when not running inside the NativePHP runtime', function (): void {
    // The test runtime never sets NATIVEPHP_RUNNING, so the config flag is false.
    expect((bool) config('nativephp-internal.running', false))->toBeFalse();

    expect(app(ShellContract::class))->toBeInstanceOf(NoOpShell::class);
});

it('does not throw when opening an allow-listed external URL outside the native runtime', function (): void {
    $action = app(OpenExternalUrlAction::class);

    // With the NoOp fallback bound, this is a no-op log write — no HTTP call to
    // localhost:4000, so no ConnectionException.
    $action('https://github.com/beatrax-app/beatrax/releases/latest');
})->throwsNoExceptions();
