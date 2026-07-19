<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Single source of truth for the http(s)://127.0.0.1:PORT/oauth/callback/{provider}
 * URI that the OAuth-client wizard prints for the user to paste into
 * Google Cloud Console / Azure Portal, and that the
 * OAuthConnectController + OAuthCallbackController both compute
 * server-side when issuing and consuming the consent dance.
 *
 * Promoted from `Modules\EmailScan\Internal` to `Modules\EmailScan\Public`
 * in Phase 19 (19-05, RESEARCH.md Open Question 3): the port-resolution +
 * URI-shaping logic is provider-agnostic (already keyed on a bare
 * `{provider}` string), so `Modules\OpenBanking`'s consent dance
 * (`OpenBankingConnectController` / `OpenBankingCallbackController`)
 * consumes it as a sanctioned cross-module Public dependency rather than
 * duplicating the port-resolution chain. EmailScan's own gmail/microsoft
 * flows are unaffected — they keep calling `forProvider()` with the
 * default `$scheme` ('http').
 *
 * Gate 0/A2 pivot (19-05): Enable Banking's Control Panel requires an
 * HTTPS-only registered redirect URI, even for the loopback IP — unlike
 * Google/Microsoft, it does NOT extend the RFC 8252 native-client
 * exception to plain HTTP on `127.0.0.1`. OpenBanking therefore calls
 * `forProvider('open-banking', scheme: 'https')`; the caller is
 * responsible for actually terminating TLS on that loopback listener
 * (a self-signed local certificate) — tracked as deferred follow-up
 * infrastructure work (see 19-05-SUMMARY.md), since the local dev/prod
 * web server this project standardizes on does not itself terminate TLS.
 *
 * Google/Microsoft both reject `https://*.test` redirect URIs (their
 * native-app spec requires the loopback IP shape); Enable Banking
 * additionally rejects the bare-HTTP loopback exception those two
 * providers DO allow. The URI is therefore always shaped
 * `{scheme}://127.0.0.1:PORT/oauth/callback/{provider}`, never the
 * configured `.test` URL.
 *
 * Port resolution order:
 *
 *  1. `email-scan.oauth_loopback_port` config value if set (lets the
 *     user override via env var `OAUTH_LOOPBACK_PORT=...` for `.test` /
 *     custom-port setups where `app.url` does NOT carry the literal
 *     port the listener binds — for `app.url=https://beatrax.test`
 *     in local dev, the listener is on 443/80, but the OAuth redirect
 *     has to land on a separate `php artisan serve --port=8000`).
 *  2. `parse_url(app.url, PHP_URL_PORT)` if the host parses as
 *     `127.0.0.1` or `localhost` (the user is running
 *     `php artisan serve` directly on the loopback) AND the port is
 *     present and positive.
 *  3. Final fallback: 8000 — the project-wide convention.
 *
 * The fallback chain deliberately ignores the scheme + host from
 * app.url because the redirect must always land on the loopback IP;
 * honouring https://beatrax.test verbatim would produce a URI the
 * gmail/microsoft providers reject.
 */
final class LoopbackRedirectUri
{
    private const DEFAULT_PORT = 8000;

    public function __construct(private readonly ConfigRepository $config) {}

    public function forProvider(string $provider, string $scheme = 'http'): string
    {
        return $scheme.'://127.0.0.1:'.$this->resolvePort().'/oauth/callback/'.$provider;
    }

    private function resolvePort(): int
    {
        $override = $this->config->get('email-scan.oauth_loopback_port');
        if (is_int($override) && $override > 0) {
            return $override;
        }
        if (is_string($override) && $override !== '' && ctype_digit($override)) {
            return (int) $override;
        }

        $appUrl = $this->config->get('app.url');
        if (is_string($appUrl) && $appUrl !== '') {
            $host = parse_url($appUrl, PHP_URL_HOST);
            $port = parse_url($appUrl, PHP_URL_PORT);
            if (
                is_string($host)
                && ($host === '127.0.0.1' || $host === 'localhost')
                && is_int($port)
                && $port > 0
            ) {
                return $port;
            }
        }

        return self::DEFAULT_PORT;
    }
}
