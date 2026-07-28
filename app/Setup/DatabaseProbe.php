<?php

declare(strict_types=1);

namespace App\Setup;

use PDO;

// Opens a connection to a server the operator has just described, purely to
// find out whether it answers and which server it is. Separate from the
// setup command so the command holds no PDO handle of its own, and not
// final so a test can stand in for the one thing here that needs a server.
class DatabaseProbe
{
    private const CONNECT_TIMEOUT_SECONDS = 5;

    // Reachability alone cannot tell an operator they reached the instance
    // they meant, so the answer is the server's own version string rather
    // than a bare yes.
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
