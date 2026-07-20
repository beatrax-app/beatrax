<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Controllers;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

// The Advanced toggle is one of the three triple-gate locks: session-
// scoped, default OFF, resets on every login (ResetAdvancedToggleOnLogin).
// No audit row here — the audit pipeline records actual destructive
// command runs, not this pre-flight toggle state.
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
