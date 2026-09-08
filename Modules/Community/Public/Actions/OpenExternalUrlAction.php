<?php

declare(strict_types=1);

namespace Modules\Community\Public\Actions;

use InvalidArgumentException;
use Modules\Community\Internal\Support\LoggableUrl;
use Modules\Core\Public\Contracts\ExternalUrlOpener;
use Modules\Core\Public\Enums\ExternalUrlRefusal;
use Modules\Core\Public\Support\ExternalUrl;
use Psr\Log\LoggerInterface;

final readonly class OpenExternalUrlAction
{
    // The only hosts this application ever asks the operating system to open.
    // A rendered corpus link has no finite list and passes none; it is judged
    // by the same gate without one.
    /** @var list<string> */
    private const array ALLOWED_HOSTS = ['github.com'];

    public function __construct(
        private ExternalUrlOpener $opener,
        private LoggerInterface $logger,
    ) {}

    // Public so a template that offers the same address as a plain anchor can
    // ask the same question without opening anything. The anchor is what the
    // browser follows when JavaScript is off or the reader middle-clicks, and
    // it used to be the one path around this list.
    public static function refusalFor(string $url): ?ExternalUrlRefusal
    {
        return ExternalUrl::refusalFor($url, self::ALLOWED_HOSTS);
    }

    // Answers whether the URL was taken, because on three of the four runtimes
    // it may not have been and the caller's next line is what the reader is
    // told happened. A refused address still throws: that is a caller passing
    // something this list never allowed, not a platform declining.
    public function __invoke(string $url): bool
    {
        $refusal = self::refusalFor($url);

        // Scrubbed for the same reason the log line below is: this message
        // reaches a public Livewire property, and the query string carries the
        // user's own statement description.
        if ($refusal !== null) {
            throw new InvalidArgumentException(
                'OpenExternalUrlAction: '.$refusal->value.', got: '.LoggableUrl::withoutQuery($url),
            );
        }

        $opened = $this->opener->open($url);

        $this->logger->info(
            $opened
                ? 'OpenExternalUrlAction: the platform took the URL.'
                : 'OpenExternalUrlAction: the platform did not take the URL.',
            ['url' => LoggableUrl::withoutQuery($url)],
        );

        return $opened;
    }
}
