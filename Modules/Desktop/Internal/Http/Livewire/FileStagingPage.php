<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Navigation\Destination;
use Modules\Core\Public\Support\Lang;
use Modules\Desktop\Internal\Native\PendingFileIntent;

// Both .csv and .eml intents navigate to the same Imports destination —
// the Import wizard owns the per-source-format branch internally (its
// issuer/source-format selector picks the right step), so this page
// never forks on extension.
final class FileStagingPage extends Component
{
    // Captured at mount time so refreshing the page mid-flow doesn't
    // lose the file binding — the session-scoped store has already
    // been cleared by mount().
    /**
     * @var array{path: string, extension: string}|null
     */
    public ?array $pending = null;

    public function mount(PendingFileIntent $intent): void
    {
        // Read-and-clear: visiting the page once consumes the pending
        // intent so the next visit shows the empty state instead of
        // re-firing a stale one.
        $this->pending = $intent->pending();
        if ($this->pending !== null) {
            $intent->clear();
        }
    }

    public function render(
        ViewFactory $views,
    ): View {
        $filename = null;
        if ($this->pending !== null) {
            $filename = basename($this->pending['path']);
        }

        $view = $views->make('desktop::staging', [
            'pending' => $this->pending,
            'filename' => $filename,
            'headingPrefix' => Lang::get('desktop::screens.staging.heading_prefix'),
            'buttonLabel' => Lang::get('desktop::screens.staging.button_label'),
            'emptyHeading' => Lang::get('desktop::screens.staging.empty_heading'),
            'emptyBody' => Lang::get('desktop::screens.staging.empty_body'),
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => Lang::get('desktop::screens.staging.page_title').' · Beatrax']);

        return $view;
    }

    public function startImport(
        UrlGenerator $urls,
    ): mixed {
        return $this->redirect(Destination::Imports->urlFrom($urls), navigate: true);
    }
}
