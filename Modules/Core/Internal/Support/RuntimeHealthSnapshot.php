<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Support;

use Illuminate\Database\DatabaseManager;

// The SQLite version is read through a live query rather than a constant: the
// probe exists to say the bundled engine answered, and a version string no
// connection was opened for would report health the caller does not have.
final readonly class RuntimeHealthSnapshot
{
    public function __construct(
        private DatabaseManager $db,
    ) {}

    /**
     * @return array{status: string, app_version: string, php_version: string, sqlite_version: string}
     */
    public function __invoke(): array
    {
        $envVersion = getenv('NATIVEPHP_APP_VERSION');
        $rawSqliteVersion = $this->db->connection()->scalar('SELECT sqlite_version()');

        return [
            'status' => 'ok',
            'app_version' => is_string($envVersion) && $envVersion !== '' ? $envVersion : 'dev',
            'php_version' => PHP_VERSION,
            'sqlite_version' => is_string($rawSqliteVersion) ? $rawSqliteVersion : '',
        ];
    }
}
