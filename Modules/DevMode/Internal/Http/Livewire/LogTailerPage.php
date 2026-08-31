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
use Modules\DevMode\Internal\Support\ByteSize;

#[Layout('dev::layouts.dev-shell')]
final class LogTailerPage extends Component
{
    // The blade binds its window listener to this same constant. Spelled out
    // on both sides, the two names drifted: the tailer listened for
    // `logs-truncated` while this dispatched `logs:truncated`, so the local
    // cursor never reset and the operator saw the old buffer sit there.
    public const string TRUNCATED_EVENT = 'logs:truncated';

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
        $freed = $stats->truncate();

        $this->dispatch(
            'toast',
            message: $freed > 0
                ? Lang::get('dev::logs.toast.truncated', ['size' => ByteSize::human($freed)])
                : Lang::get('dev::logs.toast.nothing'),
        );
        $this->dispatch(self::TRUNCATED_EVENT);
    }

    public function render(ViewFactory $views, LogFileStats $stats): View
    {
        $severityList = array_values(array_filter(
            array_map('trim', explode(',', $this->severities)),
            static fn (string $s): bool => $s !== '',
        ));

        // Seeds first paint; the stats poll overwrites these on its first
        // response.
        $today = $stats->current();
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
}
