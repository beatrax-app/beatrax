<?php

declare(strict_types=1);

namespace Modules\Shell\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Contracts\SampleDataLoader;

// The control that fills an empty install, on a surface a store build actually
// carries. The Dev Console offers `demo:seed` too, but the console is closed on
// a store build, and somebody looking at an empty finance application has no
// way to judge it.
/**
 * @link ../../../../../.docs/features/core/architecture.md
 */
final class SampleDataCard extends Component
{
    public bool $confirming = false;

    // Null until a load has happened in this component's lifetime, so the
    // result line is about what the reader just did rather than about a row
    // count the screen could have read at any time.
    public ?int $loadedRows = null;

    public function ask(): void
    {
        $this->confirming = true;
        $this->loadedRows = null;
    }

    public function cancel(): void
    {
        $this->confirming = false;
    }

    // Synchronous, and the time limit goes with it: this seeds a ledger, runs
    // the real anomaly detectors over it and rebuilds the search index, and the
    // shells serve one request at a time. An expiring limit is a fatal rather
    // than an exception, so it is lifted rather than caught.
    public function load(SampleDataLoader $loader, CurrentUser $currentUser): void
    {
        $this->confirming = false;

        set_time_limit(0);

        $this->loadedRows = array_sum($loader->loadFor($currentUser->user()->id));
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('shell::livewire.sample-data-card');
    }
}
