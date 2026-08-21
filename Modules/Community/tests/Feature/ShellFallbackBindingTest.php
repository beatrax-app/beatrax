<?php

declare(strict_types=1);

use Modules\Community\Internal\Shell\NoOpShell;
use Modules\Community\Public\Actions\OpenExternalUrlAction;
use Native\Desktop\Contracts\Shell as ShellContract;

// NativePHP's NativeServiceProvider binds the real Shell unconditionally at
// register time, so a naive `! app()->bound(Shell)` fallback never wins. Outside
// the desktop runtime that Shell POSTs to localhost:4000 and openExternal() dies
// with cURL error 7, so NoOpShell has to be what resolves.
it('resolves Shell to the NoOp fallback when not running inside the NativePHP runtime', function (): void {
    expect((bool) config('nativephp-internal.running', false))->toBeFalse();

    expect(app(ShellContract::class))->toBeInstanceOf(NoOpShell::class);
});

it('does not throw when opening an allow-listed external URL outside the native runtime', function (): void {
    $action = app(OpenExternalUrlAction::class);

    $action('https://github.com/beatrax-app/beatrax/releases/latest');
})->throwsNoExceptions();
