<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Modules\Community\Internal\Http\Livewire\SuggestMappingModal;
use Modules\Community\Public\Events\MysteryMerchantSubmitted;
use Modules\Community\Tests\Support\RecordingUrlOpener;
use Modules\Core\Public\Contracts\ExternalUrlOpener;
use Modules\Core\Public\Support\Lang;

beforeEach(function (): void {
    $this->user = makeCommunityTestUser('suggest-modal-user');
    $this->actingAs($this->user);

    $this->shell = new RecordingUrlOpener;
    $this->app->instance(ExternalUrlOpener::class, $this->shell);
});

it('opens with a prefilled pattern, submits, launches the system browser, and dispatches a MysteryMerchantSubmitted event', function (): void {
    $captured = [];
    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);
    $events->listen(MysteryMerchantSubmitted::class, static function (MysteryMerchantSubmitted $event) use (&$captured): void {
        $captured[] = $event;
    });

    Livewire::test(SuggestMappingModal::class)
        ->dispatch('suggest-mapping:open', rawDescription: 'SHELL*PIETER*')
        ->assertSet('pattern', 'SHELL*PIETER*')
        ->set('name', 'Shell Pieter')
        ->call('submit')
        ->assertDispatched('toast')
        ->assertDispatched('modal-close');

    expect($this->shell->openCalls)->not->toBeEmpty();
    $url = $this->shell->openCalls[0];
    expect($url)->toStartWith('https://github.com/');
    expect($captured)->toHaveCount(1);
    expect($captured[0]->pattern)->toBe('SHELL*PIETER*');
});

it('does not launch the system browser when name is empty (validation error)', function (): void {
    Livewire::test(SuggestMappingModal::class)
        ->dispatch('suggest-mapping:open', rawDescription: 'SHELL*PIETER*')
        ->set('name', '')
        ->call('submit')
        ->assertSet('submitError', 'Name is required.');

    expect($this->shell->openCalls)->toBe([]);
});

it('keeps the modal open and renders submitError when the configured Compare base targets a non-allow-listed host', function (): void {
    /** @var ConfigRepository $config */
    $config = $this->app->make(ConfigRepository::class);
    $config->set('community.github_compare_base', 'https://evil.example.com/compare/main');

    Livewire::test(SuggestMappingModal::class)
        ->dispatch('suggest-mapping:open', rawDescription: 'PATTERN-X')
        ->set('name', 'X Co')
        ->call('submit')
        ->assertSet('submitError', Lang::get('community::suggest.errors.browser_refused'));

    expect($this->shell->openCalls)->toBe([]);
});

// The refusal used to be printed verbatim, and it opens with the action's own
// class name and the URL it rejected.
it('never shows the reader what the refusing action said', function (): void {
    /** @var ConfigRepository $config */
    $config = $this->app->make(ConfigRepository::class);
    $config->set('community.github_compare_base', 'https://evil.example.com/compare/main');

    App::setLocale('nl');
    $dutch = Livewire::test(SuggestMappingModal::class)
        ->dispatch('suggest-mapping:open', rawDescription: 'PATTERN-X')
        ->set('name', 'X Co')
        ->call('submit')
        ->get('submitError');

    App::setLocale('en');
    $english = Livewire::test(SuggestMappingModal::class)
        ->dispatch('suggest-mapping:open', rawDescription: 'PATTERN-X')
        ->set('name', 'X Co')
        ->call('submit')
        ->get('submitError');

    expect($dutch)->not->toBe($english)
        ->and($dutch)->not->toContain('OpenExternalUrlAction')
        ->and($dutch)->not->toContain('evil.example.com');
});

// The contribution recorded here stands for a pull request the reader is about
// to open. A platform that did not take the URL means there is no such request,
// so counting one would credit them for work the app just failed to send them
// to — and before the opener answered at all, the phone never even got here.
it('records no contribution when the platform did not take the URL', function (): void {
    $refusing = new RecordingUrlOpener(takesTheUrl: false);
    $this->app->instance(ExternalUrlOpener::class, $refusing);

    $captured = [];
    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);
    $events->listen(MysteryMerchantSubmitted::class, static function (MysteryMerchantSubmitted $event) use (&$captured): void {
        $captured[] = $event;
    });

    Livewire::test(SuggestMappingModal::class)
        ->dispatch('suggest-mapping:open', rawDescription: 'SHELL*PIETER*')
        ->set('name', 'Shell Pieter')
        ->call('submit')
        ->assertNotDispatched('modal-close')
        ->assertSet('submitError', Lang::get('community::suggest.errors.browser_refused'));

    expect($refusing->openCalls)->toHaveCount(1)
        ->and($captured)->toBe([]);
});
