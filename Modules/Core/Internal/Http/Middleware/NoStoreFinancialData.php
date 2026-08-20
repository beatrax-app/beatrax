<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @link ../../../../../.docs/features/core/architecture.md
 */
final class NoStoreFinancialData
{
    // NativePHP injects its Livewire bridge as a static inline module on the
    // RequestHandled event — after this middleware runs, so it cannot carry
    // the nonce. It is allow-listed by the hash of its exact bytes instead,
    // recomputed from the shipped file so a package bump can never strand it.
    private const NATIVE_BRIDGE_JS = 'vendor/nativephp/desktop/resources/electron/electron-plugin/src/preload/livewire-dispatcher.js';

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

    // Static brand artefacts, and the only routes exempt from the no-store
    // rule: they carry no financial data, and overwriting their own week-long
    // policy made every lock screen and setup screen re-read a 91 KB PNG
    // through PHP, on a device with no web server in front of it.
    /** @var list<string> */
    private const PUBLIC_ARTEFACT_ROUTES = [
        'app.icon',
        'app.splash',
        'pwa.icon',
        'site.webmanifest',
    ];

    public function __construct(
        private readonly Vite $vite,
        private readonly Application $app,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Mint the per-request CSP nonce before the view renders so the inline
        // scripts, @vite, Livewire and Flux all stamp the same value; the
        // script-src below then admits exactly those and blocks injected code.
        $this->vite->useCspNonce();

        /** @var Response $response */
        $response = $next($request);

        if (! in_array($request->route()?->getName(), self::PUBLIC_ARTEFACT_ROUTES, true)) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, private');
            $response->headers->set('Pragma', 'no-cache');
        }

        foreach (self::SECURITY_HEADERS as $header => $value) {
            if (! $response->headers->has($header)) {
                $response->headers->set($header, $value);
            }
        }

        $policy = $this->contentSecurityPolicy();
        if ($policy !== null && ! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', $policy);
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

    // Null while the Vite dev server is hot: HMR needs the dev origin and
    // inline eval that a strict policy forbids, and the dev server is not a
    // shipped surface. Every built bundle — desktop, mobile, self-hosted —
    // gets the nonce policy, the XSS backstop for a missed Blade escape.
    private function contentSecurityPolicy(): ?string
    {
        if ($this->vite->isRunningHot()) {
            return null;
        }

        // 'unsafe-eval' is unavoidable: Livewire bundles Alpine, which compiles
        // its expressions through the Function constructor. The nonce is what
        // lets first-party inline scripts run while injected ones cannot, so
        // 'unsafe-inline' is deliberately absent (a nonce disables it anyway).
        $nonce = $this->vite->cspNonce();

        $scriptSrc = "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'";
        $bridgeHash = $this->nativeBridgeScriptHash();
        if ($bridgeHash !== null) {
            $scriptSrc .= " '{$bridgeHash}'";
        }

        return implode('; ', [
            "default-src 'self'",
            $scriptSrc,
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }

    // The bridge's inline text is `\n{file}\n` — the module wrapper strips its
    // heredoc indentation — so hashing those exact bytes gives the token the
    // browser computes. Null when the file is absent, since the bridge is then
    // never injected and no hash is needed.
    private function nativeBridgeScriptHash(): ?string
    {
        $path = $this->app->basePath(self::NATIVE_BRIDGE_JS);
        if (! is_file($path)) {
            return null;
        }

        $javascript = file_get_contents($path);
        if ($javascript === false) {
            return null;
        }

        return 'sha256-'.base64_encode(hash('sha256', "\n".$javascript."\n", true));
    }
}
