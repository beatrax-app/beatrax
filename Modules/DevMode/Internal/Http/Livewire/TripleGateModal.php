<?php

declare(strict_types=1);

namespace Modules\DevMode\Internal\Http\Livewire;

use Illuminate\Contracts\Session\Session;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;
use Modules\DevMode\Internal\Services\DevModeFlag;

final class TripleGateModal extends Component
{
    // Locked on both: the typed-name gate confirms THIS command with THESE
    // args, so a client that could rewrite them between open() and confirm()
    // would keep the ceremony while swapping what it authorises.
    #[Locked]
    public string $command = '';

    /**
     * @var array<string, mixed>
     */
    #[Locked]
    public array $resolvedArgs = [];

    public string $typed = '';

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

        if (! $devMode->isOn()) {
            $this->gateError = 'dev_mode_off';
            throw ValidationException::withMessages(['_gate' => 'dev_mode_off']);
        }

        if ($session->get('dev_mode.advanced') !== true) {
            $this->gateError = 'advanced_off';
            throw ValidationException::withMessages(['_gate' => 'advanced_off']);
        }

        if (! hash_equals('Beatrax', $this->typed)) {
            $this->gateError = 'app_name_mismatch';
            throw ValidationException::withMessages(['typed' => 'app_name_mismatch']);
        }

        // Every listener re-validates all three gates itself, so a spoofed
        // confirm event gains nothing by reaching one.
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
