<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Events\UserInstalled;

beforeEach(function (): void {
    $this->tmpRoot = sys_get_temp_dir().'/beatrax-corpus-'.bin2hex(random_bytes(6));
    if (! is_dir($this->tmpRoot)) {
        mkdir($this->tmpRoot, 0o755, true);
    }
    $this->corpusPath = $this->tmpRoot.'/merchant-mappings.yaml';
    $this->heuristicsPath = $this->tmpRoot.'/built-in-heuristics.yaml';

    // Three valid entries + one malformed (missing required `name`).
    $yaml = <<<'YAML'
entries:
  - pattern: "ALPHA"
    name: "Alpha Inc"
    category: "Groceries"
    region: "NL"
    contributor: "beatrax-bot"
  - pattern: "BRAVO"
    name: "Bravo Ltd"
    region: "NL"
    contributor: "beatrax-bot"
  - pattern: "CHARLIE"
    name: "Charlie BV"
    contributor: "beatrax-bot"
  - pattern: "DELTA"
    contributor: "beatrax-bot"
YAML;
    file_put_contents($this->corpusPath, $yaml);
    // Empty heuristics file.
    file_put_contents($this->heuristicsPath, "entries: []\n");

    /** @var ConfigRepository $config */
    $config = $this->app->make(ConfigRepository::class);
    $config->set('community.corpus.bundled_path', $this->corpusPath);
    $config->set('community.corpus.heuristics_path', $this->heuristicsPath);
});

afterEach(function (): void {
    if (isset($this->corpusPath) && is_file($this->corpusPath)) {
        @unlink($this->corpusPath);
    }
    if (isset($this->heuristicsPath) && is_file($this->heuristicsPath)) {
        @unlink($this->heuristicsPath);
    }
    if (isset($this->tmpRoot) && is_dir($this->tmpRoot)) {
        @rmdir($this->tmpRoot);
    }
});

it('seeds three valid entries and skips the malformed one on UserInstalled', function (): void {
    $user = makeCommunityTestUser('corpus-seed-a');

    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);
    $events->dispatch(new UserInstalled($user->id));

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $rows = $db->connection()->table('community_merchant_mappings')->whereNull('user_id')->get();

    expect($rows->count())->toBe(3);
    expect($rows->pluck('pattern')->all())->toContain('ALPHA', 'BRAVO', 'CHARLIE');
    expect($rows->pluck('pattern')->all())->not->toContain('DELTA');
});

it('is idempotent on re-dispatch — no duplicate rows', function (): void {
    $user = makeCommunityTestUser('corpus-seed-b');

    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);
    $events->dispatch(new UserInstalled($user->id));
    $events->dispatch(new UserInstalled($user->id));

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    expect($db->connection()->table('community_merchant_mappings')->whereNull('user_id')->count())->toBe(3);
});

it('updates the existing row when the YAML name changes on re-dispatch', function (): void {
    $user = makeCommunityTestUser('corpus-seed-c');

    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);
    $events->dispatch(new UserInstalled($user->id));

    // Rewrite the corpus file with a new name for ALPHA.
    $updated = <<<'YAML'
entries:
  - pattern: "ALPHA"
    name: "Alpha Renamed"
    category: "Groceries"
    region: "NL"
    contributor: "beatrax-bot"
  - pattern: "BRAVO"
    name: "Bravo Ltd"
    region: "NL"
    contributor: "beatrax-bot"
  - pattern: "CHARLIE"
    name: "Charlie BV"
    contributor: "beatrax-bot"
YAML;
    file_put_contents($this->corpusPath, $updated);

    $events->dispatch(new UserInstalled($user->id));

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $alpha = $db->connection()->table('community_merchant_mappings')
        ->whereNull('user_id')
        ->where('pattern', 'ALPHA')
        ->value('name');
    expect($alpha)->toBe('Alpha Renamed');
    expect($db->connection()->table('community_merchant_mappings')->whereNull('user_id')->count())->toBe(3);
});

it('tolerates a missing heuristics file without aborting the main corpus seed', function (): void {
    /** @var ConfigRepository $config */
    $config = $this->app->make(ConfigRepository::class);
    $config->set('community.corpus.heuristics_path', $this->tmpRoot.'/does-not-exist.yaml');

    $user = makeCommunityTestUser('corpus-seed-d');

    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);
    $events->dispatch(new UserInstalled($user->id));

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    expect($db->connection()->table('community_merchant_mappings')->whereNull('user_id')->count())->toBe(3);
});
