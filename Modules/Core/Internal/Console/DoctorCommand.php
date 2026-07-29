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
use Modules\Search\Public\Services\FtsHealthCheck;

/**
 * @link ../../../../.docs/features/core/architecture.md
 */
final class DoctorCommand extends Command
{
    private const ROW_FORMAT = '%-24s %-8s %s';

    /** @var string */
    protected $signature = 'beatrax:doctor';

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
        private readonly ?FtsHealthCheck $ftsHealth = null,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        /** @var list<string> $blockers */
        $blockers = [];
        /** @var list<string> $warnings */
        $warnings = [];

        $this->line('beatrax:doctor');
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

        // FtsHealthCheck is optional (null when the Search module is absent
        // or the class_exists() guard has not yet activated it). It lives in
        // Search Public, so DoctorCommand (Core Internal) can import it; the
        // ProbeResult is built here so FtsHealthCheck stays boundary-clean.
        if ($this->ftsHealth !== null) {
            $ftsResult = new ProbeResult($this->ftsHealth->severity(), $this->ftsHealth->message());
            $this->line(sprintf(self::ROW_FORMAT, $this->ftsHealth->label(), $ftsResult->severity, $ftsResult->message));
            if ($ftsResult->severity === 'critical') {
                $blockers[] = $this->ftsHealth->label();
            } elseif ($ftsResult->severity === 'warning') {
                $warnings[] = $this->ftsHealth->label();
            }
        }

        // ext-imap is reported separately as informational-only — the
        // project uses pure-PHP webklex/php-imap, so the extension's
        // presence is neither required nor forbidden. Folding it into the
        // Probe severity model would invent a fourth ("info") bucket.
        $loaded = in_array('imap', get_loaded_extensions(), true);
        $this->line(sprintf(
            self::ROW_FORMAT,
            'ext-imap',
            'info',
            ($loaded ? 'loaded' : 'not loaded').' (beatrax uses webklex/php-imap regardless)',
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
     * @param  list<string>  $blockers
     * @param  list<string>  $warnings
     */
    private function reportProbe(Probe $probe, ProbeResult $result, array &$blockers, array &$warnings): void
    {
        $this->line(sprintf(self::ROW_FORMAT, $probe->label(), $result->severity, $result->message));

        if ($result->severity === 'critical') {
            $blockers[] = $probe->label();

            return;
        }

        if ($result->severity === 'warning') {
            $warnings[] = $probe->label();
        }
    }
}
