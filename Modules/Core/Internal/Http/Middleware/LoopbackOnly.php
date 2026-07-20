<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @link ../../../../../.docs/features/core/architecture.md
 */
final class LoopbackOnly
{
    public function handle(Request $request, Closure $next): Response
    {
        $serverAddr = $request->server('SERVER_ADDR');

        if (is_string($serverAddr) && ! self::isLoopback($serverAddr)) {
            throw new NotFoundHttpException;
        }

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    private static function isLoopback(string $addr): bool
    {
        $binary = @inet_pton($addr);
        if ($binary === false) {
            return false;
        }

        $length = strlen($binary);

        if ($length === 4) {
            // 127.0.0.0/8 covers every IPv4 loopback address — checking only
            // the first byte (0x7f) is sufficient for the full /8 range.
            return $binary[0] === "\x7f";
        }

        if ($length === 16) {
            // ::1 is fifteen NUL bytes followed by 0x01 — compare the exact
            // 16-byte binary form rather than parsing text representations.
            if ($binary === str_repeat("\x00", 15)."\x01") {
                return true;
            }

            // ::ffff:0:0/96 IPv4-mapped IPv6 — 10 NUL bytes, 0xff 0xff, then
            // a 4-byte IPv4 payload. Match the same 127.0.0.0/8 rule on the
            // trailing four octets.
            $v4MappedPrefix = str_repeat("\x00", 10)."\xff\xff";
            if (str_starts_with($binary, $v4MappedPrefix)) {
                return $binary[12] === "\x7f";
            }
        }

        return false;
    }
}
