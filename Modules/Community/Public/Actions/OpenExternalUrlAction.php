<?php

declare(strict_types=1);

namespace Modules\Community\Public\Actions;

use InvalidArgumentException;
use Native\Desktop\Contracts\Shell;
use Psr\Log\LoggerInterface;

/**
 * Opens a URL in the system's default browser. Wraps the NativePHP
 * `Native\Desktop\Contracts\Shell::openExternal` contract behind a
 * single constructor-injected action so the rest of the application
 * calls a domain-shaped method rather than the NativePHP API directly.
 *
 * Defence-in-depth posture: every URL is validated through two gates
 * before it reaches the shell contract. The first gate rejects non-
 * HTTPS URLs (covers `http://`, `javascript:`, `file://`, and any
 * scheme that filter_var's URL validator accepts but is not a web
 * fetch). The second gate restricts the host to the allow-list — only
 * `github.com` is permitted in this phase because the sole outbound
 * surface 16.1 introduces is the GitHub Compare URL produced by
 * GitHubCompareUrlBuilder.
 *
 * When the bundle is not running inside the NativePHP runtime (Herd
 * dev mode, CI test runs), the container resolves Shell to the
 * Modules\Community\Internal\Shell\NoOpShell fallback, which logs the
 * would-be URL and does nothing else; feature tests bind a ShellFake
 * to assert intent without launching a real browser.
 */
final class OpenExternalUrlAction
{
    /** @var list<string> */
    private const ALLOWED_HOSTS = ['github.com'];

    public function __construct(
        private readonly Shell $shell,
        private readonly LoggerInterface $logger,
    ) {}

    public function __invoke(string $url): void
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! str_starts_with($url, 'https://')) {
            throw new InvalidArgumentException(
                'OpenExternalUrlAction: URL must be a valid https:// URL, got: '.$url,
            );
        }

        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || ! in_array($host, self::ALLOWED_HOSTS, true)) {
            throw new InvalidArgumentException(
                'OpenExternalUrlAction: host not allow-listed, got: '.(is_string($host) ? $host : 'null'),
            );
        }

        $this->shell->openExternal($url);
        $this->logger->info('OpenExternalUrlAction: launched system browser.', ['url' => $url]);
    }
}
