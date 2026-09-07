<?php

declare(strict_types=1);

namespace Modules\Desktop\Commands;

use Illuminate\Console\Command;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Desktop\Internal\Boot\ReadsAMacBundle;

// Regenerates the calibration fixture from bundles installed on this machine.
// The rules are only as good as the bundles they were checked against, and a
// recording nobody can reproduce is an assertion.
final class RecordMacBundlesCommand extends Command
{
    /** @var string */
    protected $signature = 'desktop:record-mac-bundles {--out= : where to write the fixture}';

    /** @var string */
    protected $description = 'Record what real macOS bundles carry, so the App Store rules are calibrated against shipped software.';

    private const string DEFAULT_OUT = 'Modules/Desktop/tests/Fixtures/mac-bundles-observed.json';

    public function handle(ReadsAMacBundle $reader, Clock $clock): int
    {
        $bundles = [];

        foreach ($this->candidates($reader) as $path => $fromTheStore) {
            $bundles[] = [
                'name' => basename($path),
                'from_the_store' => $fromTheStore,
                'app_entitlements' => $reader->entitlementsOf($path),
                'children' => $this->childrenOf($reader, $path),
            ];
        }

        return $this->write($bundles, $clock);
    }

    // Three from the store and one Electron app from outside it. More store
    // bundles widen the evidence; more than one outside adds nothing, because
    // one already answers whether the rules find anything at all.

    // The outside one has to be Electron: a bundle shaped nothing like this
    // product could be refused for reasons this product will never meet, and
    // the negative control would stop meaning anything.
    /** @return array<string, bool> path => came from the store */
    private function candidates(ReadsAMacBundle $reader): array
    {
        $store = [];
        $outside = [];

        foreach ($reader->installedBundles() as $path => $what) {
            if ($what['from_the_store']) {
                $store[$path] = true;

                continue;
            }

            if ($what['electron']) {
                $outside[$path] = false;
            }
        }

        return [...array_slice($store, 0, 3, true), ...array_slice($outside, 0, 1, true)];
    }

    /** @return array<string, array<string, mixed>> */
    private function childrenOf(ReadsAMacBundle $reader, string $path): array
    {
        $children = [];
        $main = 'Contents/MacOS/'.basename($path, '.app');

        foreach ($reader->executablesIn($path) as $relative) {
            if ($relative === $main) {
                continue;
            }

            $children[$relative] = [
                'entitlements' => $reader->entitlementsOf($path.'/'.$relative),
                'signed' => $reader->isSigned($path.'/'.$relative),
                'inMacOsDirectory' => $reader->isInAMacOsDirectory($relative),
                'launched' => str_starts_with($relative, 'Contents/Frameworks/')
                    && str_contains($relative, '.app/Contents/MacOS/'),
            ];
        }

        return $children;
    }

    /** @param list<array<string, mixed>> $bundles */
    private function write(array $bundles, Clock $clock): int
    {
        $store = count(array_filter($bundles, static fn (array $b): bool => $b['from_the_store'] === true));

        // A recording with only one side proves nothing: without a store
        // bundle there is no rule to disprove, and without one from outside,
        // rules that refuse nothing would read as calibrated.
        if ($store === 0 || $store === count($bundles)) {
            $this->components->error('Need at least one store bundle and one from outside it; found '.$store.' of '.count($bundles).'.');

            return self::FAILURE;
        }

        /** @var string|null $out */
        $out = $this->option('out');
        $path = $out ?? UserDataPathService::projectPath(self::DEFAULT_OUT);

        file_put_contents($path, json_encode(
            ['recorded_on' => $clock->now()->toDateString(), 'bundles' => $bundles],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        )."\n");

        $this->components->info('Recorded '.count($bundles).' bundles ('.$store.' from the store) into '.$path);

        return self::SUCCESS;
    }
}
