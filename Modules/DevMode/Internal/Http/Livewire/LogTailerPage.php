<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\DevMode\Internal\Logging\LogFileStats;

/**
 * /dev/logs page — live tail of the daily-rotated Laravel log file
 * with severity multi-select + channel filter + contains-filter +
 * pause/resume + 10k client-side ring buffer + click-to-expand
 * ±10 lines of context.
 *
 * Server state is minimal — every filter is `#[Url]` so the page is
 * deep-linkable. The 10k-line scrollback is a CLIENT-side Alpine
 * ring buffer (the server never holds the buffer); pause/resume is
 * purely client-side too (the SSE controller has no notion of pause
 * — the Alpine handler simply closes + re-opens the EventSource).
 *
 * No constructor: Livewire components never receive constructor DI
 * per the project's larastan-strict-rules profile. Collaborators
 * arrive via method-DI on render().
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

    /**
     * Empty today's daily log file to zero bytes. Preserves the inode
     * so the LogStreamController's poll loop sees the size shrink via
     * its `?since` past-current-size branch and signals reset to the
     * Alpine ring buffer. After truncation the listener Blade
     * dispatches a `logs:truncated` browser event so the client also
     * zeros its locally cached `offset` + line buffer immediately
     * (without waiting for the next poll round-trip).
     */
    public function truncate(LogFileStats $stats): void
    {
        $freed = $stats->truncateToday();

        $this->dispatch(
            'toast',
            message: $freed > 0
                ? sprintf('Log truncated — freed %s.', self::humanBytes($freed))
                : 'Nothing to truncate.',
        );
        $this->dispatch('logs:truncated');
    }

    public function render(ViewFactory $views, LogFileStats $stats): View
    {
        $severityList = array_values(array_filter(
            array_map('trim', explode(',', $this->severities)),
            static fn (string $s): bool => $s !== '',
        ));

        // Initial stats snapshot so the chip labels + totals strip
        // render correctly on first paint, before the JS stats poll
        // has fired. The Alpine x-data overwrites these as soon as
        // the first /dev/logs/stats response lands.
        $today = $stats->forToday();
        $allFiles = $stats->allFiles();

        return $views->make('dev::livewire.log-tailer-page', [
            'severityList' => $severityList,
            'channel' => $this->channel,
            'contains' => $this->contains,
            'initialStats' => [
                'today' => $today,
                'allFiles' => $allFiles,
            ],
        ]);
    }

    /**
     * Compact KB/MB/GB renderer for the toast message. Mirrors the
     * client-side humanBytes() in the blade so a 0-byte freed message
     * never reaches the operator (handled upstream by the "Nothing to
     * truncate" branch).
     */
    private static function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        $kb = $bytes / 1024;
        if ($kb < 1024) {
            return number_format($kb, 1).' KB';
        }
        $mb = $kb / 1024;
        if ($mb < 1024) {
            return number_format($mb, 1).' MB';
        }

        return number_format($mb / 1024, 2).' GB';
    }
}
