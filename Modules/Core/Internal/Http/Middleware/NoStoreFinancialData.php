<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Http\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Vite;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class NoStoreFinancialData
{
    // NativePHP injects its Livewire bridge as a static inline module on the
    // RequestHandled event — after this middleware runs, so it cannot carry
    // the nonce. It is allow-listed by the hash of its exact bytes instead,
    // recomputed from the shipped file so a package bump can never strand it.
    private const string NATIVE_BRIDGE_JS = 'vendor/nativephp/desktop/resources/electron/electron-plugin/src/preload/livewire-dispatcher.js';

    // The two CSP source tokens this policy repeats. Nine directives are built
    // from them, and a literal written nine times is a policy nine edits wide.
    private const string ORIGIN = "'self'";

    private const string NOTHING = "'none'";

    // The one directive a route may set for itself: whether this page is meant
    // to be framed is a question only the route knows. Every other directive is
    // an app-wide property no single route is in a position to relax.
    /** @var list<string> */
    private const array OVERRIDABLE_DIRECTIVES = ['frame-ancestors'];

    // The app renders text it did not write — counterparty names, payment
    // references, receipt bodies from bank exports and mailboxes — so these
    // are what stands between a missed escape and a working attack.
    /** @var array<string, string> */
    private const array SECURITY_HEADERS = [
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
    private const array PUBLIC_ARTEFACT_ROUTES = [
        'app.icon',
        'app.splash',
        'pwa.icon',
        'site.webmanifest',
    ];

    public function __construct(
        private Vite $vite,
        private Application $app,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Minted before the view renders so inline scripts, @vite, Livewire and
        // Flux all stamp the same value the script-src below admits.
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

        $this->writeContentSecurityPolicy($response);

        // Browsers disagree on whether a CSP frame-ancestors or X-Frame-Options
        // wins when both are present, and the merge above guarantees a policy
        // that carries frame-ancestors, so the more expressive header is left
        // to stand alone.
        if ($response->headers->has('Content-Security-Policy')) {
            $response->headers->remove('X-Frame-Options');
        }

        return $response;
    }

    // The base is what a route EXTENDS. Every route middleware runs inside this
    // one, so deferring to a policy already on the response handed one route the
    // whole header: /dev/horizon shipped a frame rule and none of the other nine
    // directives, and no X-Frame-Options either, because a CSP was present.
    private function writeContentSecurityPolicy(Response $response): void
    {
        $directives = array_merge($this->baseDirectives(), $this->declaredOverrides($response));

        if ($directives === []) {
            $response->headers->remove('Content-Security-Policy');

            return;
        }

        $policy = [];

        foreach ($directives as $name => $value) {
            $policy[] = $name.' '.$value;
        }

        $response->headers->set('Content-Security-Policy', implode('; ', $policy));
    }

    // What an inner layer asked for, narrowed to OVERRIDABLE_DIRECTIVES. Merging
    // whatever it wrote would re-open the hole from the other side: a route could
    // then hand itself `script-src *`. Anything else is dropped, so the base
    // value stands rather than a spelling this parser did not follow.
    /**
     * @return array<string, string>
     */
    private function declaredOverrides(Response $response): array
    {
        $declared = $response->headers->get('Content-Security-Policy');

        if ($declared === null) {
            return [];
        }

        $overrides = [];

        foreach (explode(';', $declared) as $directive) {
            $directive = trim($directive);
            $nameLength = strcspn($directive, " \t");
            $name = strtolower(substr($directive, 0, $nameLength));
            $value = trim(substr($directive, $nameLength));

            // An empty source list is a parse error the browser answers by
            // ignoring the directive, which for frame-ancestors means framed by
            // anyone. The base value is the safe reading of "said nothing".
            if ($value !== '' && in_array($name, self::OVERRIDABLE_DIRECTIVES, true)) {
                $overrides[$name] = $value;
            }
        }

        return $overrides;
    }

    // Empty while the Vite dev server is hot: HMR needs the dev origin and inline
    // eval a strict policy forbids, and the dev server is not a shipped surface.
    // Every built bundle gets the nonce policy instead.
    /**
     * @return array<string, string>
     */
    private function baseDirectives(): array
    {
        if ($this->vite->isRunningHot()) {
            return [];
        }

        // 'unsafe-eval' is unavoidable: Livewire bundles Alpine, which compiles
        // its expressions through the Function constructor. The nonce is what
        // lets first-party inline scripts run while injected ones cannot, so
        // 'unsafe-inline' is deliberately absent (a nonce disables it anyway).
        $nonce = $this->vite->cspNonce();

        $scriptSrc = self::ORIGIN.sprintf(" 'nonce-%s' 'unsafe-eval'", $nonce);
        $bridgeHash = $this->nativeBridgeScriptHash();
        if ($bridgeHash !== null) {
            $scriptSrc .= sprintf(" '%s'", $bridgeHash);
        }

        return [
            'default-src' => self::ORIGIN,
            'script-src' => $scriptSrc,
            'style-src' => self::ORIGIN." 'unsafe-inline'",
            'img-src' => self::ORIGIN.' data:',
            'font-src' => self::ORIGIN.' data:',
            'connect-src' => self::ORIGIN,
            'object-src' => self::NOTHING,
            'base-uri' => self::ORIGIN,
            'form-action' => self::ORIGIN,
            'frame-ancestors' => self::NOTHING,
        ];
    }

    // The bridge's inline text is `\n{file}\n` — the module wrapper strips its
    // heredoc indentation — so hashing those exact bytes gives the token the
    // browser computes. Null when the file is absent; nothing is injected then.
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
