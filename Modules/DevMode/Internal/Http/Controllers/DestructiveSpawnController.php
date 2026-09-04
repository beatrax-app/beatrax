<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Controllers;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\DevMode\Internal\Actions\SpawnDevCommand;
use Modules\DevMode\Internal\Enums\CommandTier;
use Modules\DevMode\Internal\Exceptions\CommandRefusedException;
use Modules\DevMode\Internal\Services\DevModeFlag;
use Modules\DevMode\Internal\Support\DevModeSession;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

// The only DESTRUCTIVE-tier entry point. All three TripleGateModal gates are
// re-checked here rather than in the action, so a tampered Livewire payload
// still cannot spawn and the checks sit where the route can be read beside them.
final readonly class DestructiveSpawnController
{
    public function __construct(
        private ValidatorFactory $validator,
        private DevModeFlag $devMode,
        private SpawnDevCommand $spawn,
    ) {}

    public function __invoke(
        Request $request,
        CurrentUser $user,
        Session $session,
    ): JsonResponse {
        $payload = $request->all();
        $this->assertTripleGate($session, $payload['confirmed_typed'] ?? '');

        $validated = $this->validator
            ->make($payload, [
                'command' => ['required', 'string', 'max:255'],
                'args' => ['sometimes', 'array'],
                'confirmed_typed' => ['required', 'string'],
            ])
            ->validate();

        $argsRaw = $validated['args'] ?? null;
        /** @var array<string, mixed> $args */
        $args = is_array($argsRaw) ? $argsRaw : [];

        try {
            $record = ($this->spawn)($validated['command'] ?? null, $args, $user->id(), CommandTier::Destructive);
        } catch (CommandRefusedException $refusal) {
            return new JsonResponse($refusal->wirePayload(), $refusal->wireStatus());
        }

        return new JsonResponse([
            'run_id' => $record->runId,
            'pid' => $record->pid,
        ], 202);
    }

    private function assertTripleGate(Session $session, mixed $confirmed): void
    {
        if (! $this->devMode->isOn()) {
            throw new AccessDeniedHttpException('dev_mode_off');
        }

        if ($session->get(DevModeSession::ADVANCED_KEY) !== true) {
            throw new AccessDeniedHttpException('advanced_off');
        }

        if (! is_string($confirmed) || ! hash_equals('Beatrax', $confirmed)) {
            throw new AccessDeniedHttpException('app_name_mismatch');
        }
    }
}
