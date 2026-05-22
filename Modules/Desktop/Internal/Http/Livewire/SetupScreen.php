<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Desktop\Internal\Native\FirstLaunchBootstrap;

/**
 * The "Setting up…" boot screen (D-21).
 *
 * Renders the centered full-screen splash while
 * `FirstLaunchBootstrap::runPendingMigrations()` finishes. The component
 * holds zero properties — `phpstan-strict-rules` forbids a constructor
 * on a Livewire `Component` subclass, so collaborators arrive as
 * method-parameter DI on `render()` (the same pattern
 * `SystemAlertsBanner` uses).
 *
 * The render delegates the migration-pending check to the bootstrap,
 * which lets the view fork between the in-flight ("Setting up…") state
 * and the post-failure error state without leaking any
 * `database_path()` / facade calls into the component.
 */
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
        $view->extends('layouts.app', ['title' => 'Setting up… · diederik']);

        return $view;
    }
}
