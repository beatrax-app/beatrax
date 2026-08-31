<?php

declare(strict_types=1);

use Modules\Auth\Public\Contracts\ColdStartVault;
use Modules\Auth\Public\Services\MobileLockGateway;
use Modules\Mobile\Internal\Identity\MobileColdStartVault;

// MobileColdStartVault took MobileLockGateway whole for two single-column
// reads, and the gateway is built from the app-lock provisioner. So the moment
// the provisioner named the vault back, the container could build neither, and
// the forget that belongs in enable() had to be pushed up into every caller.
it('builds the app lock and the mobile cold-start vault together', function (): void {
    // The binding MobileServiceProvider makes on a real device. The gate there
    // is the mobile runtime, which no test root reports, so a plain resolve
    // would exercise NullColdStartVault and prove nothing about this pair.
    $this->app->singleton(ColdStartVault::class, MobileColdStartVault::class);

    expect($this->app->make(MobileLockGateway::class))->toBeInstanceOf(MobileLockGateway::class)
        ->and($this->app->make(ColdStartVault::class))->toBeInstanceOf(MobileColdStartVault::class);
});
