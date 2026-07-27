<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Session\Session;

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
    public function __construct(private readonly Container $container) {}

    public function __invoke(): Session
    {
        return $this->container->make(Session::class);
    }
}
