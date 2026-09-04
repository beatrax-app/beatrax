<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\PatternScan;
use Modules\Migration\Internal\Actions\ConfirmMigration;
use Modules\Migration\Internal\Actions\StartMigrationRun;
use Modules\Migration\Tests\Support\MigrationFixturePaths;

uses(RefreshDatabase::class);

// A wrapping transaction around promote() is invisible to every other test in
// this module: the same rows land, in the same order, with the same counts. It
// only shows on a real import, as the one unbounded transaction the chunked
// promotion path exists to avoid. So the level itself is what is asserted.

beforeEach(function (): void {
    $this->promotionDepthUser = User::create([
        'username' => 'promotion-depth-fixture-user',
        'password' => 'opensesame',
        'period_start_day' => 1,
    ]);
    $this->promotionDepthDb = app(DatabaseManager::class);
});

it('writes the promoted ledger rows at the depth it was called at, not one inside it', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->promotionDepthDb;
    $connection = $db->connection();

    $run = app(StartMigrationRun::class)->__invoke(
        $this->promotionDepthUser,
        'ynab4',
        MigrationFixturePaths::ynab4Dir('v1'),
        'Beatrax Test Budget.zip',
    );

    // RefreshDatabase already holds one open transaction, so the depth a
    // correct promotion writes at is whatever this reads, not zero.
    $ambient = $connection->transactionLevel();

    /** @var array<string, int> $depthByTable */
    $depthByTable = [];

    $connection->listen(function (object $query) use ($connection, &$depthByTable): void {
        $table = PatternScan::first('/^\s*insert(?:\s+or\s+\w+)?\s+into\s+"([^"]+)"/i', (string) $query->sql);
        if ($table === []) {
            return;
        }

        $name = $table[1];
        $depth = $connection->transactionLevel();
        $depthByTable[$name] = min($depthByTable[$name] ?? $depth, $depth);
    });

    app(ConfirmMigration::class)->__invoke($run->id, $this->promotionDepthUser);

    $promoted = array_filter(
        $depthByTable,
        static fn (string $name): bool => ! str_starts_with($name, 'migration_'),
        ARRAY_FILTER_USE_KEY,
    );

    // Counted before the depths are read: a listener that saw no insert would
    // report no table written too deep, which is the answer a correct run gives.
    expect($promoted)->not->toBeEmpty()
        ->and(array_keys($promoted))->toContain('categories', 'accounts', 'transactions');

    $depths = [];
    foreach ($promoted as $name => $depth) {
        $depths[] = $name.' at depth '.$depth;
    }

    // A writer opening its own transaction per chunk is the shape this pipeline
    // is built as, so depth deeper than ambient is expected on some tables. What
    // an outer wrap changes is the shallowest: with one, nothing is written at
    // the depth promote() was called at.
    expect(min($promoted))->toBe(
        $ambient,
        'Promotion writes a whole budget history and must not be wrapped in one transaction — '
        .'only the status change and the counts may be. Nothing was written at the depth '
        ."promote() was called at ({$ambient}), so a transaction is open around all of it:\n  "
        .implode("\n  ", $depths),
    );
});

it('opens exactly one transaction in the whole promotion path, around the status change', function (): void {
    $root = base_path('Modules/Migration/Internal');
    expect(is_dir($root))->toBeTrue();

    $sites = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
    );

    /** @var SplFileInfo $file */
    foreach ($iterator as $file) {
        $path = $file->getPathname();
        if (! $file->isFile() || ! str_ends_with($path, '.php')) {
            continue;
        }

        $contents = (string) file_get_contents($path);
        $stripped = preg_replace('#/\*.*?\*/|//[^\n]*#s', '', $contents) ?? $contents;

        foreach (PatternScan::all('/->(transaction|beginTransaction)\s*\(/', $stripped)[1] ?? [] as $call) {
            $sites[] = str_replace(base_path().'/', '', $path).'::'.$call;
        }
    }

    expect($sites)->toBe(
        ['Modules/Migration/Internal/Actions/ConfirmMigration.php::transaction'],
        'The import path opens one transaction, and it is the one around the status flip and its '
        .'counts. A second one is either a wrap around promotion or a nested wrap inside it, and '
        ."both are the unbounded write this pipeline is chunked to avoid. Sites found:\n  "
        .implode("\n  ", $sites),
    );

    $confirm = (string) file_get_contents(base_path('Modules/Migration/Internal/Actions/ConfirmMigration.php'));
    $wrapped = PatternScan::first('/->transaction\(\s*fn\s*\([^)]*\)\s*:\s*\w+\s*=>\s*\$this->(\w+)\(/', $confirm);

    expect($wrapped)->not->toBe([], 'Could not read what ConfirmMigration wraps in its transaction.');
    expect($wrapped[1])->toBe('flipToConfirmed');
});
