<?php

declare(strict_types=1);

namespace Modules\Core\Public\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Public\Http\ResponseTailBudget;
use Symfony\Component\HttpFoundation\Response;

// Laravel decides a middleware is terminable by finding a terminate() method on
// it, then calls it with the request and the response it has already sent. Both
// halves are declared once here: the signature, and the fact that "already
// sent" is false on the mobile runtime, which is what the budget is for.
/**
 * @link ../../../../../.docs/features/mobile/a-tail-the-reader-waits-for.md
 */
abstract readonly class AfterResponseMiddleware
{
    public function __construct(protected ResponseTailBudget $tail) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    // The budget is opened here, once per request whichever tail runs first, so
    // the six of these that a request may carry share one wait rather than
    // taking one each.
    public function terminate(Request $request, Response $response): void
    {
        $this->tail->openFor($request);

        $this->afterResponse();
    }

    abstract protected function afterResponse(): void;
}
