<?php

declare(strict_types=1);

namespace Tests\Contracts\Fixtures\Livewire;

use Livewire\Component;

// Deliberately broken: the $queryString entry names a SecretsColumnRegistry
// column verbatim, which would echo the value into the URL.
// SecretsInLivewireSnapshotTest's inverse pass fails if the scan stops
// flagging it, so do not "fix" this component.
final class SyntheticQueryStringViolator extends Component
{
    /** @var array<int, string> */
    protected $queryString = [
        'oauth_secrets.client_secret',
    ];

    public function render(): string
    {
        return '<div></div>';
    }
}
