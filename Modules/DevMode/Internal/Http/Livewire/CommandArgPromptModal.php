<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Livewire;

use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Dto\ArgSpec;
use Modules\DevMode\Public\Dto\CommandSpec;

/**
 * Global arg-prompt modal SFC. Mounted once per layout so the
 * `command-args:prompt` Livewire event can open the modal from
 * anywhere — the command palette, the artisan runner's fallback
 * modal, or a future surface that exposes per-command launchers.
 *
 * Renders a Flux flyout with one input per ArgSpec on the targeted
 * CommandSpec. Field types map to native inputs:
 *
 *   - `text`    → `<input type=text>` with placeholder + helpText
 *   - `boolean` → `<input type=checkbox>` (renders as the literal
 *                 `--name` flag downstream when truthy)
 *   - `select`  → `<select>` populated from `$argSpec->options`
 *
 * Required-arg enforcement is three-layered:
 *
 *   - Submit button disabled (and aria-disabled) in the blade while
 *     any required field is empty — pure UX nicety, surfaces the
 *     gate before the click.
 *   - On submit, the component re-checks required-arg presence
 *     server-side and refuses to dispatch the spawn event with an
 *     in-modal error banner.
 *   - The existing pre-spawn guard in
 *     {@see ArtisanRunnerPage::spawn()}
 *     remains the third line of defense for any caller that
 *     bypasses this modal.
 *
 * Hostile DESTRUCTIVE-tier names that somehow arrive on this event
 * (the palette JSON only exposes SAFE rows) are routed back through
 * `triple-gate:open` rather than spawned — defense-in-depth.
 *
 * Method-DI on listeners + render() per the project's larastan-
 * strict-rules profile (no constructor DI on Livewire Component
 * subclasses).
 */
final class CommandArgPromptModal extends Component
{
    /** Target command name set by the `command-args:prompt` event. */
    public string $command = '';

    /** Tier claimed by the dispatcher; re-verified against the registry on submit. */
    public string $claimedTier = 'safe';

    /**
     * The form state — keyed by ArgSpec::$name. Public so Livewire
     * binds <input wire:model="values.<name>"> reactively. Values
     * arrive as strings; the spawn-time renderArg() in CommandSpawner
     * handles the typed conversion downstream.
     *
     * @var array<string, mixed>
     */
    public array $values = [];

    /**
     * Form-level error banner. Populated when the submit-time
     * required-arg re-check trips. Cleared on every open + submit
     * attempt.
     */
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

    public function submit(DevCommandRegistry $registry): void
    {
        $this->submitError = '';

        try {
            $spec = $registry->find($this->command);
        } catch (InvalidArgumentException) {
            $this->submitError = 'Unknown command: '.$this->command;

            return;
        }

        if ($spec->tier !== 'safe') {
            // The palette JSON excludes destructive rows so this path
            // should never fire from the legitimate UI. A hostile
            // dispatch that ships a destructive name routes through
            // the triple-gate instead of spawning — same defense-in-
            // depth posture spawn() takes on the runner page.
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

        // Normalise the args map: drop blank optional values so the
        // spawner's renderArg() skips them entirely (it already
        // skips null + missing keys, but an empty string would
        // render as `php artisan cmd ''` which Laravel sometimes
        // rejects).
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

        // Same event the palette already uses for no-arg commands.
        // ArtisanRunnerPage::onSpawnCommand listens and routes
        // through spawn(), where the required-arg guard is a third
        // line of defense.
        $this->dispatch(
            'spawn-command',
            name: $this->command,
            args: $args,
            tier: 'safe',
        );

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
