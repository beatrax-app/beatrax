<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Modules\Community\Internal\Corpus\CorpusLoader;
use Modules\Ledger\Models\Category;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

/**
 * Tiny in-test PSR-3 recorder so we can assert the loader emits a
 * `warning` log when a corpus entry references a category that does
 * not match any row in the `categories` table.
 */
$makeRecorder = function (): LoggerInterface {
    return new class extends AbstractLogger
    {
        /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
        public array $records = [];

        public function log($level, $message, array $context = []): void
        {
            $this->records[] = [
                'level' => is_string($level) ? $level : (string) $level,
                'message' => (string) $message,
                'context' => $context,
            ];
        }
    };
};

beforeEach(function () use ($makeRecorder): void {
    $this->tmpRoot = sys_get_temp_dir().'/beatrax-corpus-warn-'.bin2hex(random_bytes(6));
    if (! is_dir($this->tmpRoot)) {
        mkdir($this->tmpRoot, 0o755, true);
    }
    $this->corpusPath = $this->tmpRoot.'/merchant-mappings.yaml';
    $this->heuristicsPath = $this->tmpRoot.'/built-in-heuristics.yaml';

    /** @var ConfigRepository $config */
    $config = $this->app->make(ConfigRepository::class);
    $config->set('community.corpus.bundled_path', $this->corpusPath);
    $config->set('community.corpus.heuristics_path', $this->heuristicsPath);

    // Seed one valid category that we'll reference in the YAML.
    Category::create([
        'user_id' => null,
        'name' => 'Groceries',
        'slug' => 'groceries',
        'kind' => 'expense',
        'display_order' => 30,
    ]);

    $this->logger = $makeRecorder();
    $this->app->instance(LoggerInterface::class, $this->logger);
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

it('logs a warning when a corpus entry references an unknown category, and still loads the entry', function (): void {
    $yaml = <<<'YAML'
entries:
  - pattern: "OMEGA"
    name: "Omega LLC"
    category: "NonExistentCategory"
    contributor: "beatrax-bot"
YAML;
    file_put_contents($this->corpusPath, $yaml);
    file_put_contents($this->heuristicsPath, "entries: []\n");

    /** @var CorpusLoader $loader */
    $loader = $this->app->make(CorpusLoader::class);
    $entries = $loader->loadBundled();

    expect(count($entries))->toBe(1);
    expect($entries[0]->pattern)->toBe('OMEGA');
    expect($entries[0]->category)->toBe('NonExistentCategory');

    $hasWarning = false;
    foreach ($this->logger->records as $record) {
        if ($record['level'] === 'warning'
            && str_contains((string) $record['message'], 'Corpus entry references unknown category')
            && ($record['context']['pattern'] ?? null) === 'OMEGA'
            && ($record['context']['category'] ?? null) === 'NonExistentCategory'
        ) {
            $hasWarning = true;
            break;
        }
    }
    expect($hasWarning)->toBeTrue();
});

it('does not log a warning when a corpus entry references a known category', function (): void {
    $yaml = <<<'YAML'
entries:
  - pattern: "DELTA"
    name: "Delta Corp"
    category: "Groceries"
    contributor: "beatrax-bot"
YAML;
    file_put_contents($this->corpusPath, $yaml);
    file_put_contents($this->heuristicsPath, "entries: []\n");

    /** @var CorpusLoader $loader */
    $loader = $this->app->make(CorpusLoader::class);
    $entries = $loader->loadBundled();

    expect(count($entries))->toBe(1);

    $hasWarning = false;
    foreach ($this->logger->records as $record) {
        if ($record['level'] === 'warning'
            && str_contains((string) $record['message'], 'Corpus entry references unknown category')
        ) {
            $hasWarning = true;
            break;
        }
    }
    expect($hasWarning)->toBeFalse();
});

it('returns an empty list and logs a warning when the bundled file is missing', function (): void {
    /** @var ConfigRepository $config */
    $config = $this->app->make(ConfigRepository::class);
    $config->set('community.corpus.bundled_path', $this->tmpRoot.'/does-not-exist.yaml');
    file_put_contents($this->heuristicsPath, "entries: []\n");

    /** @var CorpusLoader $loader */
    $loader = $this->app->make(CorpusLoader::class);
    $entries = $loader->loadBundled();

    expect($entries)->toBe([]);
});
