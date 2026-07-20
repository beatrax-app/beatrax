<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Services\UserDataPathService;

/**
 * @link ../../../../../.docs/features/core/architecture.md
 */
final class HelpDataLocations extends Component
{
    public function render(
        UserDataPathService $paths,
        CurrentUser $currentUser,
        ViewFactory $views,
    ): View {
        $user = $currentUser->user();

        return $views->make('core::livewire.help.data-locations', [
            'sqlitePath' => $paths->databasePath(),
            'secretsPath' => $paths->secrets(),
            'cachesPath' => $paths->framework(),
            'devModeOn' => $user->is_developer === true,
        ]);
    }
}
