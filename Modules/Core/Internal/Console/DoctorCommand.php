<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console;

use Illuminate\Console\Command;
use Modules\Core\Internal\Console\Probes\BackupFreshnessProbe;
use Modules\Core\Internal\Console\Probes\Probe;
use Modules\Core\Internal\Console\Probes\ProbeResult;
use Modules\Core\Internal\Console\Probes\SynchronousModeProbe;
use Modules\Core\Internal\Console\Probes\WalModeProbe;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Reports the versions of every external tool diederik depends on AND
 * runs the Phase 11 operational probes:
 *
 * - PHP runtime (must be ≥ 8.5; `ext-imap` may or may not be loaded — the
 *   project deliberately uses the pure-PHP `webklex/php-imap`).
 * - Composer.
 * - SQLite (CLI; the PHP `sqlite3` extension separately).
 * - Node (required for `npm run build`).
 * - WalModeProbe / SynchronousModeProbe / BackupFreshnessProbe — each
 *   implements the `Probe` contract under `Modules/Core/Internal/Console/Probes/`
 *   and returns a `ProbeResult` summarising one operational signal.
 *
 * Exit codes:
 *   0 — every check meets its minimum
 *   1 — one or more soft warnings (e.g. optional tool missing, probe warning)
 *   2 — at least one hard blocker (or probe critical)
 *
 * The legacy inline `reportTool()` PHP / Composer / SQLite / Node checks
 * deliberately stay inline rather than being migrated to the `Probe`
 * interface. The contract was introduced in Phase 11-03 for the three
 * SQLite-substrate probes; refactoring the working tool-version checks
 * onto the same interface is an aesthetic carry-cost reserved for a
 * future polish phase. The aggregation loop appends new probe results
 * after the existing inline output, preserving the current 0/1/2 exit
 * code semantics.
 */
final class DoctorCommand extends Command
{
    /** @var string */
    protected $signature = 'diederik:doctor';

    /** @var string */
    protected $description = 'Report installed PHP / Composer / SQLite versions and verify minimums.';

    private const MIN_PHP = '8.5';

    public function __construct(
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

        // PHP runtime — match against major.minor only so alpha / beta / RC
        // builds of the minimum minor version still pass. `phpversion()` is
        // used (not the PHP_VERSION constant) so the comparison is honest at
        // runtime rather than statically pre-computed.
        $phpVersion = phpversion();
        if (preg_match('/^(\d+\.\d+)/', $phpVersion, $m) === 1 && version_compare($m[1], self::MIN_PHP, '>=')) {
            $this->line(sprintf('PHP        %s   ok', $phpVersion));
        } else {
            $this->line(sprintf('PHP        %s   BLOCKER (min %s)', $phpVersion, self::MIN_PHP));
            $blockers[] = 'PHP';
        }

        // The project uses the pure-PHP `webklex/php-imap` library rather
        // than the native ext-imap module (which PHP 8.4 unbundled). The
        // native extension may still be loaded on older builds — that is
        // informational, not a warning, because no diederik code path
        // consumes it.
        $loaded = in_array('imap', get_loaded_extensions(), true);
        $this->line(sprintf(
            'ext-imap   %s   info (diederik uses webklex/php-imap regardless)',
            $loaded ? 'loaded' : 'not loaded',
        ));

        $this->reportTool('Composer  ', ['composer', '--version'], 'Composer not on PATH', $warnings);
        $this->reportTool('SQLite    ', ['sqlite3', '--version'], 'sqlite3 CLI not on PATH', $warnings);
        $this->reportTool('Node      ', ['node', '--version'], 'node not on PATH (required for Vite asset builds)', $warnings);

        $this->line('');

        // Phase 11 probes: each prints `{$label}: {$severity} — {$message}`
        // and contributes to the exit-code aggregation per the existing
        // 0 / 1 / 2 convention.
        $probes = [$this->walProbe, $this->synchronousProbe, $this->backupFreshnessProbe];
        foreach ($probes as $probe) {
            $result = $probe->run();
            $this->reportProbe($probe, $result, $blockers, $warnings);
        }

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

    /**
     * Runs the given command and either logs an OK line with the captured
     * version string or records a warning.
     *
     * @param  list<string>  $command
     * @param  list<string>  $warnings
     */
    private function reportTool(string $label, array $command, string $warningMessage, array &$warnings): void
    {
        [$version, $available] = $this->runVersion($command);

        if ($available) {
            $this->line(sprintf('%s %s   ok', $label, $version));

            return;
        }

        $this->line(sprintf('%s %s   WARNING (%s)', $label, $version, $warningMessage));
        $warnings[] = $label;
    }

    /**
     * Runs the given command and returns [version-string, success]. When
     * the command exits 0, the version is read from stdout only — chatty
     * deprecation notices on stderr no longer leak into the displayed
     * line. stderr is consulted only on failure to produce the user-facing
     * error message.
     *
     * @param  list<string>  $command
     * @return array{0: string, 1: bool}
     */
    private function runVersion(array $command): array
    {
        $process = new Process($command);
        $process->setTimeout(5.0);

        try {
            $process->run();
        } catch (Throwable) {
            return ['(not available)', false];
        }

        if (! $process->isSuccessful()) {
            $stderr = trim($process->getErrorOutput());

            return [$stderr === '' ? '(not available)' : $stderr, false];
        }

        $stdout = trim($process->getOutput());

        return [$stdout === '' ? '(empty)' : $stdout, true];
    }
}
