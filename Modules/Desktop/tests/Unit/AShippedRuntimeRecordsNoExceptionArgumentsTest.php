<?php

declare(strict_types=1);

use Modules\Desktop\Internal\NativeAppServiceProvider;

// SafeTrace assembles its own frames and needs none of this. Anything else that
// renders a trace -- a vendor handler, a fatal shutdown dump -- reads the
// directive instead, and Off is what the bundled interpreter ships.
it('publishes a php.ini override that stops PHP recording exception arguments', function (): void {
    $phpIni = app(NativeAppServiceProvider::class)->phpIni();

    expect($phpIni)->toHaveKey('zend.exception_ignore_args')
        ->and($phpIni['zend.exception_ignore_args'])->toBe('1');
});
