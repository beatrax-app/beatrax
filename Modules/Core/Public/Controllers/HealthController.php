<?php

declare(strict_types=1);

namespace Modules\Core\Public\Controllers;

use Illuminate\Http\JsonResponse;
use Modules\Core\Internal\Support\RuntimeHealthSnapshot;

final readonly class HealthController
{
    public function __construct(
        private RuntimeHealthSnapshot $snapshot,
    ) {}

    public function __invoke(): JsonResponse
    {
        return new JsonResponse(($this->snapshot)());
    }
}
