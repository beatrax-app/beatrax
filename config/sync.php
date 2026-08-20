<?php

declare(strict_types=1);

return [

    // What sync:serve binds for incoming Noise/WebSocket connections, and what
    // peers advertise over mDNS. The NativePHP ChildProcess host reads it to
    // start the listener on the same port.
    'port' => (int) env('SYNC_PORT', 51337),

    // What relay:serve binds for the zero-knowledge relay endpoints. The relay
    // is opt-in and never starts automatically: a self-hoster runs
    // `php artisan relay:serve` themselves, or supervises it.
    'relay_port' => (int) env('SYNC_RELAY_PORT', 51338),

];
