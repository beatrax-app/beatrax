<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Controllers;

use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\DevMode\Internal\Actions\SpawnDevCommand;
use Modules\DevMode\Internal\Enums\CommandTier;
use Modules\DevMode\Internal\Exceptions\CommandRefusedException;

// SAFE tier only. DESTRUCTIVE names are refused rather than handled, so a
// palette bug that leaks one cannot fire it without the triple gate.
final readonly class ArtisanSpawnController
{
    public function __construct(
        private ValidatorFactory $validator,
        private SpawnDevCommand $spawn,
    ) {}

    public function __invoke(Request $request, CurrentUser $user): JsonResponse
    {
        $validated = $this->validator
            ->make($request->all(), [
                'command' => ['required', 'string', 'max:255'],
                'args' => ['sometimes', 'array'],
            ])
            ->validate();

        $argsRaw = $validated['args'] ?? null;
        /** @var array<string, mixed> $args */
        $args = is_array($argsRaw) ? $argsRaw : [];

        try {
            $record = ($this->spawn)($validated['command'] ?? null, $args, $user->id(), CommandTier::Safe);
        } catch (CommandRefusedException $refusal) {
            return new JsonResponse($refusal->wirePayload(), $refusal->wireStatus());
        }

        return new JsonResponse([
            'run_id' => $record->runId,
            'pid' => $record->pid,
        ], 202);
    }
}
