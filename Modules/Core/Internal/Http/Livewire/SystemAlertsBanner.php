<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Actions\AcknowledgeSystemAlert;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\SystemAlertQuery;

/**
 * Persistent dashboard banner that surfaces every un-acknowledged
 * `system_alerts` row visible to the current user — their own rows
 * plus system-wide (user_id IS NULL) rows. Sits at the top of every
 * authenticated page via the `@livewire('core.system-alerts-banner')`
 * slot in `resources/views/layouts/app.blade.php`.
 *
 * Severity-first stack:
 *   critical → warning → info
 * with chronological tie-break inside each tier (locked by
 * `SystemAlertQuery::active`).
 *
 * No constructor DI — phpstan-strict-rules bans it on Livewire
 * Component subclasses. Method-parameter DI on `render()` and
 * `acknowledge()` instead. The class deliberately holds zero
 * properties so the component is stateless across re-renders;
 * Livewire re-runs `render()` after every action returns, so the
 * post-acknowledge view automatically drops the dismissed row.
 */
final class SystemAlertsBanner extends Component
{
    public function render(
        CurrentUser $currentUser,
        SystemAlertQuery $query,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();
        $alerts = $query->active($user);

        return $views->make('core::livewire.system-alerts-banner', [
            'alerts' => $alerts,
        ]);
    }

    public function acknowledge(int $alertId, AcknowledgeSystemAlert $action, CurrentUser $currentUser): void
    {
        $action($alertId, $currentUser->user());
    }
}
