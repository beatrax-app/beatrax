<?php

declare(strict_types=1);

use Illuminate\Log\LogManager;
use Modules\Core\Public\Bootstrap\EnsurePrivateLogFiles;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\OwnerOnlyPath;
use Modules\Core\Public\Support\SecretFileMode;

function privateLogScratch(): string
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-private-log-'.bin2hex(random_bytes(6));
    mkdir($dir.DIRECTORY_SEPARATOR.'logs', 0o755, true);

    return $dir;
}

function privateLogMode(string $path): int
{
    clearstatcache(true, $path);

    return (int) fileperms($path) & 0o777;
}

// The env var moves UserDataPathService's whole storage root, databaseFile()
// included, so this suite lives in Unit where no RefreshDatabase connection is
// open against the path being swapped out from under it.
beforeEach(function (): void {
    $this->previousStorage = getenv('NATIVEPHP_STORAGE_PATH');
    $this->scratch = privateLogScratch();
    putenv('NATIVEPHP_STORAGE_PATH='.$this->scratch);
});

afterEach(function (): void {
    putenv(is_string($this->previousStorage) && $this->previousStorage !== ''
        ? 'NATIVEPHP_STORAGE_PATH='.$this->previousStorage
        : 'NATIVEPHP_STORAGE_PATH');
    exec('rm -rf '.escapeshellarg((string) $this->scratch));
});

it('narrows a log file an earlier install rotated out at 0644', function (): void {
    $rotated = UserDataPathService::logsDirectory().DIRECTORY_SEPARATOR.'laravel-2026-01-01.log';
    file_put_contents($rotated, "ING_NL91ABNA0417164300_2026.csv\n");
    chmod($rotated, 0o644);

    (new EnsurePrivateLogFiles(new OwnerOnlyPath))->run();

    expect(privateLogMode($rotated))->toBe(SecretFileMode::FILE)
        ->and(privateLogMode(UserDataPathService::logsDirectory()))->toBe(SecretFileMode::DIRECTORY);
});

it('does not invent a log file that was never written', function (): void {
    (new EnsurePrivateLogFiles(new OwnerOnlyPath))->run();

    expect(glob(UserDataPathService::logsDirectory().DIRECTORY_SEPARATOR.'*.log'))->toBe([]);
});

it('creates the logs directory owner-only when the install has never logged', function (): void {
    exec('rm -rf '.escapeshellarg(UserDataPathService::logsDirectory()));

    (new EnsurePrivateLogFiles(new OwnerOnlyPath))->run();

    expect(is_dir(UserDataPathService::logsDirectory()))->toBeTrue()
        ->and(privateLogMode(UserDataPathService::logsDirectory()))->toBe(SecretFileMode::DIRECTORY);
});

it('hands the file channels a permission Laravel actually forwards', function (): void {
    foreach (['single', 'daily'] as $channel) {
        expect(config("logging.channels.{$channel}.permission"))->toBe(SecretFileMode::FILE);
    }
});

// The emergency logger is built by LogManager::createEmergencyLogger(), which
// passes StreamHandler a path and a level only. A `permission` key here would
// be read by nobody, so its absence is the decision and this pins it against a
// later reader who adds one and believes the file is now 0600.
it('leaves the emergency channel no permission key that nothing would read', function (): void {
    expect(config('logging.channels.emergency'))->not->toHaveKey('permission');

    $reflected = new ReflectionMethod(LogManager::class, 'createEmergencyLogger');
    $source = file((string) $reflected->getFileName()) ?: [];
    $body = implode('', array_slice(
        $source,
        $reflected->getStartLine() - 1,
        $reflected->getEndLine() - $reflected->getStartLine() + 1,
    ));

    expect($body)->not->toContain('permission');
});
