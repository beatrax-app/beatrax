<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;
use Modules\Core\Public\Support\Lang;
use Modules\DevMode\Internal\Logging\LogFileStats;

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

    // Preserving the inode is what lets the poll loop see the size shrink and
    // tell the client to zero its cursor.
    public function truncate(LogFileStats $stats): void
    {
        $freed = $stats->truncateToday();

        $this->dispatch(
            'toast',
            message: $freed > 0
                ? Lang::get('dev::logs.toast.truncated', ['size' => self::humanBytes($freed)])
                : Lang::get('dev::logs.toast.nothing'),
        );
        $this->dispatch('logs:truncated');
    }

    public function render(ViewFactory $views, LogFileStats $stats): View
    {
        $severityList = array_values(array_filter(
            array_map('trim', explode(',', $this->severities)),
            static fn (string $s): bool => $s !== '',
        ));

        // Seeds first paint; the stats poll overwrites these on its first
        // response.
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
