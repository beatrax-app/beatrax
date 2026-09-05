<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Modules\Core\Public\Support\PatternScan;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

// Re-runnable: existing .env values become the prompt defaults, so a
// second run edits rather than overwrites.
final class SetupCommand extends Command
{
    protected $signature = 'beatrax:setup';

    protected $description = 'Interactively configure this environment (.env + database) for a server deployment.';

    public function handle(Repository $config): int
    {
        intro('Beatrax server setup');

        $this->ensureEnvFileExists();
        $this->ensureAppKey($config);

        $appUrl = text(
            label: 'Application URL',
            placeholder: 'https://finance.example.com',
            default: $this->configString($config, 'app.url', 'http://localhost:8000'),
            required: true,
        );

        $appEnv = (string) select(
            label: 'Environment',
            options: ['production' => 'production (server)', 'local' => 'local (development)'],
            default: $this->laravel->environment() === 'local' ? 'local' : 'production',
        );

        // Written rather than asked for: SQLite is the only engine this
        // schema runs on, in every deployment shape, and writing it normalises
        // a .env somebody pointed at a server database by hand.
        $env = [
            'APP_ENV' => $appEnv,
            'APP_URL' => $appUrl,
            'APP_DEBUG' => $appEnv === 'production' ? 'false' : 'true',
            'DB_CONNECTION' => 'sqlite',
        ];

        $this->writeEnvValues($env);
        note('Wrote '.implode(', ', array_keys($env)).' to .env');

        if (confirm(label: 'Run database migrations and create your user now (beatrax:install)?', default: true)) {
            // A fresh process: .env was read once at boot, so an in-process
            // call would migrate against the old connection, not the new one.
            note('Running beatrax:install in a fresh process so it reads the new .env…');
            $exitCode = 0;
            passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg($this->laravel->basePath('artisan')).' beatrax:install', $exitCode);
            if ($exitCode !== 0) {
                $this->components->error('beatrax:install did not complete; resolve the error above and re-run `php artisan beatrax:install`.');

                return self::FAILURE;
            }
        } else {
            note('Skipped. Run `php artisan beatrax:install` when the database is ready.');
        }

        $this->printNextSteps();
        outro('Setup complete.');

        return self::SUCCESS;
    }

    private function ensureEnvFileExists(): void
    {
        $env = $this->laravel->basePath('.env');
        if (is_file($env)) {
            return;
        }

        $example = $this->laravel->basePath('.env.example');
        if (is_file($example)) {
            copy($example, $env);
            note('Created .env from .env.example');
        } else {
            file_put_contents($env, '');
            note('Created an empty .env');
        }
    }

    private function ensureAppKey(Repository $config): void
    {
        if ($this->configString($config, 'app.key', '') !== '') {
            return;
        }

        $this->callSilently('key:generate', ['--force' => true]);
        note('Generated an application key (APP_KEY)');
    }

    /**
     * @param  array<string, string>  $values
     */
    private function writeEnvValues(array $values): void
    {
        $path = $this->laravel->basePath('.env');
        $contents = is_file($path) ? (string) file_get_contents($path) : '';
        $lines = $contents === '' ? [] : explode("\n", rtrim(str_replace("\r\n", "\n", $contents), "\n"));

        foreach ($values as $key => $value) {
            // Array assignment, not preg_replace, whose replacement would read
            // `$1` in a password as a backreference. A commented occurrence
            // matches too, so a `# DB_HOST=` line is set, not duplicated.
            $line = $key.'='.$this->encodeEnvValue($value);
            $pattern = '/^#?\s*'.preg_quote($key, '/').'=/';
            $replaced = false;

            foreach ($lines as $i => $existing) {
                if (preg_match($pattern, $existing) === 1) {
                    $lines[$i] = $line;
                    $replaced = true;
                    break;
                }
            }

            if (! $replaced) {
                $lines[] = $line;
            }
        }

        file_put_contents($path, implode("\n", $lines)."\n");
    }

    private function encodeEnvValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (! PatternScan::matches('/[\s#"\'$\\\\]/', $value)) {
            return $value;
        }

        // Escaped backslash, then quote, then `$`, so DotEnv reads back the
        // exact value and a password like `a${b}` is not interpolated.
        $escaped = str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value);

        return '"'.$escaped.'"';
    }

    private function configString(Repository $config, string $key, string $default): string
    {
        $value = $config->get($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }

    private function printNextSteps(): void
    {
        // The backup line used to appear only for a server database, which had
        // it backwards: the SQLite file IS the whole ledger, and nothing prunes
        // it, so it is the one thing on this list nobody can recreate.
        note("Next steps:\n - ".implode("\n - ", [
            'Back up the SQLite file regularly — full history is retained and never pruned.',
            'Serve the app behind a web server (nginx/Caddy → php-fpm) or run `php artisan serve` for a quick start.',
            'Run the queue worker:   php artisan queue:work --tries=3',
            'Run the scheduler:      php artisan schedule:work   (or a cron entry calling schedule:run every minute)',
        ]));
    }
}
