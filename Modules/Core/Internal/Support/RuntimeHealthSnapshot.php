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
        private NetworkBoundary $boundary,
    ) {}

    // The boundary state is a fixed word, never the interface list: an operator
    // needs to know the boundary is open without reading config, and once it IS
    // open this body is reachable from the network, where an inventory of the
    // other interfaces served would be a disclosure and not a diagnosis.
    /**
     * @return array{status: string, app_version: string, php_version: string, sqlite_version: string, network_boundary: string}
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
            'network_boundary' => $this->boundary->state()->value,
        ];
    }
}
