<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Http\Livewire\Concerns\DispatchesToast;
use Modules\Core\Public\Support\Lang;
use Modules\DevMode\Internal\Enums\ArgType;
use Modules\DevMode\Internal\Enums\CommandTier;
use Modules\DevMode\Internal\Exceptions\ProcessSpawningUnavailableException;
use Modules\DevMode\Internal\Process\CommandArgValidator;
use Modules\DevMode\Internal\Process\CommandSpawner;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Dto\ArgSpec;
use Modules\DevMode\Public\Dto\CommandSpec;

final class CommandArgPromptModal extends Component
{
    use DispatchesToast;

    // The layout mounts this component on every authenticated page, so the
    // /dev route gate never sees it. EnsureDeveloperMode's predicate is
    // restated here because a component reachable from the wire has to answer
    // for itself; the layout condition below it is only the outer skin.
    private static function isDeveloper(CurrentUser $user): bool
    {
        return $user->isAuthenticated() && $user->user()->is_developer === true;
    }

    // Locked because it selects the registry entry submit() resolves: a
    // client swap would spawn something other than what the user was shown.
    #[Locked]
    public string $command = '';

    // Display only — submit() re-derives the tier from the registry, so
    // nothing authorises on this value.
    #[Locked]
    public CommandTier $claimedTier = CommandTier::Safe;

    /**
     * @var array<string, mixed>
     */
    public array $values = [];

    public string $submitError = '';

    /**
     * @param  array<string, mixed>  $prefill
     */
    #[On('command-args:prompt')]
    public function open(string $name, DevCommandRegistry $registry, CurrentUser $user, string $tier = CommandTier::Safe->value, array $prefill = []): void
    {
        if (! self::isDeveloper($user)) {
            return;
        }

        $this->command = $name;
        $this->claimedTier = CommandTier::fromStored($tier);
        $this->values = [];
        $this->submitError = '';

        try {
            $spec = $registry->find($name);
        } catch (InvalidArgumentException) {
            $this->submitError = Lang::get('dev::arg_prompt.errors.unknown_command', ['command' => $name]);
            $this->dispatch('modal-show', name: 'command-args');

            return;
        }

        foreach ($spec->argsSchema as $arg) {
            $supplied = $prefill[$arg->name] ?? null;
            $this->values[$arg->name] = match ($arg->type) {
                ArgType::Boolean => $supplied === true || $supplied === 'true' || $supplied === 1 || $supplied === '1',
                default => is_scalar($supplied) ? (string) $supplied : '',
            };
        }

        $this->dispatch('modal-show', name: 'command-args');
    }

    public function submit(
        DevCommandRegistry $registry,
        CommandSpawner $spawner,
        CurrentUser $user,
        CommandArgValidator $validator,
    ): void {
        if (! self::isDeveloper($user)) {
            return;
        }

        $this->submitError = '';

        $spec = $this->spawnableSpec($registry);
        if ($spec === null) {
            return;
        }

        // Null means a preflight refused the submission and has already put
        // its own message on $submitError.
        $args = $this->acceptedArgs($spec, $validator);
        if ($args === null) {
            return;
        }

        // Spawning directly rather than dispatching `spawn-command`:
        // ArtisanRunnerPage is not mounted on /dev/logs or /dev/queue, and an
        // event with no listener drops the spawn silently.
        $command = $this->command;

        try {
            $runId = $spawner->start($command, $args, $user->id(), CommandTier::Safe);
        } catch (ProcessSpawningUnavailableException $e) {
            // The modal is the only spawn path that reaches the shell without
            // going through ArtisanRunnerPage, so it needs its own answer --
            // uncaught, this was a 500 on every iOS run, where the embedded
            // interpreter has no binary on disk to hand a child process.
            $this->submitError = $e->readerMessage();

            return;
        }

        $this->toast(Lang::get('dev::runner.toast.started', ['command' => $command, 'runId' => $runId]));
        $this->dispatch('modal-close', name: 'command-args');

        $this->values = [];
        $this->command = '';
    }

    public function cancel(): void
    {
        $this->values = [];
        $this->submitError = '';
        $this->command = '';
        $this->dispatch('modal-close', name: 'command-args');
    }

    public function render(ViewFactory $views, DevCommandRegistry $registry): View
    {
        $spec = null;
        if ($this->command !== '') {
            try {
                $spec = $registry->find($this->command);
            } catch (InvalidArgumentException) {
                $spec = null;
            }
        }

        return $views->make('dev::livewire.command-arg-prompt-modal', [
            'spec' => $spec,
            'argSchema' => $spec instanceof CommandSpec ? $spec->argsSchema : [],
        ]);
    }

    // A destructive name reaching here bypassed the palette's safe-only
    // filter; it reroutes to the triple gate rather than being refused.
    private function spawnableSpec(DevCommandRegistry $registry): ?CommandSpec
    {
        try {
            $spec = $registry->find($this->command);
        } catch (InvalidArgumentException) {
            $this->submitError = Lang::get('dev::arg_prompt.errors.unknown_command', ['command' => $this->command]);

            return null;
        }

        if ($spec->tier->reachesThePalette()) {
            return $spec;
        }

        $this->dispatch(
            'triple-gate:open',
            command: $this->command,
            args: $this->values,
        );
        $this->dispatch('modal-close', name: 'command-args');

        return null;
    }

    // Required-arg checks run against the raw form values; the declared rules
    // run against the normalised map the spawner will actually receive.
    /**
     * @return array<string, mixed>|null
     */
    private function acceptedArgs(CommandSpec $spec, CommandArgValidator $validator): ?array
    {
        $missing = $this->missingRequiredArgs($spec->argsSchema);
        if ($missing !== []) {
            $this->submitError = Lang::get('dev::arg_prompt.errors.missing', [
                'noun' => Lang::choice('dev::arg_prompt.errors.arg', count($missing)),
                'list' => implode(', ', $missing),
            ]);

            return null;
        }

        $args = $this->normalisedArgs($spec);

        return $this->argsSatisfyRules($spec, $args, $validator) ? $args : null;
    }

    // Blank optional values are dropped: the spawner skips null and missing
    // keys, but an empty string would render as `php artisan cmd ''`.
    /**
     * @return array<string, mixed>
     */
    private function normalisedArgs(CommandSpec $spec): array
    {
        $args = [];
        foreach ($spec->argsSchema as $arg) {
            $value = $this->values[$arg->name] ?? null;
            if ($arg->type === ArgType::Boolean) {
                if ($value === true) {
                    $args[$arg->name] = true;
                }

                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }
            $args[$arg->name] = $value;
        }

        return $args;
    }

    // Third guard, after the registry allow-list and escapeshellarg. The
    // violation surfaces on $submitError rather than being rethrown, which
    // would blank a form the operator is mid-edit on.
    /**
     * @param  array<string, mixed>  $args
     */
    private function argsSatisfyRules(CommandSpec $spec, array $args, CommandArgValidator $validator): bool
    {
        try {
            $validator->assertValid($spec, $args);
        } catch (ValidationException $e) {
            $first = $e->validator->errors()->first();
            $this->submitError = $first !== ''
                ? $first
                : Lang::get('dev::arg_prompt.errors.invalid_args');

            return false;
        }

        return true;
    }

    /**
     * @param  list<ArgSpec>  $schema
     * @return list<string>
     */
    private function missingRequiredArgs(array $schema): array
    {
        $missing = [];
        foreach ($schema as $arg) {
            if (! in_array('required', $arg->rules, true)) {
                continue;
            }
            $value = $this->values[$arg->name] ?? null;
            if ($value === null || $value === '' || $value === []) {
                $missing[] = $arg->label !== '' ? $arg->label : $arg->name;
            }
        }

        return $missing;
    }
}
