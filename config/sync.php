<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Sync WebSocket Listen Port
    |--------------------------------------------------------------------------
    |
    | The port the sync:serve artisan daemon binds to for incoming Noise/WebSocket
    | connections. Peers discover this port via mDNS (D-07). The NativePHP
    | ChildProcess host reads this value to start the listener on the correct port.
    |
    | Default: 51337
    |
    */
    'port' => (int) env('SYNC_PORT', 51337),

    /*
    |--------------------------------------------------------------------------
    | Relay HTTP Listen Port
    |--------------------------------------------------------------------------
    |
    | The port the relay:serve artisan daemon binds its ZK relay HTTP endpoint
    | (POST /relay/deliver, GET /relay/drain, DELETE /relay/drain/{id}).
    |
    | The relay is opt-in (D-01): it is NOT started automatically. Self-hosters
    | run `php artisan relay:serve` manually or via their own supervised process.
    |
    | Default: 51338
    |
    */
    'relay_port' => (int) env('SYNC_RELAY_PORT', 51338),

];
