<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Controllers;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// One of the three triple-gate locks: session-scoped, default OFF, cleared on
// every login by ResetAdvancedToggleOnLogin. No audit row — the pipeline
// records destructive runs, not the pre-flight toggle.
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
        $session->put('dev_mode.advanced', $value);

        return new JsonResponse(null, 204);
    }
}
