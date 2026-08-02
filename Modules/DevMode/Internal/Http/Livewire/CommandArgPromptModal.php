<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Livewire;

use Illuminate\Contracts\Validation\Factory as ValidatorFactory;
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
use Modules\DevMode\Internal\Process\CommandSpawner;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Dto\ArgSpec;
use Modules\DevMode\Public\Dto\CommandSpec;

/**
 * @link ../../../../../.docs/features/dev-mode/architecture.md
 */
final class CommandArgPromptModal extends Component
{
    use DispatchesToast;

    // #[Locked] — $command selects which registry entry (and therefore which
    // tier and arg rules) submit() resolves. Only the open() listener may set
    // it; letting the client swap it after the modal is populated would
    // decouple the spawn from the entry the user was shown.
    #[Locked]
    public string $command = '';

    // #[Locked] for the same reason, though nothing authorises on it —
    // submit() re-derives the tier from the registry rather than trusting
    // this. It exists to render the destructive-tier warning copy.
    #[Locked]
    public string $claimedTier = 'safe';

    // Values arrive as strings (Livewire wire:model binds <input> here);
    // the spawn-time renderArg() in CommandSpawner handles the typed
    // conversion downstream.
    /**
     * @var array<string, mixed>
     */
    public array $values = [];

    public string $submitError = '';

    /**
     * @param  array<string, mixed>  $prefill
     */
    #[On('command-args:prompt')]
    public function open(string $name, DevCommandRegistry $registry, string $tier = 'safe', array $prefill = []): void
    {
        $this->command = $name;
        $this->claimedTier = $tier !== '' ? $tier : 'safe';
        $this->values = [];
        $this->submitError = '';

        try {
            $spec = $registry->find($name);
        } catch (InvalidArgumentException) {
            $this->submitError = Lang::get('dev::arg_prompt.errors.unknown_command', ['command' => $name]);
            $this->dispatch('modal-show', name: 'command-args');

            return;
        }

        // Seed default values so the form renders prefilled inputs.
        // Boolean defaults to false; text/select default to whatever
        // the dispatcher supplied for that key, or empty.
        foreach ($spec->argsSchema as $arg) {
            $supplied = $prefill[$arg->name] ?? null;
            $this->values[$arg->name] = match ($arg->type) {
                'boolean' => $supplied === true || $supplied === 'true' || $supplied === 1 || $supplied === '1',
                default => is_scalar($supplied) ? (string) $supplied : '',
            };
        }

        $this->dispatch('modal-show', name: 'command-args');
    }

    public function submit(
        DevCommandRegistry $registry,
        CommandSpawner $spawner,
        CurrentUser $user,
        ValidatorFactory $validator,
    ): void {
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

        // Spawn directly via CommandSpawner rather than dispatching a
        // `spawn-command` event: ArtisanRunnerPage is not always
        // mounted (e.g. on /dev/logs, /dev/queue), so an event-only
        // path silently drops the spawn on those pages.
        $command = $this->command;
        $runId = $spawner->start($command, $args, $user->id(), 'safe');

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

    // The registry entry this submission may spawn, or null when it may not.
    // A destructive name reaching here is a hostile dispatch that bypassed the
    // palette's safe-only filter, so it is rerouted to the triple gate rather
    // than refused outright — the same posture the runner page takes.
    private function spawnableSpec(DevCommandRegistry $registry): ?CommandSpec
    {
        try {
            $spec = $registry->find($this->command);
        } catch (InvalidArgumentException) {
            $this->submitError = Lang::get('dev::arg_prompt.errors.unknown_command', ['command' => $this->command]);

            return null;
        }

        if ($spec->tier === 'safe') {
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

    // The args map to hand the spawner, or null when a preflight refused it.
    // Both gates live here so submit() reads as resolve-then-spawn: required
    // args are checked against the raw form values, and the declared rules
    // against the normalised map the spawner will actually receive.
    /**
     * @return array<string, mixed>|null
     */
    private function acceptedArgs(CommandSpec $spec, ValidatorFactory $validator): ?array
    {
        $missing = $this->missingRequiredArgs($spec->argsSchema);
        if ($missing !== []) {
            $this->submitError = Lang::get('dev::arg_prompt.errors.missing', [
                'noun' => count($missing) === 1
                    ? Lang::get('dev::arg_prompt.errors.arg_singular')
                    : Lang::get('dev::arg_prompt.errors.arg_plural'),
                'list' => implode(', ', $missing),
            ]);

            return null;
        }

        $args = $this->normalisedArgs($spec);

        return $this->argsSatisfyRules($spec, $args, $validator) ? $args : null;
    }

    // Drops blank optional values: renderArg() already skips null and missing
    // keys, but an empty string renders as `php artisan cmd ''`, which Laravel
    // sometimes rejects.
    /**
     * @return array<string, mixed>
     */
    private function normalisedArgs(CommandSpec $spec): array
    {
        $args = [];
        foreach ($spec->argsSchema as $arg) {
            $value = $this->values[$arg->name] ?? null;
            if ($arg->type === 'boolean') {
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

    // Mirrors the ArgSpec::$rules validation both spawn controllers run — the
    // third guard alongside the registry allow-list and escapeshellarg. The
    // violation surfaces as $submitError rather than rethrown, because an
    // uncaught one would blank a form the operator is mid-edit on.
    /**
     * @param  array<string, mixed>  $args
     */
    private function argsSatisfyRules(CommandSpec $spec, array $args, ValidatorFactory $validator): bool
    {
        if ($spec->argsSchema === []) {
            return true;
        }

        $argRules = [];
        foreach ($spec->argsSchema as $argSpec) {
            $argRules['args.'.$argSpec->name] = $argSpec->rules;
        }

        try {
            $validator->make(['args' => $args], $argRules)->validate();
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
