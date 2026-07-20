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
use RuntimeException;

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
            return new JsonResponse(['error' => 'invalid_command'], 422);
        }
        $command = $commandRaw;

        // Reject DESTRUCTIVE-tier commands at this layer — the runner's
        // triple-gate pathway owns destructive execution.
        if (in_array($command, $destructiveNames, true)) {
            return new JsonResponse(
                ['error' => 'destructive_requires_triple_gate'],
                403,
            );
        }

        if (! in_array($command, $safeNames, true)) {
            return new JsonResponse(
                ['error' => 'unknown_command', 'command' => $command],
                422,
            );
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
            throw new RuntimeException("ArtisanSpawnController: RunRegistry lost record for run {$runId} immediately after spawn.");
        }

        return new JsonResponse([
            'run_id' => $runId,
            'pid' => $record->pid,
        ], 202);
    }
}
