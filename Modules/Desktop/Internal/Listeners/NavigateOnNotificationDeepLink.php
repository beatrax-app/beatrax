<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Listeners;

use Illuminate\Contracts\Routing\UrlGenerator;
use Modules\Desktop\Internal\Native\AppWindow;
use Modules\Desktop\Public\Events\NotificationDeepLink;
use Native\Desktop\Facades\Window;
use Psr\Log\LoggerInterface;

final readonly class NavigateOnNotificationDeepLink
{
    public function __construct(
        private UrlGenerator $urls,
        private LoggerInterface $logger,
    ) {}

    public function handle(NotificationDeepLink $event): void
    {
        $route = $event->screenRoute;
        $ours = $route !== '' && $this->addressesThisApplication($route);

        // Addressed by id, never by focus: the notification exists only because
        // the window was not focused when it fired, and it may since have been
        // hidden to the tray. open() on an id already in the shell's window
        // state shows and focuses it, which is what the click asked for.
        if ($ours) {
            Window::get(AppWindow::ID)->url($route);
        }

        if (! $ours && $route !== '') {
            $this->logger->warning('NavigateOnNotificationDeepLink: refused a deep link that does not address this application.', [
                'host' => self::hostOf($route),
            ]);
        }

        Window::open(AppWindow::ID);
    }

    // This call replaces the address of the application's own window, so a
    // route that is not the application's own is not a deep link — it is an
    // outside page taking that window over. A savings prompt used to stamp the
    // merchant's cancellation URL here, verbatim from the community corpus.
    private function addressesThisApplication(string $route): bool
    {
        $origin = rtrim($this->urls->to('/'), '/');

        return self::namesNoHost($route) || $route === $origin || str_starts_with($route, $origin.'/');
    }

    // A rooted path is this application by construction: it carries no host to
    // be wrong about. Two slashes, or a slash and a backslash, are the
    // exception — both are protocol-relative and resolve to whatever host
    // follows them, which is the one rooted shape that does leave the origin.
    private static function namesNoHost(string $route): bool
    {
        return str_starts_with($route, '/')
            && ! str_starts_with($route, '//')
            && ! str_starts_with($route, '/\\');
    }

    private static function hostOf(string $route): string
    {
        $host = parse_url($route, PHP_URL_HOST);

        return is_string($host) ? $host : 'none';
    }
}
