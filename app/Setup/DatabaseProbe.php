<?php

declare(strict_types=1);

namespace App\Setup;

use PDO;

// Split out of the setup command so that command holds no PDO handle, and
// left non-final so a test can substitute the one part needing a server.
class DatabaseProbe
{
    private const int CONNECT_TIMEOUT_SECONDS = 5;

    // Returns the server's own version string, not a bare yes: reachability
    // alone cannot tell an operator they reached the instance they meant.
    /**
     * @throws \PDOException when the server is absent, refuses the
     *                       credentials, or does not answer in time
     */
    public function serverVersion(string $dsn, string $username, string $password): string
    {
        $connection = new PDO($dsn, $username, $password, [
            PDO::ATTR_TIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $version = $connection->getAttribute(PDO::ATTR_SERVER_VERSION);

        return is_string($version) ? $version : '';
    }
}
