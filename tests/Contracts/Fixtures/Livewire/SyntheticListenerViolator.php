<?php

declare(strict_types=1);

namespace Tests\Contracts\Fixtures\Livewire;

use Livewire\Component;

// Deliberately broken: the $listeners key names a SecretsColumnRegistry entry
// verbatim. SecretsInLivewireSnapshotTest's inverse pass fails if the scan
// stops flagging it, so do not "fix" this component.
final class SyntheticListenerViolator extends Component
{
    /** @var array<string, string> */
    protected $listeners = [
        'oauth_secrets.refresh_token' => 'handle',
    ];

    public function render(): string
    {
        return '<div></div>';
    }
}
