<?php

declare(strict_types=1);

use Illuminate\Contracts\View\Factory as ViewFactory;
use Modules\Community\Public\Dto\SupportResource;
use Modules\Community\Public\Services\SupportResourceProvider;
use Modules\Core\Public\Enums\Locale;
use Modules\Core\Public\Support\Lang;

// The cancellation guidance is a researched paragraph on how ONE provider ends
// a contract, carrying that provider's phone number, notice period and postal
// address. It stays in the provider's language, because 608 of them restated
// by somebody who cannot verify a notice period is a worse answer than prose
// the reader can put through a translator. What the reader is owed is being
// told which language it is, and a screen reader is owed the tag.
function providerProseHtml(SupportResource $resource): string
{
    /** @var ViewFactory $views */
    $views = app(ViewFactory::class);

    return $views->make('counterparties::livewire.profile-tabs.partials.support-resources', [
        'resource' => $resource,
    ])->render();
}

function providerProseResource(?string $lang): SupportResource
{
    return new SupportResource(
        name: 'KPN',
        type: 'merchant',
        cancelUrl: 'https://www.kpn.com/service/administratie/opzeggen',
        notes: 'Opzeggen via het online opzegformulier of telefonisch via 0800 0402.',
        notesLang: $lang,
    );
}

it('carries the language a support file declares onto the resource it built', function (): void {
    /** @var SupportResourceProvider $provider */
    $provider = app(SupportResourceProvider::class);

    expect($provider->forCounterparty('KPN', 'merchant', 'nl')?->notesLang)->toBe('nl')
        ->and($provider->forCounterparty('Spotify AB', 'merchant')?->notesLang)->toBe('en')
        ->and($provider->forCounterparty('Swisscom', 'merchant', 'ch')?->notesLang)->toBe('de');
});

it('tags the paragraph with the provider\'s language so a screen reader changes voice', function (): void {
    app()->setLocale('en');

    expect(providerProseHtml(providerProseResource('nl')))->toContain('lang="nl"');
});

it('names the language to a reader who does not have it', function (): void {
    app()->setLocale('en');

    $html = providerProseHtml(providerProseResource('nl'));

    expect($html)->toContain(Lang::get(
        'counterparties::profile.support.notes_language',
        ['language' => Locale::Nl->label()],
    ));
});

// Naming it to a reader already in that language is noise: they can see what
// language it is by reading it.
it('says nothing about the language when it is the one the reader is in', function (): void {
    app()->setLocale('nl');

    $html = providerProseHtml(providerProseResource('nl'));

    expect($html)->toContain('lang="nl"')
        ->and($html)->not->toContain(Locale::Nl->label());
});

it('prints the paragraph untagged rather than losing it when no language is declared', function (): void {
    app()->setLocale('en');

    $html = providerProseHtml(providerProseResource(null));

    expect($html)->toContain('Opzeggen via het online opzegformulier')
        ->and($html)->not->toContain('lang="');
});
