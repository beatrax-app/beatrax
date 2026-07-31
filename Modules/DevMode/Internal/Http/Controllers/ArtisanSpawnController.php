<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Controllers;

use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\DevMode\Internal\Process\CommandSpawner;
use Modules\DevMode\Internal\Process\RunRegistry;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Dto\CommandSpec;
use Modules\DevMode\Public\Exceptions\SpawnedRunVanishedException;
use Symfony\Component\HttpFoundation\Response;

// SAFE-tier spawn endpoint; DESTRUCTIVE-tier commands are rejected at
// this layer — that path lives behind the TripleGate modal +
// DestructiveSpawnController instead, isolating the SAFE-tier surface so
// a palette bug leaking a DESTRUCTIVE name cannot fire it through here.
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

        // Per-arg rules enforcement: ArgSpec::$rules is a Laravel-ready
        // rule array, so we validate the args sub-array here before the
        // shell ever sees them. This is the third guard alongside the
        // command whitelist and escapeshellarg in CommandSpawner.
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

    // DESTRUCTIVE-tier commands are rejected here (403) — the runner's
    // triple-gate pathway owns destructive execution; unknown names 422
    // before the spawner's whitelist is ever consulted. Null lets the
    // SAFE-tier command through.
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
