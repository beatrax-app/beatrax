<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console;

use Illuminate\Console\Command;
use Modules\Core\Internal\Console\Probes\BackupFreshnessProbe;
use Modules\Core\Internal\Console\Probes\ComposerVersionProbe;
use Modules\Core\Internal\Console\Probes\NodeVersionProbe;
use Modules\Core\Internal\Console\Probes\PhpVersionProbe;
use Modules\Core\Internal\Console\Probes\Probe;
use Modules\Core\Internal\Console\Probes\ProbeResult;
use Modules\Core\Internal\Console\Probes\SqliteCliVersionProbe;
use Modules\Core\Internal\Console\Probes\SynchronousModeProbe;
use Modules\Core\Internal\Console\Probes\WalModeProbe;

/**
 * Runs the diederik operational doctor: a homogeneous iteration over
 * every registered `Probe` (tool-version checks + SQLite-substrate
 * health + backup freshness). Each probe contributes one line to the
 * output table and one severity bucket to the exit-code aggregator.
 *
 * Probes:
 *  - PhpVersionProbe (BLOCKER if < 8.5)
 *  - ComposerVersionProbe / SqliteCliVersionProbe / NodeVersionProbe
 *    (warning if missing — none are runtime-fatal for the dashboard,
 *    they matter for dev workflows: composer install, sqlite3 CLI
 *    inspection, npm run build)
 *  - WalModeProbe / SynchronousModeProbe / BackupFreshnessProbe (the
 *    three Phase 11-03 SQLite-substrate probes)
 *
 * `ext-imap` is reported separately (info-only) because the project
 * uses the pure-PHP `webklex/php-imap`; the extension's presence is
 * neither required nor forbidden and folds awkwardly into the
 * severity bucket model.
 *
 * Exit codes:
 *   0 — every probe returned `ok` (or `info` for ext-imap)
 *   1 — one or more `warning` probes
 *   2 — at least one `critical` probe
 */
final class DoctorCommand extends Command
{
    /** @var string */
    protected $signature = 'diederik:doctor';

    /** @var string */
    protected $description = 'Report installed PHP / Composer / SQLite versions and verify minimums.';

    public function __construct(
        private readonly PhpVersionProbe $phpProbe,
        private readonly ComposerVersionProbe $composerProbe,
        private readonly SqliteCliVersionProbe $sqliteCliProbe,
        private readonly NodeVersionProbe $nodeProbe,
        private readonly WalModeProbe $walProbe,
        private readonly SynchronousModeProbe $synchronousProbe,
        private readonly BackupFreshnessProbe $backupFreshnessProbe,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        /** @var list<string> $blockers */
        $blockers = [];
        /** @var list<string> $warnings */
        $warnings = [];

        $this->line('diederik:doctor');
        $this->line('-----------------');

        // Every check runs through the same Probe -> ProbeResult ->
        // reportProbe pipeline so the output table is homogeneous and
        // a new probe is one constructor argument away.
        $probes = [
            $this->phpProbe,
            $this->composerProbe,
            $this->sqliteCliProbe,
            $this->nodeProbe,
            $this->walProbe,
            $this->synchronousProbe,
            $this->backupFreshnessProbe,
        ];
        foreach ($probes as $probe) {
            $result = $probe->run();
            $this->reportProbe($probe, $result, $blockers, $warnings);
        }

        // ext-imap is reported separately as informational-only — the
        // project uses pure-PHP webklex/php-imap so the native
        // extension's presence is neither required nor forbidden.
        // Folding this into the Probe severity model would invent a
        // fourth severity ("info"); keeping it inline preserves the
        // three-bucket Probe contract (ok / warning / critical).
        $loaded = in_array('imap', get_loaded_extensions(), true);
        $this->line(sprintf(
            '%-24s %-8s %s',
            'ext-imap',
            'info',
            ($loaded ? 'loaded' : 'not loaded').' (diederik uses webklex/php-imap regardless)',
        ));

        $this->line('');

        if ($blockers !== []) {
            $this->error(sprintf('%d blocker(s), %d warning(s). Fix blockers before continuing.', count($blockers), count($warnings)));

            return 2;
        }

        if ($warnings !== []) {
            $this->warn(sprintf('%d warning(s). Review the output above.', count($warnings)));

            return 1;
        }

        $this->info('All checks passed.');

        return self::SUCCESS;
    }

    /**
     * Print one probe row and bump the exit-code accumulator arrays per
     * the existing severity convention.
     *
     * @param  list<string>  $blockers
     * @param  list<string>  $warnings
     */
    private function reportProbe(Probe $probe, ProbeResult $result, array &$blockers, array &$warnings): void
    {
        $this->line(sprintf('%-24s %-8s %s', $probe->label(), $result->severity, $result->message));

        if ($result->severity === 'critical') {
            $blockers[] = $probe->label();

            return;
        }

        if ($result->severity === 'warning') {
            $warnings[] = $probe->label();
        }
    }
}
