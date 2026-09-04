<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Modules\Core\Public\Bootstrap\EnsureAppKey;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Desktop\Internal\Native\FirstLaunchBootstrap;
use Psr\Log\AbstractLogger;

beforeEach(function (): void {
    // A fresh temp dir per test, with the env var restored in afterEach so
    // unrelated tests are not contaminated.
    $this->previousStorageEnv = getenv('NATIVEPHP_STORAGE_PATH');
    $this->tempRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ensure-app-key-'.bin2hex(random_bytes(6));
    mkdir($this->tempRoot, 0755, true);
    putenv('NATIVEPHP_STORAGE_PATH='.$this->tempRoot);

    // A .env of its own, because the action now reads the key back from the
    // file: the shipped key is what a fresh install starts with, and whether
    // it is still there afterwards is the only thing that answers the question.
    $this->shippedKey = 'base64:'.base64_encode(random_bytes(32));
    $this->envFile = $this->tempRoot.DIRECTORY_SEPARATOR.'.env';
    file_put_contents($this->envFile, "APP_NAME=Beatrax\nAPP_KEY={$this->shippedKey}\n");

    $this->app->useEnvironmentPath($this->tempRoot);
    $this->app->loadEnvironmentFrom('.env');

    // key:generate replaces the line matching the key currently in config, not
    // any APP_KEY line, so a fresh install is only simulated once the two agree.
    $this->app->make(ConfigRepository::class)->set('app.key', $this->shippedKey);
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

function appKeyWrittenIn(string $envFile): ?string
{
    if (! is_file($envFile)) {
        return null;
    }

    preg_match('/^APP_KEY=(.*)$/m', (string) file_get_contents($envFile), $matched);

    return $matched === [] ? null : trim($matched[1]);
}

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

        /** @var null|callable(): void */
        public $onCall = null;

        public function call($command, array $parameters = [], $outputBuffer = null): int
        {
            $this->calls[] = [$command, $parameters];

            if ($this->onCall !== null) {
                ($this->onCall)();
            }

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

    $kernel->onCall = fn () => file_put_contents(
        $this->envFile,
        "APP_NAME=Beatrax\nAPP_KEY=base64:".base64_encode(random_bytes(32))."\n",
    );

    $action = new EnsureAppKey($paths, $kernel, environmentFile: $this->envFile);
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

    $action = new EnsureAppKey($paths, $kernel, environmentFile: $this->envFile);
    $action->run();

    expect($kernel->calls)->toBe([]);
    expect($config->get('app.key'))->toBe($existingAppKey);
});

it('is idempotent across successive calls — exactly one invocation, one sentinel', function (): void {
    $paths = $this->app->make(UserDataPathService::class);
    $kernel = ensureAppKeySpyKernel();

    $sentinel = UserDataPathService::appPath('first-launch.app-key-generated');

    $kernel->onCall = fn () => file_put_contents(
        $this->envFile,
        "APP_NAME=Beatrax\nAPP_KEY=base64:".base64_encode(random_bytes(32))."\n",
    );

    $action = new EnsureAppKey($paths, $kernel, environmentFile: $this->envFile);
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

    // Bound explicitly: without it the container resolves the default,
    // base_path('.env'), and this test would rotate the key of the checkout
    // it is running in.
    $this->app->bind(EnsureAppKey::class, fn ($app) => new EnsureAppKey(
        $app->make(UserDataPathService::class),
        $app->make(ConsoleKernel::class),
        environmentFile: $this->envFile,
    ));

    $bootstrap = $this->app->make(FirstLaunchBootstrap::class);

    $bootstrap->runPendingMigrations();

    expect(file_exists($sentinel))->toBeTrue();
    expect($config->get('app.key'))->not->toBeEmpty();

    $bootstrap->runPendingMigrations();

    expect(is_file($sentinel))->toBeTrue();
});

// The failure this exists for. Laravel's key:generate does not check
// file_put_contents and returns success either way, so a read-only .env -- a
// signed application bundle, an install under Program Files, a mounted
// AppImage -- is indistinguishable from a rotation that worked.
it('does not stamp the sentinel when the key never reached the file', function (): void {
    $paths = $this->app->make(UserDataPathService::class);
    $kernel = ensureAppKeySpyKernel();
    $sentinel = UserDataPathService::appPath('first-launch.app-key-generated');

    // The spy writes nothing, which is exactly what a failed write leaves.
    $action = new EnsureAppKey($paths, $kernel, environmentFile: $this->envFile);
    $action->run();

    expect($kernel->calls)->toHaveCount(1)
        ->and(file_exists($sentinel))->toBeFalse()
        ->and(appKeyWrittenIn($this->envFile))->toBe($this->shippedKey);
});

// Without the sentinel the next launch tries again, which is the whole point:
// a rotation that could not happen must not be recorded as one that did.
it('tries again on the next launch after a write that did not land', function (): void {
    $paths = $this->app->make(UserDataPathService::class);
    $kernel = ensureAppKeySpyKernel();

    $action = new EnsureAppKey($paths, $kernel, environmentFile: $this->envFile);
    $action->run();
    $action->run();

    expect($kernel->calls)->toHaveCount(2);
});

// The real command against a real read-only file, so the claim about
// key:generate's behaviour is measured here rather than asserted.
it('leaves the shipped key in place, and says so, when the file cannot be written', function (): void {
    $paths = $this->app->make(UserDataPathService::class);
    $sentinel = UserDataPathService::appPath('first-launch.app-key-generated');

    chmod($this->envFile, 0o444);
    expect(is_writable($this->envFile))->toBeFalse();

    $logger = new class extends AbstractLogger
    {
        /** @var list<string> */
        public array $errors = [];

        public function log($level, $message, array $context = []): void
        {
            if ($level === 'error') {
                $this->errors[] = (string) $message;
            }
        }
    };

    $action = new EnsureAppKey($paths, $this->app->make(ConsoleKernel::class), $logger, $this->envFile);
    @$action->run();

    expect(appKeyWrittenIn($this->envFile))->toBe($this->shippedKey)
        ->and(file_exists($sentinel))->toBeFalse()
        ->and($logger->errors)->not->toBeEmpty()
        ->and($logger->errors[0])->toContain('still using the key shipped in the bundle');

    chmod($this->envFile, 0o644);
});
