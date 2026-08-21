<?php

declare(strict_types=1);

namespace Modules\Core\Public\Enums;

// The server APIs this app makes a decision about, and the one thing it decides:
// whether a request could have crossed a network to arrive. Anything not named
// here is treated as socket-serving, which is why callers go through
// tryFrom() and read a null as "not one of ours".
enum PhpSapi: string
{
    // PHP's own built-in server — the desktop shell's `artisan serve`. It binds
    // loopback yet publishes no SERVER_ADDR.
    case CliServer = 'cli-server';

    // The mobile shell calling into PHP in-process via php_embed. There is no
    // listening socket at all, so there is no bind address to publish either.
    case Embed = 'embed';

    // Neither of these can receive a request that crossed a network, so a gate
    // on the interface a connection arrived over has nothing to judge here.
    public function servesInProcess(): bool
    {
        return match ($this) {
            self::CliServer, self::Embed => true,
        };
    }
}
