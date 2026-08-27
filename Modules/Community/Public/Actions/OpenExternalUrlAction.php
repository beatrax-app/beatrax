<?php

declare(strict_types=1);

namespace Modules\Community\Public\Actions;

use InvalidArgumentException;
use Modules\Community\Public\Support\LoggableUrl;
use Native\Desktop\Contracts\Shell;
use Psr\Log\LoggerInterface;

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
        // Scrubbed for the same reason the log line below is: this message
        // reaches a public Livewire property, and the query string carries the
        // user's own statement description.
        if (filter_var($url, FILTER_VALIDATE_URL) === false || ! str_starts_with($url, 'https://')) {
            throw new InvalidArgumentException(
                'OpenExternalUrlAction: URL must be a valid https:// URL, got: '.LoggableUrl::withoutQuery($url),
            );
        }

        // Lower-cased before the compare: parse_url does not fold case and the
        // list is matched strictly, so https://GITHUB.COM/... was refused as an
        // un-allow-listed host.
        $host = parse_url($url, PHP_URL_HOST);
        $host = is_string($host) ? strtolower($host) : null;
        if ($host === null || ! in_array($host, self::ALLOWED_HOSTS, true)) {
            throw new InvalidArgumentException(
                'OpenExternalUrlAction: host not allow-listed, got: '.($host ?? 'null'),
            );
        }

        $this->shell->openExternal($url);
        $this->logger->info('OpenExternalUrlAction: launched system browser.', ['url' => LoggableUrl::withoutQuery($url)]);
    }
}
