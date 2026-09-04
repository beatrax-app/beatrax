<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Modules\Core\Public\Support\Brand;
use Modules\Core\Public\Support\Lang;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Mobile\Internal\Boot\MobileFirstLaunchBootstrap;
use Modules\Mobile\Internal\Boot\SchemaCompletionMarker;
use Psr\Log\LoggerInterface;
use Throwable;

// The screen a half-built database gets instead of the welcome page. Before
// this, a first launch whose migration run died partway still opened on the
// welcome screen — fifteen tables of a hundred and two, every tap a 500, and
// the only record of it a line in a log file no reader can reach.
final class SchemaIncompleteScreen extends Component
{
    // Set only by retry(), which asks the migrator itself. Locked because the
    // wire is the one place that must not be able to claim a repair happened.
    #[Locked]
    public bool $retryFailed = false;

    public function retry(
        MobileFirstLaunchBootstrap $bootstrap,
        UrlGenerator $urls,
        LoggerInterface $logger,
    ): void {
        try {
            $bootstrap->runPendingMigrations();
        } catch (Throwable $e) {
            $logger->error('SchemaIncompleteScreen: the repair run failed too.', [
                ...SafeExceptionContext::describe($e),
            ]);
        }

        // The marker is the migrator's own answer, not this method's: a run
        // that threw may still have applied everything it was missing, and a
        // run that returned may have had nothing to do.
        if (SchemaCompletionMarker::isRaised()) {
            $this->retryFailed = true;

            return;
        }

        $this->redirect($urls->route('mobile.welcome'), navigate: false);
    }

    public function render(ViewFactory $views): View
    {
        $view = $views->make('mobile::livewire.schema-incomplete-screen');

        $view->extends('layouts.lock', ['title' => Lang::get('mobile::schema.page_title').Brand::TITLE_SUFFIX]);

        return $view;
    }
}
