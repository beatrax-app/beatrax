<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Fixtures\Camt053Rebaser;
use App\Fixtures\MonthShift;
use App\Fixtures\Mt940Rebaser;
use App\Fixtures\PresetCsvRebaser;
use App\Fixtures\RebasesStatementDates;
use App\Fixtures\StatementRebaseFailed;
use App\Fixtures\StatementRebaseResult;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\SafeDate;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ingestion\Public\Dto\AccountResolution;
use Modules\Ingestion\Public\Services\SourceAdapterRegistry;
use Throwable;

/**
 * @link ../../../.docs/local_development/rebasing-a-statement-fixture.md
 */
final class RebaseStatementFixtureCommand extends Command
{
    /** @var string */
    protected $signature = 'fixture:rebase
        {fixture : Fixture path, or a bare filename resolved under tests/fixtures/}
        {--out= : Where to write the rebased copy; defaults to storage/app/rebased/}
        {--months= : Shift this many whole months instead of deriving one from the anchor}
        {--anchor= : Land the newest row in the month of this Y-m-d date; defaults to today}';

    /** @var string */
    protected $description = 'Write a date-rebased copy of a shipped statement fixture so its rows land inside the windows the product reads. Developer-only.';

    public function __construct(
        private readonly PresetCsvRebaser $csv,
        private readonly Camt053Rebaser $camt053,
        private readonly Mt940Rebaser $mt940,
        private readonly SourceAdapterRegistry $adapters,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            return $this->rebase();
        } catch (StatementRebaseFailed $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function rebase(): int
    {
        $source = $this->source();
        $contents = (string) file_get_contents($source);
        $rebaser = $this->rebaser($source, $contents);
        $format = $rebaser->format($contents);

        $result = $rebaser->rebase($contents, $this->shift($rebaser, $contents));
        $destination = $this->destination($source);

        $this->verify($source, $format, $result, $destination);

        $this->line(sprintf('Format          %s', $format));
        $this->line(sprintf('Shift           %+d months', $result->months));
        $this->line(sprintf('Dates rewritten %d', $result->datesRewritten));
        $this->line(sprintf(
            'Was             %s .. %s',
            $result->oldestBefore->toDateString(),
            $result->newestBefore->toDateString(),
        ));
        $this->line(sprintf(
            'Now             %s .. %s',
            $result->oldestAfter->toDateString(),
            $result->newestAfter->toDateString(),
        ));
        $this->info($destination);

        return self::SUCCESS;
    }

    // Re-reads the copy through the adapter that will read it on import, so a
    // shift that produced a file the parser cannot line up fails here instead of
    // on the device.
    private function verify(string $source, string $format, StatementRebaseResult $result, string $destination): void
    {
        $before = $this->parseOutcome($source, $format);

        if (file_put_contents($destination, $result->contents) === false) {
            throw new StatementRebaseFailed(sprintf('Could not write %s', $destination));
        }

        $after = $this->parseOutcome($destination, $format);

        if ($before === $after) {
            return;
        }

        @unlink($destination);

        throw new StatementRebaseFailed(sprintf(
            'The rebased copy reads as %s where the source reads as %s; refusing to leave it behind.',
            $this->describe($after),
            $this->describe($before),
        ));
    }

    // Rows yielded AND where the read stopped, because a fixture can be
    // deliberately unreadable partway through -- asn-partial-failure.csv is -- and
    // a plain count would call a copy that broke one row earlier a match.
    /**
     * @return array{rows: int, stopped: ?string}
     */
    private function parseOutcome(string $path, string $format): array
    {
        $resolver = new class implements AccountResolver
        {
            public function resolve(string $iban): AccountResolution
            {
                return AccountResolution::unknown($iban);
            }
        };

        $rows = 0;

        try {
            $parsed = $this->adapters->for($format)->parse($path, $resolver);

            // Drained by hand rather than with foreach: the rows themselves
            // are not wanted here, only how many arrived before it stopped.
            while ($parsed->valid()) {
                $rows++;
                $parsed->next();
            }

            return ['rows' => $rows, 'stopped' => null];
        } catch (Throwable $e) {
            return ['rows' => $rows, 'stopped' => $e->getMessage()];
        }
    }

    /**
     * @param  array{rows: int, stopped: ?string}  $outcome
     */
    private function describe(array $outcome): string
    {
        return $outcome['stopped'] === null
            ? sprintf('%d rows', $outcome['rows'])
            : sprintf('%d rows then "%s"', $outcome['rows'], $outcome['stopped']);
    }

    private function shift(RebasesStatementDates $rebaser, string $contents): MonthShift
    {
        $months = $this->option('months');
        if (is_string($months) && $months !== '') {
            return MonthShift::of((int) $months);
        }

        $newest = $rebaser->newestDate($contents);
        if (! $newest instanceof CarbonImmutable) {
            throw new StatementRebaseFailed('Could not find a newest date in this fixture.');
        }

        return MonthShift::intoMonthOf($newest, $this->anchor());
    }

    private function anchor(): CarbonImmutable
    {
        $anchor = $this->option('anchor');
        if (! is_string($anchor) || $anchor === '') {
            return CarbonImmutable::now()->startOfDay();
        }

        $parsed = SafeDate::fromFormatOrNull('!Y-m-d', $anchor);
        if (! $parsed instanceof CarbonImmutable) {
            throw new StatementRebaseFailed(sprintf('--anchor must be a Y-m-d date, got %s', $anchor));
        }

        return $parsed;
    }

    private function rebaser(string $source, string $contents): RebasesStatementDates
    {
        foreach ([$this->csv, $this->camt053, $this->mt940] as $rebaser) {
            if ($rebaser->handles($source, $contents)) {
                return $rebaser;
            }
        }

        throw new StatementRebaseFailed(sprintf(
            'No rebaser recognises %s. Supported: a CSV matching a shipped preset, a camt.053 XML, or an MT940 .sta.',
            $source,
        ));
    }

    private function source(): string
    {
        $fixture = $this->argument('fixture');
        $candidate = str_contains($fixture, DIRECTORY_SEPARATOR)
            ? $fixture
            : UserDataPathService::projectPath('tests/fixtures/'.$fixture);

        if (! is_file($candidate) || ! is_readable($candidate)) {
            throw new StatementRebaseFailed(sprintf('No readable fixture at %s', $candidate));
        }

        return $candidate;
    }

    // The extension has to survive: the header sniffer refuses a CSV that does not
    // end in .csv, so a copy written under another name never reaches its adapter.
    private function destination(string $source): string
    {
        $extension = pathinfo($source, PATHINFO_EXTENSION);
        $out = $this->option('out');

        if (is_string($out) && $out !== '') {
            if (strcasecmp(pathinfo($out, PATHINFO_EXTENSION), $extension) !== 0) {
                throw new StatementRebaseFailed(sprintf('--out must keep the .%s extension, got %s', $extension, $out));
            }

            return $out;
        }

        $directory = UserDataPathService::appPath('rebased');
        if (! is_dir($directory) && ! mkdir($directory, 0o755, recursive: true) && ! is_dir($directory)) {
            throw new StatementRebaseFailed(sprintf('Could not create %s', $directory));
        }

        return $directory.DIRECTORY_SEPARATOR.basename($source);
    }
}
