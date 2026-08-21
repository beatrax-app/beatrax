<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Middleware;

// The one thing this decides: whether a request could have crossed a network to
// arrive. A SAPI not named here is treated as socket-serving, which is why
// callers go through tryFrom() and read a null as "not one of ours".
enum PhpSapi: string
{
    // Binds loopback yet publishes no SERVER_ADDR.
    case CliServer = 'cli-server';

    // The mobile shell calling into PHP in-process: no listening socket at all,
    // so no bind address to publish either.
    case Embed = 'embed';

    // Neither can receive a request that crossed a network, so a gate on the
    // interface a connection arrived over has nothing to judge.
    public function servesInProcess(): bool
    {
        return match ($this) {
            self::CliServer, self::Embed => true,
        };
    }
}
