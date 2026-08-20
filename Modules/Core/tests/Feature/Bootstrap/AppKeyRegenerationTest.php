<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Modules\Core\Public\Bootstrap\EnsureAppKey;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Desktop\Internal\Native\FirstLaunchBootstrap;

beforeEach(function (): void {
    // A fresh temp dir per test, with the env var restored in afterEach so
    // unrelated tests are not contaminated.
    $this->previousStorageEnv = getenv('NATIVEPHP_STORAGE_PATH');
    $this->tempRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ensure-app-key-'.bin2hex(random_bytes(6));
    mkdir($this->tempRoot, 0755, true);
    putenv('NATIVEPHP_STORAGE_PATH='.$this->tempRoot);
});

afterEach(function (): void {
    /** @var string $tempRoot */
    $tempRoot = $this->tempRoot;
    if (is_dir($tempRoot)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tempRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            $path = $file->getPathname();
            if ($file->isDir()) {
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($tempRoot);
    }

    /** @var string|false $prev */
    $prev = $this->previousStorageEnv;
    if (is_string($prev) && $prev !== '') {
        putenv('NATIVEPHP_STORAGE_PATH='.$prev);
    } else {
        putenv('NATIVEPHP_STORAGE_PATH');
    }
});

// A spy kernel so `key:generate` is counted, never run against the real .env.
/**
 * @return ConsoleKernel
 */
function ensureAppKeySpyKernel(): object
{
    return new class implements ConsoleKernel
    {
        /** @var array<int, array{0: string, 1: array<int|string, mixed>}> */
        public array $calls = [];

        public function handle($input, $output = null): int
        {
            return 0;
        }

        public function terminate($input, $status): void {}

        public function call($command, array $parameters = [], $outputBuffer = null): int
        {
            $this->calls[] = [$command, $parameters];

            return 0;
        }

        public function queue($command, array $parameters = [])
        {
            return null;
        }

        public function all(): array
        {
            return [];
        }

        public function output(): string
        {
            return '';
        }

        public function bootstrap(): void {}

        public function getApplication() {}
    };
}

it('invokes key:generate --force and creates the sentinel when absent', function (): void {
    $paths = $this->app->make(UserDataPathService::class);
    $kernel = ensureAppKeySpyKernel();

    $sentinel = UserDataPathService::appPath('first-launch.app-key-generated');
    expect(file_exists($sentinel))->toBeFalse();

    $action = new EnsureAppKey($paths, $kernel);
    $action->run();

    expect($kernel->calls)->toHaveCount(1);
    expect($kernel->calls[0][0])->toBe('key:generate');
    expect($kernel->calls[0][1])->toBe(['--force' => true]);
    expect(file_exists($sentinel))->toBeTrue();
});

it('is a no-op when the sentinel already exists', function (): void {
    $paths = $this->app->make(UserDataPathService::class);
    $config = $this->app->make(ConfigRepository::class);
    $kernel = ensureAppKeySpyKernel();

    $sentinel = UserDataPathService::appPath('first-launch.app-key-generated');
    @mkdir(dirname($sentinel), 0755, true);
    file_put_contents($sentinel, '');

    $existingAppKey = $config->get('app.key');

    $action = new EnsureAppKey($paths, $kernel);
    $action->run();

    expect($kernel->calls)->toBe([]);
    expect($config->get('app.key'))->toBe($existingAppKey);
});

it('is idempotent across successive calls — exactly one invocation, one sentinel', function (): void {
    $paths = $this->app->make(UserDataPathService::class);
    $kernel = ensureAppKeySpyKernel();

    $sentinel = UserDataPathService::appPath('first-launch.app-key-generated');

    $action = new EnsureAppKey($paths, $kernel);
    $action->run();
    $action->run();
    $action->run();

    expect($kernel->calls)->toHaveCount(1);
    expect(file_exists($sentinel))->toBeTrue();

    expect(is_file($sentinel))->toBeTrue();
});

it('FirstLaunchBootstrap chain leaves the sentinel present and APP_KEY non-empty (integration)', function (): void {
    // Driven through the chained-bootstrap call site, not a standalone
    // EnsureAppKey, so a regression in the FirstLaunchBootstrap wiring shows up.
    $config = $this->app->make(ConfigRepository::class);

    $sentinel = UserDataPathService::appPath('first-launch.app-key-generated');
    expect(file_exists($sentinel))->toBeFalse();

    $bootstrap = $this->app->make(FirstLaunchBootstrap::class);

    $bootstrap->runPendingMigrations();

    expect(file_exists($sentinel))->toBeTrue();
    expect($config->get('app.key'))->not->toBeEmpty();

    $bootstrap->runPendingMigrations();

    expect(is_file($sentinel))->toBeTrue();
});
