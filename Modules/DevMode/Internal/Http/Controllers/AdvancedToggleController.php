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
 * command triple-gate: session-scoped, default OFF, resets on every
 * login (see ResetAdvancedToggleOnLogin). This controller is the
 * storage endpoint the UI calls when the operator flips the toggle.
 *
 * Body: {value: bool}; response: 204 No Content. The session write
 * is the only side effect; no audit row — the audit pipeline
 * records the actual destructive command runs, not the pre-flight
 * toggle state.
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
