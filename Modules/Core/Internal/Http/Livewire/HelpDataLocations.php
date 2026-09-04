<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Services\UserDataLocations;

final class HelpDataLocations extends Component
{
    public function render(ViewFactory $views): View
    {
        $databaseFiles = UserDataLocations::databaseFiles();

        return $views->make('core::livewire.help.data-locations', [
            'locations' => UserDataLocations::all(),
            'walFile' => basename($databaseFiles[1]),
            'shmFile' => basename($databaseFiles[2]),
        ]);
    }
}
