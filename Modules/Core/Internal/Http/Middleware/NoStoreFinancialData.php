<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @link ../../../../../.docs/features/core/architecture.md
 */
final class NoStoreFinancialData
{
    // Baseline headers on every `web` response. The app renders text it did
    // not write — counterparty names, payment references and receipt bodies
    // arrive from bank exports and mailboxes — so these are what stands
    // between a missed escape and a working attack.
    /** @var array<string, string> */
    private const SECURITY_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'no-referrer',
        'X-Permitted-Cross-Domain-Policies' => 'none',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
        $response->headers->set('Pragma', 'no-cache');

        foreach (self::SECURITY_HEADERS as $header => $value) {
            if (! $response->headers->has($header)) {
                $response->headers->set($header, $value);
            }
        }

        // The Dev Console embeds Horizon in a frame and allows it with
        // `frame-ancestors 'self'`. Browsers disagree on whether CSP or
        // X-Frame-Options wins when both are present, so the safe reading is
        // to let the more specific header stand alone and drop ours.
        if ($response->headers->has('Content-Security-Policy')) {
            $response->headers->remove('X-Frame-Options');
        }

        return $response;
    }
}
