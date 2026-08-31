<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class LoopbackOnly
{
    // The SAPI is a parameter so the mobile path can be exercised: PHP_SAPI is
    // a compile-time constant, and a gate that cannot be tested off its own
    // SAPI is how this one shipped able to 404 an entire platform.
    public function __construct(
        private Application $app,
        private string $sapi = \PHP_SAPI,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $serverAddr = $request->server('SERVER_ADDR');

        if (is_string($serverAddr)) {
            if (! self::isLoopback($serverAddr)) {
                throw new NotFoundHttpException;
            }
        } elseif (! $this->app->runningInConsole() && ! $this->servesLocally($request)) {
            // A real HTTP SAPI (php-fpm, mod_php) with no SERVER_ADDR never
            // advertised its bind address; fail closed rather than assume it
            // was loopback.
            throw new NotFoundHttpException;
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    // A SAPI that publishes no bind address is not automatically local. The
    // built-in server publishes none yet `--host=0.0.0.0` binds every
    // interface, so it has to answer for the peer it is actually talking to.
    private function servesLocally(Request $request): bool
    {
        $sapi = PhpSapi::tryFrom($this->sapi);

        if ($sapi === null) {
            return false;
        }

        if ($sapi->cannotBeReachedOffDevice()) {
            return true;
        }

        $remote = $request->server('REMOTE_ADDR');

        return is_string($remote) && self::isLoopback($remote);
    }

    // Anything inet_pton cannot read is not an address, and anything that is
    // neither 4 nor 16 bytes is not one either — both are refused rather than
    // guessed at, since this gate decides whether a request reaches the app.
    private static function isLoopback(string $addr): bool
    {
        $binary = @inet_pton($addr);
        if ($binary === false) {
            return false;
        }

        return match (strlen($binary)) {
            4 => self::isLoopbackV4($binary),
            16 => self::isLoopbackV6($binary),
            default => false,
        };
    }

    // 127.0.0.0/8 covers every IPv4 loopback address, so the first byte is
    // the whole test for the full /8 range.
    private static function isLoopbackV4(string $binary): bool
    {
        return $binary[0] === "\x7f";
    }

    private static function isLoopbackV6(string $binary): bool
    {
        // ::1 is fifteen NUL bytes followed by 0x01 — compared in binary
        // rather than against one of the many text spellings of it.
        if ($binary === str_repeat("\x00", 15)."\x01") {
            return true;
        }

        // ::ffff:0:0/96 IPv4-mapped — ten NUL bytes, 0xff 0xff, then a 4-byte
        // IPv4 payload, which gets the same rule a native V4 address gets
        // rather than a second copy of it.
        $v4MappedPrefix = str_repeat("\x00", 10)."\xff\xff";

        return str_starts_with($binary, $v4MappedPrefix)
            && self::isLoopbackV4(substr($binary, 12));
    }
}
