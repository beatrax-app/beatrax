<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\DevMode\Internal\Process\RunRegistry;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// The PID comes from the cached RunRecord, never the request body: otherwise a
// forged runId would SIGTERM an arbitrary process.
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

        if (! posix_kill($record->pid, 0)) {
            $this->registry->markCancelled($runId);

            return new JsonResponse(null, 204);
        }

        @posix_kill($record->pid, SIGTERM);

        // Blocking the HTTP request through the grace period buys the SSE
        // liveness check a PID that is actually gone by the time it looks.
        // The ignore directives guard a posix_kill stub typed as always-true.
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
            usleep(200_000);
        }

        $this->registry->markCancelled($runId);

        return new JsonResponse(null, 204);
    }
}
