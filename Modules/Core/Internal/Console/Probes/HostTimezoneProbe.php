<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console\Probes;

use Illuminate\Contracts\Config\Repository;
use Modules\Core\Public\Services\InstallTimezone;
use Modules\Core\Public\Support\HostTimezone;
use Throwable;

// The zone is the frame every DATETIME column is written in, and its floor is
// silent: a host genuinely on UTC and a host that could not be asked return
// the same string. Android could not be asked at all, and read its days two
// hours out from the desktop it synced with until somebody went looking.
final readonly class HostTimezoneProbe implements Probe
{
    public function __construct(
        private Repository $config,
        private InstallTimezone $timezone,
    ) {}

    public function label(): string
    {
        return 'Host time zone';
    }

    public function run(): ProbeResult
    {
        try {
            $pinned = $this->config->get('app.timezone_pinned');
            $chosen = $this->timezone->chosen();
            $zone = $this->timezone->zone();
        } catch (Throwable $e) {
            return new ProbeResult(ProbeSeverity::Critical->value,
                'Failed to resolve the installation time zone: '.$e->getMessage(),
                ['exception' => $e::class],
            );
        }

        // A pin or a stored choice answers before the machine is ever asked, so
        // a silent floor underneath one of them costs nothing.
        if (is_string($pinned) && $pinned !== '') {
            return new ProbeResult(ProbeSeverity::Ok->value,
                sprintf('Pinned by the environment to %s.', $zone), ['zone' => $zone, 'source' => 'environment']);
        }

        if ($chosen !== null) {
            return new ProbeResult(ProbeSeverity::Ok->value,
                sprintf('Chosen on this installation: %s.', $zone), ['zone' => $zone, 'source' => 'stored']);
        }

        if (HostTimezone::hostAnswered()) {
            return new ProbeResult(ProbeSeverity::Ok->value,
                sprintf('Read from the machine: %s.', $zone), ['zone' => $zone, 'source' => 'machine']);
        }

        return new ProbeResult(ProbeSeverity::Warning->value,
            'The machine could not be asked which zone it is in, so days are being read in UTC. '
            .'Set APP_TIMEZONE, or choose a zone in settings, before trusting a stored date.',
            ['zone' => $zone, 'source' => 'floor'],
        );
    }
}
