<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Session\Session;
use LogicException;

/**
 * @link ../../../../.docs/architecture/module-boundaries.md
 */
// Sessions are configured encrypted, so resolving one builds the encrypter and
// needs an application key. Depending on this instead of on Session keeps a
// constructor cheap enough for Artisan to build every command just to list
// them — which is what `key:generate` has to survive to mint that key.
final readonly class SessionFactory
{
    private function __construct(
        private ?Container $container,
        private ?Session $session,
    ) {}

    // The autowired production path: the container resolves this wherever a
    // SessionFactory is type-hinted, and the session is built on first call.
    public static function fromContainer(Container $container): self
    {
        return new self($container, null);
    }

    // For a caller that already holds the session it wants used — a unit test
    // building a Store by hand, or any wiring that is not going through the
    // container at all.
    public static function forSession(Session $session): self
    {
        return new self(null, $session);
    }

    public function __invoke(): Session
    {
        if ($this->session instanceof Session) {
            return $this->session;
        }

        if (! $this->container instanceof Container) {
            throw new LogicException('SessionFactory was built with neither a container nor a session.');
        }

        return $this->container->make(Session::class);
    }
}
