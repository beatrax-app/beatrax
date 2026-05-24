<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * /dev/logs page — live tail of the daily-rotated Laravel log file
 * with severity multi-select + channel filter + contains-filter +
 * pause/resume + 10k client-side ring buffer + click-to-expand ±10
 * lines of context (CONTEXT D-31).
 *
 * Server state is minimal — every filter is `#[Url]` so the page is
 * deep-linkable. The 10k-line scrollback is a CLIENT-side Alpine ring
 * buffer (the server never holds the buffer); pause/resume is purely
 * client-side too (the SSE controller has no notion of pause — the
 * Alpine handler simply closes + re-opens the EventSource).
 *
 * Pattern B from PATTERNS.md: no constructor on Livewire Components;
 * collaborators arrive via method-DI on `render()`.
 */
#[Layout('dev::layouts.dev-shell')]
final class LogTailerPage extends Component
{
    /**
     * Comma-separated severity selection — the 8 Monolog levels in
     * upper-case. The blade view maps this into an array of chip
     * toggles; the URL form keeps it readable.
     *
     * Default: every severity selected.
     */
    #[Url(as: 'severities')]
    public string $severities = 'DEBUG,INFO,NOTICE,WARNING,ERROR,CRITICAL,ALERT,EMERGENCY';

    /**
     * Optional Monolog channel name filter (free text — the daily file
     * does not enforce a fixed channel taxonomy; the user types it).
     */
    #[Url(as: 'channel')]
    public string $channel = '';

    /**
     * Free-text contains-filter for the visible buffer (debounced on
     * the client; the server never sees the keystrokes).
     */
    #[Url(as: 'contains')]
    public string $contains = '';

    public function render(ViewFactory $views): View
    {
        $severityList = array_values(array_filter(
            array_map('trim', explode(',', $this->severities)),
            static fn (string $s): bool => $s !== '',
        ));

        return $views->make('dev::livewire.log-tailer-page', [
            'severityList' => $severityList,
            'channel' => $this->channel,
            'contains' => $this->contains,
        ]);
    }
}
