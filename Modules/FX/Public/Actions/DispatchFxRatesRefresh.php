<?php

declare(strict_types=1);

namespace Modules\FX\Public\Actions;

use Illuminate\Contracts\Bus\Dispatcher;
use Modules\FX\Internal\Jobs\FetchFxRatesJob;

// Cross-module callers (e.g. Core's SettingsPage) use this action
// instead of importing FetchFxRatesJob from Internal directly - the
// boundary-compliant seam through which any module requests an
// on-demand or scheduled rate refresh for a given user.
final class DispatchFxRatesRefresh
{
    public function __construct(private readonly Dispatcher $bus) {}

    public function __invoke(int $userId): void
    {
        $this->bus->dispatch(new FetchFxRatesJob($userId));
    }
}
