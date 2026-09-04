<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\DevMode\Internal\Actions\CancelDevCommandRun;
use Modules\DevMode\Internal\Process\RunRegistry;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class ArtisanCancelController
{
    public function __construct(
        private RunRegistry $registry,
        private CancelDevCommandRun $cancel,
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

        ($this->cancel)($record);

        return new JsonResponse(null, 204);
    }
}
