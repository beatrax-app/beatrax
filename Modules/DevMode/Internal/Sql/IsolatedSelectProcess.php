<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Sql;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

// Not final, and the child code is a constant rather than a shipped script:
// the seam a test replaces is this whole object, and a second file on disk
// would be one more thing a packaged build could ship without.
class IsolatedSelectProcess
{
    // Runs with no framework and no autoloader: PDO plus json_encode is the
    // whole job. The busy timeout arrives in argv because this PDO is opened
    // outside Laravel, where the listener that applies it never fires; 0 means
    // the caller's connection configures none, not a number invented here.
    private const string CHILD = <<<'PHP'
        $pdo = new PDO('sqlite:'.$argv[1], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $busyTimeoutMs = (int) $argv[2];
        if ($busyTimeoutMs > 0) {
            $pdo->exec('PRAGMA busy_timeout = '.$busyTimeoutMs);
        }
        $pdo->exec('PRAGMA query_only = 1');
        $started = hrtime(true);
        try {
            $rows = $pdo->query(stream_get_contents(STDIN))->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            fwrite(STDERR, $e->getMessage());
            exit(1);
        }
        echo json_encode(
            ['rows' => $rows, 'duration_ms' => (int) ((hrtime(true) - $started) / 1000000)],
            JSON_INVALID_UTF8_SUBSTITUTE,
        );
        PHP;

    public function __construct(private readonly string $phpBinary = PHP_BINARY) {}

    // An in-memory database lives only inside the calling process, so no child
    // could open it; an empty interpreter path is the embed SAPI, which has no
    // binary to spawn at all.
    public function canIsolate(string $databaseFile): bool
    {
        return $this->phpBinary !== ''
            && $databaseFile !== ''
            && ! str_contains($databaseFile, ':memory:');
    }

    /**
     * @return array{rows: list<object>, duration_ms: int}
     *
     * @throws QueryTimedOutException
     */
    public function run(string $databaseFile, string $sql, int $timeoutSeconds, int $busyTimeoutMs = 0): array
    {
        $process = new Process(
            [$this->phpBinary, '-r', self::CHILD, $databaseFile, (string) max(0, $busyTimeoutMs)],
        );
        $process->setInput($sql);
        $process->setTimeout((float) $timeoutSeconds);

        try {
            $process->run();
        } catch (ProcessTimedOutException $e) {
            // Symfony has already SIGKILLed the child by the time this lands;
            // the runaway statement dies with it and this process is untouched.
            throw new QueryTimedOutException($e->getMessage(), 0, $e);
        }

        if (! $process->isSuccessful()) {
            throw new RuntimeException($this->failureMessage($process));
        }

        return $this->decode($process->getOutput());
    }

    private function failureMessage(Process $process): string
    {
        $stderr = trim($process->getErrorOutput());

        return $stderr !== '' ? $stderr : 'The read-only query process exited with code '.((int) $process->getExitCode()).'.';
    }

    /**
     * @return array{rows: list<object>, duration_ms: int}
     */
    private function decode(string $output): array
    {
        $decoded = json_decode($output, true);
        if (! is_array($decoded) || ! is_array($decoded['rows'] ?? null)) {
            throw new RuntimeException('The read-only query process returned no readable result.');
        }

        $rows = [];
        foreach ($decoded['rows'] as $row) {
            $rows[] = (object) (is_array($row) ? $row : []);
        }

        return [
            'rows' => $rows,
            'duration_ms' => is_int($decoded['duration_ms'] ?? null) ? $decoded['duration_ms'] : 0,
        ];
    }
}
