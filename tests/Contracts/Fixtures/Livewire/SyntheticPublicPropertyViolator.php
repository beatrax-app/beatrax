<?php

declare(strict_types=1);

namespace Tests\Contracts\Fixtures\Livewire;

use Livewire\Component;

/**
 * Synthetic Livewire component used by SecretsInLivewireSnapshotTest's
 * self-test pass. It deliberately exposes a public property named after
 * a SecretsColumnRegistry entry (`oauth_secrets.access_token` →
 * `oauthAccessToken`) so the arch invariant proves it can detect the
 * exact class of leak it exists to forbid.
 *
 * This file lives under tests/ and is excluded from the production
 * Livewire scan — it only fires inside the inverse self-test.
 */
final class SyntheticPublicPropertyViolator extends Component
{
    public string $oauthAccessToken = '';

    public function render(): string
    {
        return '<div></div>';
    }
}
