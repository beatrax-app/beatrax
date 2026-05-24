<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Controllers;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /dev/advanced-toggle — flip the per-session Advanced flag.
 *
 * The Advanced toggle is one of the three locks in the destructive-
 * command triple-gate (CONTEXT D-20): session-scoped, default OFF,
 * resets on every login. The full reset listener + the UI surface
 * land in 16-04b — this controller is the storage endpoint they
 * both consume.
 *
 * Body: `{value: bool}`; response: 204 No Content. The session
 * write is the only side effect; no audit row (16-04b's audit
 * pipeline records the actual destructive command runs, not the
 * pre-flight toggles).
 */
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
