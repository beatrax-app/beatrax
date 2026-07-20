<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\DevMode\Internal\Process\RunRegistry;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// Reads the PID from the cached RunRecord (never the request body) so a
// forged runId cannot SIGTERM an arbitrary PID. Sends SIGTERM, waits for
// the child to exit, then falls back to SIGKILL. Cross-user inspection
// is rejected at the same defense-in-depth layer as ArtisanStreamController.
final readonly class ArtisanCancelController
{
    private const SIGTERM_GRACE_SECONDS = 3;

    public function __construct(
        private RunRegistry $registry,
    ) {}

    public function __invoke(string $runId, CurrentUser $user): JsonResponse
    {
        $record = $this->registry->find($runId);
        if ($record === null) {
            throw new NotFoundHttpException("Unknown run: {$runId}");
        }

        if ($record->callerUserId !== $user->id()) {
            throw new AccessDeniedHttpException('cross_user_cancel_forbidden');
        }

        if (! extension_loaded('posix')) {
            throw new HttpException(500, 'posix_required_for_cancel');
        }

        // Already finished: an idempotent cancel on a dead PID still
        // returns 204 rather than surfacing an error to the caller.
        if (! posix_kill($record->pid, 0)) {
            $this->registry->markCancelled($runId);

            return new JsonResponse(null, 204);
        }

        @posix_kill($record->pid, SIGTERM);

        // 3-second grace, then SIGKILL fallback — blocking the HTTP
        // request trades latency so the SSE liveness check can observe
        // the PID actually gone. The ignore directives below guard a
        // stub that always claims a bool(true) return.
        $deadline = microtime(true) + self::SIGTERM_GRACE_SECONDS;
        while (microtime(true) < $deadline) {
            /** @phpstan-ignore-next-line booleanNot.alwaysFalse */
            if (! posix_kill($record->pid, 0)) {
                break;
            }
            usleep(100_000);
        }

        /** @phpstan-ignore-next-line if.alwaysTrue */
        if (posix_kill($record->pid, 0)) {
            @posix_kill($record->pid, SIGKILL);
            // One more brief wait so the SSE liveness check observes
            // the gone PID inside the same wall-clock second.
            usleep(200_000);
        }

        $this->registry->markCancelled($runId);

        return new JsonResponse(null, 204);
    }
}
