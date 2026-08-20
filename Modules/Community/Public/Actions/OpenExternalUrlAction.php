<?php

declare(strict_types=1);

namespace Modules\Community\Public\Actions;

use InvalidArgumentException;
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
