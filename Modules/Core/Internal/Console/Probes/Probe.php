<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console\Probes;

/**
 * Contract for a `diederik:doctor` probe. Each probe is a small testable
 * unit that reads one operational signal (a PRAGMA value, a backup-
 * directory timestamp) and returns a `ProbeResult` summarising the
 * outcome at severity `ok` / `warning` / `critical`.
 *
 * Probes MUST NOT throw. Every IO / SQL touchpoint is wrapped in
 * try/catch internally; failures bubble up as a `critical` result with
 * the exception message, never an uncaught exception. This keeps the
 * consuming `DoctorCommand` flow trivial (iterate, print, aggregate
 * exit code) and the boot-time `HealthCheckServiceProvider`'s listener
 * structurally crash-free.
 */
interface Probe
{
    /**
     * Human-readable label printed in the doctor command's summary
     * table — for example `SQLite WAL mode` or `Backup freshness`.
     */
    public function label(): string;

    /**
     * Run the probe and return its result. The probe MUST NOT throw —
     * any IO / SQL exception is caught internally and surfaced through
     * the returned `ProbeResult` instead.
     */
    public function run(): ProbeResult;
}
