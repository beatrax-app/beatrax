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
    private const string FLOOR = 'floor';

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
            $source = $this->source();
            $zone = $this->timezone->zone();
        } catch (Throwable $e) {
            return new ProbeResult(ProbeSeverity::Critical->value,
                'Failed to resolve the installation time zone: '.$e->getMessage(),
                ['exception' => $e::class],
            );
        }

        if ($source === self::FLOOR) {
            return new ProbeResult(ProbeSeverity::Warning->value,
                'The machine could not be asked which zone it is in, so days are being read in UTC. '
                .'Set APP_TIMEZONE, or choose a zone in settings, before trusting a stored date.',
                ['zone' => $zone, 'source' => self::FLOOR],
            );
        }

        return new ProbeResult(ProbeSeverity::Ok->value,
            self::sentence($source, $zone), ['zone' => $zone, 'source' => $source]);
    }

    // A pin or a stored choice answers before the machine is ever asked, so a
    // silent floor underneath one of them costs nothing and is not reported.
    private function source(): string
    {
        $pinned = $this->config->get('app.timezone_pinned');

        if (is_string($pinned) && $pinned !== '') {
            return 'environment';
        }

        if ($this->timezone->chosen() !== null) {
            return 'stored';
        }

        return HostTimezone::hostAnswered() ? 'machine' : self::FLOOR;
    }

    private static function sentence(string $source, string $zone): string
    {
        return match ($source) {
            'environment' => sprintf('Pinned by the environment to %s.', $zone),
            'stored' => sprintf('Chosen on this installation: %s.', $zone),
            default => sprintf('Read from the machine: %s.', $zone),
        };
    }
}
