<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Log\LogManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\DevMode\Internal\Logging\ActiveLogFile;

function logViewerUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'fixture-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
        'is_developer' => true,
    ]);
}

/**
 * @param  list<string>  $stack
 */
function logViewerChannel(string $default, array $stack = []): string
{
    // Without the override the sandbox is the developer's own storage/logs and
    // the writes below land in the log they are actually reading.
    $sandbox = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-logviewer-'.bin2hex(random_bytes(6));
    putenv('NATIVEPHP_STORAGE_PATH='.$sandbox);

    $dir = UserDataPathService::logsDirectory();
    @mkdir($dir, 0755, true);

    /** @var ConfigRepository $config */
    $config = app(ConfigRepository::class);
    $config->set('logging.default', $default);
    $config->set('logging.channels.stack.channels', $stack);
    $config->set('logging.channels.single.path', UserDataPathService::logsFile());
    $config->set('logging.channels.daily.path', UserDataPathService::logsFile());

    return $dir;
}

function logViewerWrite(string $message): void
{
    // A fresh manager, because the container's `log` singleton has already
    // built its channels against the config this test just replaced.
    (new LogManager(app()))->error($message);
}

afterEach(function (): void {
    putenv('NATIVEPHP_STORAGE_PATH');
});

$shapes = [
    'single' => ['single', []],
    'daily' => ['daily', []],
    'stack of single' => ['stack', ['single']],
    'stack of daily' => ['stack', ['daily']],
];

foreach ($shapes as $label => [$default, $stack]) {
    it("opens the file the {$label} channel just wrote to", function () use ($default, $stack): void {
        $dir = logViewerChannel($default, $stack);
        logViewerWrite('canary-from-the-configured-channel');

        $written = array_values(array_filter(
            glob($dir.DIRECTORY_SEPARATOR.'*.log') ?: [],
            'is_file',
        ));

        expect($written)->toHaveCount(1);
        expect(app(ActiveLogFile::class)->path())->toBe($written[0]);
    });

    it("reports the {$label} channel's lines on the logs stats endpoint", function () use ($default, $stack): void {
        $user = logViewerUser('log-viewer-stats-'.bin2hex(random_bytes(4)));
        logViewerChannel($default, $stack);
        logViewerWrite('canary-from-the-configured-channel');

        $response = $this->actingAs($user)->getJson('/dev/logs/stats');

        $response->assertOk();
        expect($response->json('today.exists'))->toBeTrue();
        expect($response->json('today.perSeverity.ERROR'))->toBe(1);
        expect($response->json('allFiles.count'))->toBe(1);
    });

    it("streams the {$label} channel's lines to the logs poll endpoint", function () use ($default, $stack): void {
        $user = logViewerUser('log-viewer-poll-'.bin2hex(random_bytes(4)));
        logViewerChannel($default, $stack);
        logViewerWrite('canary-from-the-configured-channel');

        $response = $this->actingAs($user)->getJson('/dev/logs/poll?since=0');

        $response->assertOk();
        expect($response->json('chunk'))->toContain('canary-from-the-configured-channel');
    });
}

it('writes every file-logging channel to the one path the viewer resolves', function (): void {
    // Re-read rather than config(): the harness moves the storage root AFTER
    // the container has loaded config, so the booted values name the
    // developer's own tree while UserDataPathService now names the sandbox.
    /** @var array<string, array<string, mixed>> $channels */
    $channels = (require UserDataPathService::projectPath('config/logging.php'))['channels'];

    $fileChannels = array_filter(
        $channels,
        static fn (array $channel): bool => in_array($channel['driver'] ?? null, ['single', 'daily'], true),
    );

    expect($fileChannels)->not->toBeEmpty();

    foreach ($fileChannels as $name => $channel) {
        expect($channel['path'])->toBe(UserDataPathService::logsFile(), $name);
    }
});
