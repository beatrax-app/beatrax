<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Modules\Community\Internal\Corpus\CorpusLoader;
use Modules\Core\Models\User;
use Modules\Ledger\Models\Category;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;

// The bundled corpus is checked against the default tree, whose rows carry
// user_id = NULL. Read unscoped, a category one household member invented
// answered "known" for the corpus every other reader loads.

function corpusScopeRecorder(): LoggerInterface
{
    return new class extends AbstractLogger
    {
        /** @var list<string> */
        public array $warnings = [];

        public function log($level, $message, array $context = []): void
        {
            if ((string) $level === 'warning') {
                $this->warnings[] = (string) $message;
            }
        }
    };
}

beforeEach(function (): void {
    $this->tmpRoot = sys_get_temp_dir().'/beatrax-corpus-scope-'.bin2hex(random_bytes(6));
    $this->merchantsDir = $this->tmpRoot.'/merchants';
    mkdir($this->merchantsDir, 0o755, true);
    $this->corpusPath = $this->merchantsDir.'/nl.yaml';

    /** @var ConfigRepository $config */
    $config = $this->app->make(ConfigRepository::class);
    $config->set('community.corpus.root', $this->tmpRoot);

    file_put_contents($this->corpusPath, <<<'YAML'
    entries:
      - pattern: "OMEGA"
        name: "Omega LLC"
        category: "Sailing club dues"
        contributor: "beatrax-bot"
    YAML);

    $this->logger = corpusScopeRecorder();
    $this->app->instance(LoggerInterface::class, $this->logger);
});

afterEach(function (): void {
    @unlink($this->corpusPath);
    @rmdir($this->merchantsDir);
    @rmdir($this->tmpRoot);
});

it('calls a category the default tree does not have unknown, whoever owns one by that name', function (): void {
    $owner = User::query()->create([
        'username' => 'corpus-scope-owner',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);

    Category::create([
        'user_id' => $owner->id,
        'name' => 'Sailing club dues',
        'slug' => 'sailing-club-dues',
        'kind' => 'expense',
        'display_order' => 30,
    ]);

    $this->app->make(CorpusLoader::class)->loadBundled();

    expect($this->logger->warnings)->not->toBe(
        [],
        'A category belonging to one household member satisfied the corpus check for everyone.',
    );
});

it('still calls a category the default tree does have known', function (): void {
    Category::create([
        'user_id' => null,
        'name' => 'Sailing club dues',
        'slug' => 'sailing-club-dues',
        'kind' => 'expense',
        'display_order' => 30,
    ]);

    $this->app->make(CorpusLoader::class)->loadBundled();

    expect($this->logger->warnings)->toBe([]);
});
