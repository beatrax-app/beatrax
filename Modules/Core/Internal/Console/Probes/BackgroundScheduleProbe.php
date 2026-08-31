<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console\Probes;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Container\Container;
use Modules\Core\Public\Scheduling\MobileBackgroundSchedule;
use Throwable;

// The phone drops a schedule it cannot express and logs an INFO line nobody
// reads, so twenty of twenty-one tasks — automatic backups among them — went
// missing for the length of a release. This is the check that says so.
final readonly class BackgroundScheduleProbe implements Probe
{
    public function __construct(
        private Container $container,
    ) {}

    public function label(): string
    {
        return 'Background schedule';
    }

    public function run(): ProbeResult
    {
        try {
            $schedule = $this->container->make(Schedule::class);
            $carried = MobileBackgroundSchedule::carriedBy($schedule->events());
        } catch (Throwable $e) {
            return new ProbeResult(ProbeSeverity::Critical->value,
                'Failed to read the schedule: '.$e->getMessage(),
                ['exception' => $e::class],
            );
        }

        $required = MobileBackgroundSchedule::mobileRootLoaded()
            ? MobileBackgroundSchedule::requiredOnDevice() + MobileBackgroundSchedule::mobileRootOnly()
            : MobileBackgroundSchedule::requiredOnDevice();
        $excluded = count(MobileBackgroundSchedule::desktopOnly());
        $impossible = count(MobileBackgroundSchedule::impossibleOnDevice());
        $missing = array_values(array_diff(array_values($required), $carried));

        if ($missing !== []) {
            return new ProbeResult(ProbeSeverity::Critical->value,
                sprintf(
                    '%d of %d task(s) the phone must run never reach its background manifest: %s.',
                    count($missing),
                    count($required),
                    implode(', ', $missing),
                ),
                ['missing' => implode(', ', $missing)],
            );
        }

        return new ProbeResult(ProbeSeverity::Ok->value,
            sprintf(
                'All %d task(s) the phone must run reach its background manifest '
                .'(%d deliberately desktop-only, %d impossible on a phone at all).',
                count($required),
                $excluded,
                $impossible,
            ),
            ['required' => count($required), 'desktop_only' => $excluded, 'impossible_on_device' => $impossible],
        );
    }
}
