<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;

/**
 * Persistent top navigation. Rendered from `layouts/app.blade.php` within
 * an `@auth` guard so unauthenticated pages (login, error pages) do not
 * see it.
 *
 * Layout:
 *
 *   Dashboard · Transactions · Uncategorized {count?} ─── {email} · Sign out
 *
 * The Uncategorized item carries a count badge when the user has any
 * uncategorized transactions; the badge falls away once "inbox zero" is
 * reached. Active-link highlighting reads the current path from the
 * injected `Request` rather than the `request()` helper so the component
 * stays clean under the project's DI-only constraint.
 */
final class TopNav extends Component
{
    public function render(
        CurrentUser $currentUser,
        DatabaseManager $db,
        Request $request,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();

        $uncategorized = $db->connection()
            ->table('transactions')
            ->where('user_id', $user->id)
            ->whereNull('category_id')
            ->count();

        return $views->make('core::livewire.top-nav', [
            'uncategorizedCount' => $uncategorized,
            'userEmail' => $user->email,
            'currentPath' => '/'.ltrim($request->path(), '/'),
        ]);
    }
}
