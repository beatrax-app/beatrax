<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console\Probes;

interface Probe
{
    public function label(): string;

    // MUST NOT throw — any IO/SQL exception is caught internally and
    // surfaced through the returned ProbeResult instead.
    public function run(): ProbeResult;
}
