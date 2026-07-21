<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Http\Livewire;

use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Desktop\Internal\Native\PendingFileIntent;

// Both .csv and .eml intents navigate to the same imports.new route —
// the Import wizard owns the per-source-format branch internally (its
// issuer/source-format selector picks the right step), so this page
// never forks on extension.
final class FileStagingPage extends Component
{
    public const HEADING_PREFIX = 'File received: ';

    public const BUTTON_LABEL = 'Start import';

    public const EMPTY_HEADING = "We couldn't open that file";

    public const EMPTY_BODY = "beatrax couldn't read the file you opened. Try importing it from the Imports page instead.";

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
            'headingPrefix' => self::HEADING_PREFIX,
            'buttonLabel' => self::BUTTON_LABEL,
            'emptyHeading' => self::EMPTY_HEADING,
            'emptyBody' => self::EMPTY_BODY,
        ]);

        /** @phpstan-ignore-next-line method.notFound — registered at runtime by Livewire's SupportPageComponents */
        $view->extends('layouts.app', ['title' => 'File received · beatrax']);

        return $view;
    }

    public function startImport(
        UrlGenerator $urls,
    ): mixed {
        return $this->redirect($urls->route('imports.new'), navigate: true);
    }
}
