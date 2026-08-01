<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Support\Lang;
use Modules\Desktop\Internal\Native\FirstLaunchBootstrap;

final class SetupScreen extends Component
{
    public function render(
        ViewFactory $views,
        FirstLaunchBootstrap $bootstrap,
    ): View {
        $view = $views->make('desktop::setup', [
            'isPending' => $bootstrap->hasPendingMigrations(),
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('desktop::screens.setup.page_title').' · beatrax']);

        return $view;
    }
}
