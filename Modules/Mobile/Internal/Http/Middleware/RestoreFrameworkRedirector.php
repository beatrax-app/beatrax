<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;
use Illuminate\Session\Store;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

// Livewire swaps the container's redirector while a component is booted and
// swaps it back when the component dehydrates. Its own `to()` returns `$this`
// rather than a response, which is only safe inside a component — Livewire
// reads the target off it there.

// A request that dies between those two points leaves that redirector bound.
// Under PHP-FPM the process ends and nothing carries over; this runtime is
// persistent, so the container survives and every later request whose
// middleware redirects gets an object with no ->headers.

// Measured on an iPhone: GET /cash answered 500 "Undefined property:
// Redirector::$headers" from the CSRF middleware, on every request, until the
// app was relaunched.
/**
 * @link ../../../../../.docs/features/mobile/architecture.md
 */
final class RestoreFrameworkRedirector
{
    public function __construct(private readonly Application $app) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->needsRestoring()) {
            $redirector = new Redirector($this->app->make('url'));

            $session = $this->app->bound('session.store') ? $this->app->make('session.store') : null;

            if ($session instanceof Store) {
                $redirector->setSession($session);
            }

            $this->app->instance('redirect', $redirector);
        }

        return $next($request);
    }

    // Anything but the framework's own class, including a binding that now
    // throws on resolve. Compared by exact class rather than instanceof:
    // Livewire's extends the framework's, so instanceof answers yes to both.
    private function needsRestoring(): bool
    {
        try {
            $current = $this->app->make('redirect');
        } catch (Throwable) {
            return true;
        }

        return ! self::isFrameworkRedirector($current);
    }

    // Declared mixed on purpose: what the container's return type resolves to
    // differs between here and CI, and narrowing from mixed reads the same to
    // the analyser either way. instanceof first, then the exact class —
    // Livewire's extends the framework's, so instanceof alone accepts both.
    private static function isFrameworkRedirector(mixed $redirector): bool
    {
        return $redirector instanceof Redirector && $redirector::class === Redirector::class;
    }
}
