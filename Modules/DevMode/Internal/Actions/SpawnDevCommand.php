<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Actions;

use Modules\DevMode\Internal\Enums\CommandTier;
use Modules\DevMode\Internal\Exceptions\CommandRefusedException;
use Modules\DevMode\Internal\Exceptions\SpawnedRunVanishedException;
use Modules\DevMode\Internal\Process\CommandArgValidator;
use Modules\DevMode\Internal\Process\CommandSpawner;
use Modules\DevMode\Internal\Process\RunRecord;
use Modules\DevMode\Internal\Process\RunRegistry;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Dto\CommandSpec;

final readonly class SpawnDevCommand
{
    public function __construct(
        private CommandSpawner $spawner,
        private DevCommandRegistry $registry,
        private RunRegistry $runs,
        private CommandArgValidator $argValidator,
    ) {}

    /**
     * @param  array<string, mixed>  $args
     *
     * @throws CommandRefusedException
     */
    public function __invoke(mixed $command, array $args, int $callerUserId, CommandTier $tier): RunRecord
    {
        $name = $this->runnableName($command, $tier);

        // Third guard on the args, alongside the command whitelist and
        // CommandSpawner's escapeshellarg — and the only one before the shell.
        $this->argValidator->assertValid($this->registry->find($name), $args);

        $runId = $this->spawner->start($name, $args, $callerUserId, $tier);

        $record = $this->runs->find($runId);
        if ($record === null) {
            throw SpawnedRunVanishedException::immediatelyAfterSpawn($runId);
        }

        return $record;
    }

    // Neither spawn endpoint doubles as a route into the other tier, and the
    // destructive list is read FIRST on the safe path: a name in both lists is
    // the registry's own bug, and reading it as safe would run a destructive
    // command with no triple gate in front of it.
    private function runnableName(mixed $command, CommandTier $tier): string
    {
        if (! is_string($command)) {
            throw CommandRefusedException::invalidCommand();
        }

        if ($tier === CommandTier::Safe && in_array($command, $this->namesFor(CommandTier::Destructive), true)) {
            throw CommandRefusedException::destructiveRequiresTripleGate();
        }

        if (in_array($command, $this->namesFor($tier), true)) {
            return $command;
        }

        throw $tier === CommandTier::Destructive
            ? CommandRefusedException::notDestructive($command)
            : CommandRefusedException::unknownCommand($command);
    }

    /**
     * @return list<string>
     */
    private function namesFor(CommandTier $tier): array
    {
        return array_map(
            static fn (CommandSpec $spec): string => $spec->name,
            $tier === CommandTier::Destructive ? $this->registry->destructive() : $this->registry->safe(),
        );
    }
}
