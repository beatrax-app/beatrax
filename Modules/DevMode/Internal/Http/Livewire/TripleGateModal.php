<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Livewire;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\DevMode\Internal\Services\DevModeFlag;

/**
 * Global triple-gate modal SFC. Mounted once in the dev-shell
 * layout so the `triple-gate:open` Livewire event can open the
 * modal from anywhere on `/dev/*` (runner timeline rows, command
 * palette, queue inspector's bulk-delete affordance).
 *
 * Server-side enforcement of all three locks before any DESTRUCTIVE
 * command spawns:
 *
 *   Gate 1: config('app.dev_mode') === true (env-pinned).
 *   Gate 2: session('dev_mode.advanced') === true (the operator
 *           flipped the in-session Advanced toggle inside the
 *           current login — resets on Login via
 *           ResetAdvancedToggleOnLogin).
 *   Gate 3: Operator typed the exact lowercase app name `beatrax`
 *           (timing-safe hash_equals comparison so client-side
 *           enable/disable of the button is purely cosmetic).
 *
 * On all-three-pass: dispatches `triple-gate:confirmed` with the
 * command + args + the typed token. Downstream listeners
 * (DestructiveSpawnController for artisan; QueueInspectorPage for
 * bulk-delete) re-validate all three gates a second time —
 * defense-in-depth so a tampered Livewire payload that somehow
 * spoofs the confirmed event still cannot reach the spawner /
 * delete path without passing the identical three-gate sweep.
 *
 * Method-DI on confirm() — Livewire components never receive
 * constructor DI per the project's larastan-strict-rules profile.
 */
final class TripleGateModal extends Component
{
    /** Command name set by the `triple-gate:open` event. */
    public string $command = '';

    /**
     * Args set by the `triple-gate:open` event. Forwarded verbatim
     * into the spawn payload so the DestructiveSpawnController re-
     * validates them against the ArgSpec::rules array.
     *
     * @var array<string, mixed>
     */
    public array $resolvedArgs = [];

    /** The operator's typed input for the third gate mode. */
    public string $typed = '';

    /** Last server-side gate error code (form-level). */
    public string $gateError = '';

    /**
     * @param  array<string, mixed>  $args
     */
    #[On('triple-gate:open')]
    public function open(string $command = '', array $args = []): void
    {
        $this->command = $command;
        $this->resolvedArgs = $args;
        $this->typed = '';
        $this->gateError = '';
    }

    public function confirm(
        DevModeFlag $devMode,
        Session $session,
    ): void {
        $this->gateError = '';

        // Three server-side gates: Dev Mode env flag, session Advanced
        // toggle, then the typed-name comparison below.
        if (! $devMode->isOn()) {
            $this->gateError = 'dev_mode_off';
            throw ValidationException::withMessages(['_gate' => 'dev_mode_off']);
        }

        if ($session->get('dev_mode.advanced') !== true) {
            $this->gateError = 'advanced_off';
            throw ValidationException::withMessages(['_gate' => 'advanced_off']);
        }

        // Case-sensitive, timing-safe comparison against the lowercase
        // token "beatrax".
        if (! hash_equals('beatrax', $this->typed)) {
            $this->gateError = 'app_name_mismatch';
            throw ValidationException::withMessages(['typed' => 'app_name_mismatch']);
        }

        // Downstream listeners (DestructiveSpawnController,
        // QueueInspectorPage) re-validate all three gates a second
        // time before acting — defense-in-depth against a spoofed event.
        $this->dispatch(
            'triple-gate:confirmed',
            command: $this->command,
            args: $this->resolvedArgs,
            confirmed_typed: $this->typed,
        );

        $this->dispatch('modal-close', name: 'triple-gate');

        $this->typed = '';
    }

    public function cancel(): void
    {
        $this->typed = '';
        $this->gateError = '';
        $this->dispatch('modal-close', name: 'triple-gate');
    }

    public function render(ViewFactory $views): View
    {
        return $views->make('dev::livewire.triple-gate-modal');
    }
}
