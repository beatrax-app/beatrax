<?php

declare(strict_types=1);

namespace Tests\Contracts\Fixtures\Livewire;

use Livewire\Component;

/**
 * Synthetic Livewire component used by SecretsInLivewireSnapshotTest's
 * self-test pass. It deliberately registers a `$listeners` key naming a
 * SecretsColumnRegistry entry verbatim so the arch invariant proves it
 * can detect that variant of the leak (events keyed on secret-column
 * names land in the wire snapshot just like properties).
 */
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
