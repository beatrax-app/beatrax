<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Livewire;

use Composer\InstalledVersions;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\DatabaseManager;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\DevMode\Internal\System\ConfigFlattener;
use Throwable;

/**
 * @link ../../../../../.docs/features/dev-mode/architecture.md
 */
#[Layout('dev::layouts.dev-shell')]
final class SystemSnapshotPage extends Component
{
    public function render(
        ViewFactory $views,
        ConfigRepository $config,
        Application $app,
        DatabaseManager $db,
        ConfigFlattener $flattener,
    ): View {
        $php = $this->phpFacts();
        $sqlite = $this->sqliteFacts($db);
        $laravel = $this->laravelFacts($app, $config);
        $paths = $this->pathFacts();
        $env = $flattener->redactSecretSuffixes($this->envFacts());
        $runtime = $this->runtimeFacts();
        $configAll = $config->all();
        $effectiveConfig = $flattener->redactSecretSuffixes($flattener->flatten($configAll));

        return $views->make('dev::livewire.system-snapshot-page', [
            'php' => $php,
            'sqlite' => $sqlite,
            'laravel' => $laravel,
            'paths' => $paths,
            'env' => $env,
            'runtime' => $runtime,
            'effectiveConfig' => $effectiveConfig,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function phpFacts(): array
    {
        $iniPath = php_ini_loaded_file();

        return [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'ini_path' => is_string($iniPath) ? $iniPath : '(none)',
            'extensions' => get_loaded_extensions(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function sqliteFacts(DatabaseManager $db): array
    {
        $pragmas = ['journal_mode', 'synchronous', 'cache_size', 'page_size'];
        $values = [];
        foreach ($pragmas as $pragma) {
            $values[$pragma] = $this->readPragma($db, $pragma);
        }
        $dbFile = UserDataPathService::databaseFile();
        $size = null;
        try {
            if (is_file($dbFile)) {
                $stat = @stat($dbFile);
                if ($stat !== false) {
                    $size = $stat['size'];
                }
            }
        } catch (Throwable) {
            $size = null;
        }

        return [
            'pragmas' => $values,
            'file' => $dbFile,
            'file_size' => $size,
        ];
    }

    private function readPragma(DatabaseManager $db, string $pragma): string
    {
        try {
            $rows = $db->connection()->select('PRAGMA '.$pragma);
            if ($rows === []) {
                return '(unset)';
            }
            $first = $rows[0];
            if (! is_object($first)) {
                return '(empty)';
            }
            $vars = get_object_vars($first);
            foreach ($vars as $value) {
                return (string) (is_scalar($value) ? $value : json_encode($value));
            }
        } catch (Throwable $e) {
            return 'error: '.$e->getMessage();
        }

        return '(empty)';
    }

    /**
     * @return array<string, mixed>
     */
    private function laravelFacts(Application $app, ConfigRepository $config): array
    {
        return [
            'version' => $app->version(),
            'env' => $config->get('app.env'),
            'debug' => $config->get('app.debug'),
            'locale' => $config->get('app.locale'),
            'timezone' => $config->get('app.timezone'),
        ];
    }

    // Routes through UserDataPathService (never base_path()/storage_path()
    // directly) so the raw-path-helpers-outside-the-service-only arch
    // invariant stays clean.
    /**
     * @return array<string, string>
     */
    private function pathFacts(): array
    {
        return [
            'base' => UserDataPathService::projectPath(),
            'app' => UserDataPathService::projectPath('app'),
            'storage' => UserDataPathService::storageBase(),
            'config' => UserDataPathService::projectPath('config'),
            'cache' => UserDataPathService::frameworkPath('cache'),
            'database' => UserDataPathService::databaseFile(),
        ];
    }

    // Other env vars are intentionally excluded — the snapshot is the
    // project's operational facts, not a full environment dump.
    /**
     * @return array<string, string>
     */
    private function envFacts(): array
    {
        $env = [];
        $raw = getenv();
        foreach ($raw as $key => $value) {
            if (
                str_starts_with($key, 'BEATRAX_')
                || str_starts_with($key, 'NATIVEPHP_')
                || $key === 'APP_KEY'
                || $key === 'APP_ENV'
                || $key === 'APP_DEBUG'
            ) {
                $env[$key] = $value;
            }
        }

        return $env;
    }

    /**
     * @return array<string, mixed>
     */
    private function runtimeFacts(): array
    {
        $native = null;
        if (class_exists(InstalledVersions::class) && InstalledVersions::isInstalled('nativephp/laravel')) {
            try {
                $native = InstalledVersions::getPrettyVersion('nativephp/laravel');
            } catch (Throwable) {
                $native = null;
            }
        }

        return [
            'nativephp' => $native ?? '(not installed)',
            'host_os' => php_uname(),
        ];
    }
}
