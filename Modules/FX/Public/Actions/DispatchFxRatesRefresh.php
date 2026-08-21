<?php

declare(strict_types=1);

namespace Modules\FX\Public\Actions;

use Illuminate\Contracts\Bus\Dispatcher;
use Modules\FX\Internal\Jobs\FetchFxRatesJob;

// The boundary-compliant seam for a rate refresh: cross-module callers must not
// reach into Internal\Jobs\FetchFxRatesJob directly.
final class DispatchFxRatesRefresh
{
    public function __construct(private readonly Dispatcher $bus) {}

    public function __invoke(int $userId): void
    {
        $this->bus->dispatch(new FetchFxRatesJob($userId));
    }
}
