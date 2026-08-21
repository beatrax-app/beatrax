<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Controllers;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\DevMode\Internal\Exceptions\SpawnedRunVanishedException;
use Modules\DevMode\Internal\Process\CommandSpawner;
use Modules\DevMode\Internal\Process\RunRegistry;
use Modules\DevMode\Internal\Services\DevModeFlag;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Dto\CommandSpec;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

// The only DESTRUCTIVE-tier entry point. All three TripleGateModal gates are
// re-checked server-side, so a tampered Livewire payload still cannot spawn.
final readonly class DestructiveSpawnController
{
    public function __construct(
        private CommandSpawner $spawner,
        private DevCommandRegistry $registry,
        private RunRegistry $runs,
        private ValidatorFactory $validator,
        private DevModeFlag $devMode,
    ) {}

    public function __invoke(
        Request $request,
        CurrentUser $user,
        Session $session,
    ): JsonResponse {
        if (! $this->devMode->isOn()) {
            throw new AccessDeniedHttpException('dev_mode_off');
        }

        if ($session->get('dev_mode.advanced') !== true) {
            throw new AccessDeniedHttpException('advanced_off');
        }

        $payload = $request->all();
        $confirmed = $payload['confirmed_typed'] ?? '';
        if (! is_string($confirmed) || ! hash_equals('Beatrax', $confirmed)) {
            throw new AccessDeniedHttpException('app_name_mismatch');
        }

        $validated = $this->validator
            ->make($payload, [
                'command' => ['required', 'string', 'max:255'],
                'args' => ['sometimes', 'array'],
                'confirmed_typed' => ['required', 'string'],
            ])
            ->validate();

        $commandRaw = $validated['command'] ?? null;
        if (! is_string($commandRaw)) {
            return new JsonResponse(['error' => 'invalid_command'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $command = $commandRaw;

        // SAFE-tier names are refused rather than run, so neither controller
        // doubles as a second route to the other tier.
        $destructiveNames = array_map(
            static fn (CommandSpec $spec): string => $spec->name,
            $this->registry->destructive(),
        );
        if (! in_array($command, $destructiveNames, true)) {
            return new JsonResponse(
                ['error' => 'not_destructive', 'command' => $command],
                422,
            );
        }

        $spec = $this->registry->find($command);

        // Third guard on the args, alongside the command whitelist and
        // CommandSpawner's escapeshellarg — and the only one before the shell.
        if ($spec->argsSchema !== []) {
            $argRules = [];
            foreach ($spec->argsSchema as $argSpec) {
                $argRules['args.'.$argSpec->name] = $argSpec->rules;
            }
            $this->validator->make($payload, $argRules)->validate();
        }

        $argsRaw = $validated['args'] ?? null;
        /** @var array<string, mixed> $args */
        $args = is_array($argsRaw) ? $argsRaw : [];

        $runId = $this->spawner->start($command, $args, $user->id(), 'destructive');
        $record = $this->runs->find($runId);
        if ($record === null) {
            throw SpawnedRunVanishedException::immediatelyAfterSpawn('DestructiveSpawnController', $runId);
        }

        return new JsonResponse([
            'run_id' => $runId,
            'pid' => $record->pid,
        ], 202);
    }
}
