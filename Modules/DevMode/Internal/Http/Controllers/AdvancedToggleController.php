<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Controllers;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\DevMode\Internal\Support\DevModeSession;

// One of the three triple-gate locks: session-scoped and default OFF. No audit
// row, because the pipeline records destructive runs, not the pre-flight.
final readonly class AdvancedToggleController
{
    public function __construct(
        private ValidatorFactory $validator,
    ) {}

    public function __invoke(Request $request, Session $session): JsonResponse
    {
        $validated = $this->validator
            ->make($request->all(), [
                'value' => ['required', 'boolean'],
            ])
            ->validate();

        $value = (bool) ($validated['value'] ?? false);
        $session->put(DevModeSession::ADVANCED_KEY, $value);

        return new JsonResponse(null, 204);
    }
}
