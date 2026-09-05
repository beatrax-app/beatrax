<?php

declare(strict_types=1);

use Illuminate\Contracts\View\Factory as ViewFactory;
use Modules\Community\Public\Dto\SupportResource;
use Modules\Core\Public\Enums\ExternalUrlRefusal;
use Modules\Core\Public\Support\Lang;

function supportCardHtml(SupportResource $resource): string
{
    /** @var ViewFactory $views */
    $views = app(ViewFactory::class);

    return $views->make('counterparties::livewire.profile-tabs.partials.support-resources', [
        'resource' => $resource,
    ])->render();
}

it('renders a chip for a link the gate withheld rather than dropping it', function (): void {
    $html = supportCardHtml(new SupportResource(
        name: 'Voorbeeld',
        type: 'merchant',
        supportUrl: 'https://voorbeeld.test/help',
        withheld: ['cancel_url' => ExternalUrlRefusal::NotHttps],
    ));

    expect($html)->toContain(Lang::get('counterparties::profile.support.cancel'))
        ->and($html)->toContain(Lang::get('counterparties::profile.support.withheld'))
        ->and($html)->toContain('https://voorbeeld.test/help');
});

it('gives a withheld link no href, so nothing can follow it', function (): void {
    $html = supportCardHtml(new SupportResource(
        name: 'Voorbeeld',
        type: 'merchant',
        withheld: ['cancel_url' => ExternalUrlRefusal::NotHttps],
    ));

    expect($html)->not->toContain('<a class="support-chip" href')
        ->and($html)->toContain(Lang::get('counterparties::profile.support.withheld'));
});

it('says nothing about withholding when every link came through', function (): void {
    $html = supportCardHtml(new SupportResource(
        name: 'Voorbeeld',
        type: 'merchant',
        cancelUrl: 'https://voorbeeld.test/opzeggen',
    ));

    expect($html)->toContain('https://voorbeeld.test/opzeggen')
        ->and($html)->not->toContain(Lang::get('counterparties::profile.support.withheld'));
});
