<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Single source of truth for the http://127.0.0.1:PORT/oauth/callback/{provider}
 * URI that the OAuth-client wizard prints for the user to paste into
 * Google Cloud Console / Azure Portal, and that the
 * OAuthConnectController + OAuthCallbackController both compute
 * server-side when issuing and consuming the consent dance.
 *
 * Both providers reject `https://*.test` redirect URIs (Google's
 * native-app spec requires the loopback IP shape; Microsoft Entra
 * rejects non-`localhost` HTTP redirects). The URI therefore is
 * always shaped `http://127.0.0.1:PORT/oauth/callback/{provider}`,
 * never the configured `.test` URL.
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
 * app.url because the redirect must always be HTTP on the loopback
 * IP; honouring https://beatrax.test verbatim would produce a URI
 * the provider rejects.
 */
final class LoopbackRedirectUri
{
    private const DEFAULT_PORT = 8000;

    public function __construct(private readonly ConfigRepository $config) {}

    public function forProvider(string $provider): string
    {
        return 'http://127.0.0.1:'.$this->resolvePort().'/oauth/callback/'.$provider;
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
