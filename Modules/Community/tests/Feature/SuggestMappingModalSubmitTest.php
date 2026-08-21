<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Events\Dispatcher;
use Livewire\Livewire;
use Modules\Community\Internal\Http\Livewire\SuggestMappingModal;
use Modules\Community\Public\Events\MysteryMerchantSubmitted;
use Native\Desktop\Contracts\Shell as ShellContract;
use Native\Desktop\Fakes\ShellFake;

beforeEach(function (): void {
    $this->user = makeCommunityTestUser('suggest-modal-user');
    $this->actingAs($this->user);

    $this->shell = new ShellFake;
    $this->app->instance(ShellContract::class, $this->shell);
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

    expect($this->shell->openExternalCalls)->not->toBeEmpty();
    $url = $this->shell->openExternalCalls[0];
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

    expect($this->shell->openExternalCalls)->toBe([]);
});

it('keeps the modal open and renders submitError when the configured Compare base targets a non-allow-listed host', function (): void {
    /** @var ConfigRepository $config */
    $config = $this->app->make(ConfigRepository::class);
    $config->set('community.github_compare_base', 'https://evil.example.com/compare/main');

    Livewire::test(SuggestMappingModal::class)
        ->dispatch('suggest-mapping:open', rawDescription: 'PATTERN-X')
        ->set('name', 'X Co')
        ->call('submit');

    expect($this->shell->openExternalCalls)->toBe([]);
});
