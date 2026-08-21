<?php

declare(strict_types=1);

use Modules\Core\Models\User;

beforeEach(function (): void {
    $this->user = User::query()->create([
        'username' => 'pwa-sidebar-kbd-fixture',
        'password' => 'fixture-password-12chars',
        'period_start_day' => 1,
    ]);
});

it('the authenticated sidebar does not contain a hardcoded ⌘K glyph', function (): void {
    $response = $this->actingAs($this->user)->get('/help/data-locations');
    $response->assertOk();
    $html = (string) $response->getContent();
    expect($html)->not->toContain('⌘K');
});

it('the authenticated sidebar contains the $store.platform.isMac Alpine binding', function (): void {
    $response = $this->actingAs($this->user)->get('/help/data-locations');
    $response->assertOk();
    $html = (string) $response->getContent();
    expect($html)->toContain('$store.platform.isMac');
});

it('the authenticated sidebar contains the Ctrl+K label for non-Mac platforms', function (): void {
    $response = $this->actingAs($this->user)->get('/help/data-locations');
    $response->assertOk();
    $html = (string) $response->getContent();
    expect($html)->toContain('Ctrl');
});

it('the authenticated sidebar does not contain a hardcoded ⌘. dev-console glyph', function (): void {
    $response = $this->actingAs($this->user)->get('/help/data-locations');
    $response->assertOk();
    $html = (string) $response->getContent();
    expect($html)->not->toContain('⌘.');
});
