<?php

declare(strict_types=1);

namespace Modules\FX\Public\Actions;

use Illuminate\Contracts\Bus\Dispatcher;
use Modules\FX\Internal\Jobs\FetchFxRatesJob;

/**
 * Public dispatch surface for the FX rate-refresh job.
 *
 * Cross-module callers (e.g. Core's SettingsPage) use this action instead
 * of importing FetchFxRatesJob from Internal directly. The action is the
 * boundary-compliant single seam through which any module may request an
 * on-demand or scheduled rate refresh for a given user.
 *
 * Wire: inject or resolve through the container. The action is intentionally
 * stateless — it holds no mutable state between calls.
 */
final class DispatchFxRatesRefresh
{
    public function __construct(private readonly Dispatcher $bus) {}

    public function __invoke(int $userId): void
    {
        $this->bus->dispatch(new FetchFxRatesJob($userId));
    }
}
