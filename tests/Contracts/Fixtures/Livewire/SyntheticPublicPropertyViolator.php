<?php

declare(strict_types=1);

namespace Tests\Contracts\Fixtures\Livewire;

use Livewire\Component;

// Deliberately broken: $oauthAccessToken is the camelCase form of the
// SecretsColumnRegistry entry oauth_secrets.access_token.
// SecretsInLivewireSnapshotTest's inverse pass fails if the scan stops
// flagging it, so do not "fix" this component.
final class SyntheticPublicPropertyViolator extends Component
{
    public string $oauthAccessToken = '';

    public function render(): string
    {
        return '<div></div>';
    }
}
