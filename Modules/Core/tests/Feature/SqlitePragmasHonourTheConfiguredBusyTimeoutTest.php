<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;

/*
 * The desktop runs sync:serve, relay:serve, a queue worker and the app server
 * against one SQLite file. busy_timeout is what makes a blocked writer wait
 * for the lock instead of failing, and the listener that applies the rest of
 * the pragmas runs AFTER the connector has applied the configured value — so
 * a hardcoded number there silently overrode config/database.php.
 *
 * It did: the config asks for 30s, the listener imposed 5s, and relay:serve
 * dropped a phone's PAIR_RESPONDER_ACCEPT with "database is locked" while the
 * app server held a write. The pairing simply stopped, with no user-visible
 * reason on either device.
 */

it('leaves the connection at the busy timeout the configuration asks for', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $connection = $db->connection();

    expect($connection->getDriverName())->toBe('sqlite');

    $configured = $connection->getConfig('busy_timeout');

    if (! is_numeric($configured)) {
        expect((int) $connection->scalar('PRAGMA busy_timeout'))
            ->toBeGreaterThanOrEqual(30_000, 'an unconfigured connection fell back below the documented default');

        return;
    }

    expect((int) $connection->scalar('PRAGMA busy_timeout'))
        ->toBe((int) $configured, 'something lowered the busy timeout the configuration asked for');
});
