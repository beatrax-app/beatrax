<?php

declare(strict_types=1);

namespace Modules\Core\Public\Controllers;

use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;

final class HealthController
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    public function __invoke(): JsonResponse
    {
        $envVersion = getenv('NATIVEPHP_APP_VERSION');
        $appVersion = is_string($envVersion) && $envVersion !== ''
            ? $envVersion
            : 'dev';

        $rawSqliteVersion = $this->db->connection()->scalar('SELECT sqlite_version()');
        $sqliteVersion = is_string($rawSqliteVersion) ? $rawSqliteVersion : '';

        return new JsonResponse([
            'status' => 'ok',
            'app_version' => $appVersion,
            'php_version' => PHP_VERSION,
            'sqlite_version' => $sqliteVersion,
        ]);
    }
}
