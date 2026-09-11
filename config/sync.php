<?php

declare(strict_types=1);

use Modules\Sync\Public\Services\SyncPorts;

return [

    // What sync:serve binds and peers advertise over mDNS. The NativePHP
    // ChildProcess host reads it to start the listener on the same port.
    'port' => (int) env('SYNC_PORT', SyncPorts::DEFAULT_PORT),

    // What relay:serve binds. The desktop bundle DOES start it on its own, from
    // NativeAppServiceProvider before the window opens, whenever a local device
    // exists; a self-hoster runs or supervises `php artisan relay:serve`. Both
    // listeners bind every interface, which is what a peer needs to reach them.
    'relay_port' => (int) env('SYNC_RELAY_PORT', SyncPorts::DEFAULT_RELAY_PORT),

];
