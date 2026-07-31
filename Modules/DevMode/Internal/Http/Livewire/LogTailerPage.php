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
 * @link ../../../../../.docs/features/dev-mode/architecture.md
 */
#[Layout('dev::layouts.dev-shell')]
final class LogTailerPage extends Component
{
    private const int BYTES_PER_UNIT = 1024;

    #[Url(as: 'severities')]
    public string $severities = 'DEBUG,INFO,NOTICE,WARNING,ERROR,CRITICAL,ALERT,EMERGENCY';

    #[Url(as: 'channel')]
    public string $channel = '';

    #[Url(as: 'contains')]
    public string $contains = '';

    // Preserves the inode so LogStreamController's poll loop sees the
    // size shrink via its `?since` past-current-size branch and signals
    // reset; the listener Blade also dispatches `logs:truncated` so the
    // client zeros its cached offset immediately.
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

    private static function humanBytes(int $bytes): string
    {
        $kb = $bytes / self::BYTES_PER_UNIT;
        $mb = $kb / self::BYTES_PER_UNIT;

        return match (true) {
            $bytes < self::BYTES_PER_UNIT => $bytes.' B',
            $kb < self::BYTES_PER_UNIT => number_format($kb, 1).' KB',
            $mb < self::BYTES_PER_UNIT => number_format($mb, 1).' MB',
            default => number_format($mb / self::BYTES_PER_UNIT, 2).' GB',
        };
    }
}
