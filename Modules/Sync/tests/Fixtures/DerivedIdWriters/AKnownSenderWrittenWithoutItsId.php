<?php

declare(strict_types=1);

namespace Modules\Sync\Tests\Fixtures\DerivedIdWriters;

use Illuminate\Database\ConnectionInterface;

// The violator the guard beside this file is pointed at, kept under tests/ so
// the production walk cannot reach it: a probe the rule scans would make the
// rule pass by being fixed, which is the one way a guard stops proving
// anything. Nothing calls this, and nothing should.
final class AKnownSenderWrittenWithoutItsId
{
    public function write(ConnectionInterface $connection): void
    {
        $connection->table('known_senders')->insert([
            'user_id' => 1,
            'email_pattern' => 'probe@beatrax.local',
            'label' => 'Probe',
            'source' => 'user',
            'added_at' => '2026-09-11 00:00:00',
            'created_at' => '2026-09-11 00:00:00',
            'updated_at' => '2026-09-11 00:00:00',
        ]);
    }
}
