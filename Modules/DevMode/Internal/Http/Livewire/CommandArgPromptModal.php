<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\DevMode\Internal\Process\CommandSpawner;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Dto\ArgSpec;
use Modules\DevMode\Public\Dto\CommandSpec;

/**
 * @link ../../../../../.docs/features/dev-mode/architecture.md
 */
final class CommandArgPromptModal extends Component
{
    public string $command = '';

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
            $this->submitError = 'Unknown command: '.$name;
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
    ): void {
        $this->submitError = '';

        try {
            $spec = $registry->find($this->command);
        } catch (InvalidArgumentException) {
            $this->submitError = 'Unknown command: '.$this->command;

            return;
        }

        if ($spec->tier !== 'safe') {
            // A hostile dispatch shipping a destructive name routes
            // through the triple-gate instead of spawning — same
            // defense-in-depth posture spawn() takes on the runner page.
            $this->dispatch(
                'triple-gate:open',
                command: $this->command,
                args: $this->values,
            );
            $this->dispatch('modal-close', name: 'command-args');

            return;
        }

        $missing = $this->missingRequiredArgs($spec->argsSchema);
        if ($missing !== []) {
            $this->submitError = sprintf(
                'Missing %s: %s',
                count($missing) === 1 ? 'argument' : 'arguments',
                implode(', ', $missing),
            );

            return;
        }

        // Drop blank optional values: renderArg() already skips null
        // and missing keys, but an empty string renders as
        // `php artisan cmd ''`, which Laravel sometimes rejects.
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

        // Spawn directly via CommandSpawner rather than dispatching a
        // `spawn-command` event: ArtisanRunnerPage is not always
        // mounted (e.g. on /dev/logs, /dev/queue), so an event-only
        // path silently drops the spawn on those pages.
        $command = $this->command;
        $runId = $spawner->start($command, $args, $user->id(), 'safe');

        $this->dispatch('toast', message: 'Started '.$command.' (run '.$runId.')');
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
