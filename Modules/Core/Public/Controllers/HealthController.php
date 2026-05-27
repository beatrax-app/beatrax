<?php

declare(strict_types=1);

namespace Modules\Core\Public\Controllers;

use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;

/**
 * Auth-free liveness endpoint with a deterministic response shape.
 *
 * The endpoint returns exactly four keys — `status`, `app_version`,
 * `php_version`, `sqlite_version` — in a flat JSON object with no
 * timestamp or other variable fields. The shape is deterministic so an
 * external probe can assert both liveness and the running bundle's
 * reported version with a single equality check, without first
 * normalising volatile fields.
 *
 * `app_version` resolves through the `NATIVEPHP_APP_VERSION` environment
 * variable; when the variable is unset the endpoint reports the literal
 * string `dev`. This mirrors the bundled-app version-selection rule:
 * the env var is the only path that produces a real version string,
 * the absence of which signals a build that did not come off the
 * release pipeline.
 *
 * `php_version` reads from the `PHP_VERSION` constant — the running
 * interpreter, not any configured value.
 *
 * `sqlite_version` runs `SELECT sqlite_version()` against the default
 * database connection, reporting the linked SQLite library version that
 * the bundle is actually using at runtime.
 *
 * The controller takes its database access through constructor DI; no
 * facade calls and no global helpers are used.
 */
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
