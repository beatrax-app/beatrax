<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Controllers;

use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\DevMode\Internal\Exceptions\SpawnedRunVanishedException;
use Modules\DevMode\Internal\Process\CommandSpawner;
use Modules\DevMode\Internal\Process\RunRegistry;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Dto\CommandSpec;
use Symfony\Component\HttpFoundation\Response;

// SAFE tier only. DESTRUCTIVE names are refused rather than handled, so a
// palette bug that leaks one cannot fire it without the triple gate.
final readonly class ArtisanSpawnController
{
    public function __construct(
        private CommandSpawner $spawner,
        private DevCommandRegistry $registry,
        private RunRegistry $runs,
        private ValidatorFactory $validator,
    ) {}

    public function __invoke(Request $request, CurrentUser $user): JsonResponse
    {
        $safeNames = array_map(
            static fn (CommandSpec $spec): string => $spec->name,
            $this->registry->safe(),
        );
        $destructiveNames = array_map(
            static fn (CommandSpec $spec): string => $spec->name,
            $this->registry->destructive(),
        );

        $payload = $request->all();

        $validated = $this->validator
            ->make($payload, [
                'command' => ['required', 'string', 'max:255'],
                'args' => ['sometimes', 'array'],
            ])
            ->validate();

        $commandRaw = $validated['command'] ?? null;
        if (! is_string($commandRaw)) {
            return new JsonResponse(['error' => 'invalid_command'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }
        $command = $commandRaw;

        $rejection = $this->commandRejection($command, $safeNames, $destructiveNames);
        if ($rejection !== null) {
            return $rejection;
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

        $runId = $this->spawner->start($command, $args, $user->id(), 'safe');
        $record = $this->runs->find($runId);
        if ($record === null) {
            throw SpawnedRunVanishedException::immediatelyAfterSpawn('ArtisanSpawnController', $runId);
        }

        return new JsonResponse([
            'run_id' => $runId,
            'pid' => $record->pid,
        ], 202);
    }

    /**
     * @param  list<string>  $safeNames
     * @param  list<string>  $destructiveNames
     */
    private function commandRejection(string $command, array $safeNames, array $destructiveNames): ?JsonResponse
    {
        return match (true) {
            in_array($command, $destructiveNames, true) => new JsonResponse(
                ['error' => 'destructive_requires_triple_gate'],
                403,
            ),
            ! in_array($command, $safeNames, true) => new JsonResponse(
                ['error' => 'unknown_command', 'command' => $command],
                422,
            ),
            default => null,
        };
    }
}
