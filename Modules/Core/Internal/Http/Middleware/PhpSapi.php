<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Middleware;

// The SAPIs that serve without publishing a bind address. A SAPI not named here
// publishes one, which is why callers go through tryFrom() and read a null as
// "not one of ours".
enum PhpSapi: string
{
    // PHP's built-in server. It publishes no SERVER_ADDR but DOES bind a real
    // socket, and `--host=0.0.0.0` binds every interface — so unlike Embed it
    // can receive a request that crossed a network.
    case CliServer = 'cli-server';

    // The runtime the self-host recipe ships. It registers SERVER_NAME and
    // SERVER_PORT and no SERVER_ADDR at all, so the address gate had nothing to
    // read and refused the whole deployment shape — every route, every request,
    // from the first `docker compose up`.
    case FrankenPhp = 'frankenphp';

    // The mobile shell calling into PHP in-process: no listening socket at all,
    // so nothing can reach it from off the device.
    case Embed = 'embed';

    // True only where there is no socket to reach. Everything else has to prove
    // where the request came from.
    public function cannotBeReachedOffDevice(): bool
    {
        return match ($this) {
            self::Embed => true,
            self::CliServer, self::FrankenPhp => false,
        };
    }
}
