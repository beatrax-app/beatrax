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
        // The query string carries the suggest-mapping YAML body, i.e. the
        // user's own statement description. Encryption at rest exists to keep
        // that off the disk, so the log line records only which page opened.
        $this->logger->info('OpenExternalUrlAction: launched system browser.', ['url' => self::withoutQuery($url)]);
    }

    private static function withoutQuery(string $url): string
    {
        return substr($url, 0, strcspn($url, '?#'));
    }
}
