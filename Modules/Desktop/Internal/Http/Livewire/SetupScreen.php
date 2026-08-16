<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Support\Lang;
use Modules\Desktop\Internal\Native\FirstLaunchBootstrap;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @link ../../../../../.docs/features/desktop/architecture.md
 */
final class SetupScreen extends Component
{
    // Whether the last attempt to apply the migrations threw. Kept so the
    // screen can stop claiming this "only takes a moment" when it plainly
    // is not going to.
    public bool $failed = false;

    // The poll tick DRIVES the migration rather than waiting for someone
    // else to. runPendingMigrations() otherwise fires only in the native
    // bootstrap, before the window opens, so a migration that appeared
    // after launch left this page polling a state nothing could change.
    public function poll(
        FirstLaunchBootstrap $bootstrap,
        LoggerInterface $logger,
        UrlGenerator $urls,
    ): void {
        if (! $bootstrap->hasPendingMigrations()) {
            $this->failed = false;

            // "Continuing…" has to actually continue. Waiting for the gate to
            // release on some later navigation left the screen polling a
            // finished state forever, and each tick was a request the page
            // had no use for.
            $this->redirect($urls->route('dashboard'), navigate: false);

            return;
        }

        try {
            $bootstrap->runPendingMigrations();
            $this->failed = false;
        } catch (Throwable $e) {
            $this->failed = true;

            $logger->error('SetupScreen: pending migrations could not be applied.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function render(
        ViewFactory $views,
        FirstLaunchBootstrap $bootstrap,
    ): View {
        $view = $views->make('desktop::setup', [
            'isPending' => $bootstrap->hasPendingMigrations(),
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('desktop::screens.setup.page_title').' · Beatrax']);

        return $view;
    }
}
