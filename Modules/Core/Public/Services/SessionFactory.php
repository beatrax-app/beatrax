<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Session\Session;
use LogicException;

/**
 * @link ../../../../.docs/architecture/module-boundaries.md
 */
// Defers session resolution to the moment a session is actually needed.
//
// Sessions are configured encrypted, so resolving one builds the encrypter,
// which requires an application key. Artisan constructs every registered
// command just to list them, and a command that reaches a session-holding
// service therefore made `key:generate` require the key it exists to create.
//
// Depending on this instead of on Session keeps the constructor cheap: nothing
// is resolved until a request-scoped code path invokes it.
final class SessionFactory
{
    private function __construct(
        private readonly ?Container $container,
        private readonly ?Session $session,
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
