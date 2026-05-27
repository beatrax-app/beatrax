<?php

declare(strict_types=1);

namespace Tests\Contracts\Fixtures\Livewire;

use Livewire\Component;

/**
 * Synthetic Livewire component used by SecretsInLivewireSnapshotTest's
 * self-test pass. It deliberately includes a `$queryString` entry that
 * names a SecretsColumnRegistry column verbatim. A real `$queryString`
 * binding would echo the property value into the URL — explicitly the
 * worst place a stored secret could surface — so the arch invariant
 * must catch this shape.
 */
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
