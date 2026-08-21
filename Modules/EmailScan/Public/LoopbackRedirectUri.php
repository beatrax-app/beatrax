<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public;

use Illuminate\Contracts\Config\Repository as ConfigRepository;

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

        return match (true) {
            is_int($override) && $override > 0 => $override,
            is_string($override) && $override !== '' && ctype_digit($override) => (int) $override,
            default => $this->portFromAppUrl(),
        };
    }

    private function portFromAppUrl(): int
    {
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
