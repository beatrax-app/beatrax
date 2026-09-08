<?php

declare(strict_types=1);

namespace Modules\Community\Internal\Shell;

use Modules\Community\Internal\Support\LoggableUrl;
use Modules\Core\Public\Contracts\ExternalUrlOpener;
use Psr\Log\LoggerInterface;

// What a runtime with no shell of its own answers: a browser tab, a test, a
// CI machine. It names no platform package, which is the whole point — the
// fallback for "no platform here" must not be typed by one.
final readonly class UnopenedUrl implements ExternalUrlOpener
{
    public function __construct(private LoggerInterface $logger) {}

    // Scrubbed of its query the same way every other line about a URL is: a
    // corpus link carries the user's own statement description.
    public function open(string $url): bool
    {
        $this->logger->info('UnopenedUrl: no shell here to open a URL with.', [
            'url' => LoggableUrl::withoutQuery($url),
        ]);

        return false;
    }
}
