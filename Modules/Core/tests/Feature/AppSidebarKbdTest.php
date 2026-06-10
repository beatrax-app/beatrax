<?php

declare(strict_types=1);

use Modules\Core\Models\User;

/*
 * D-04 no-hardcoded ⌘K glyph assertion (Wave 0 RED stub).
 *
 * This test will FAIL until Wave 2 (04-03) replaces the hardcoded
 * ⌘K glyph in app-sidebar.blade.php with a platform-aware Alpine
 * binding that shows ⌘K on Mac and Ctrl+K on other platforms.
 *
 * Assertions cover:
 *   - The authenticated sidebar layout does NOT contain a hardcoded ⌘K literal
 *   - The sidebar DOES contain the $store.platform.isMac Alpine binding marker
 *   - The sidebar DOES contain the Ctrl+K label (used on non-Mac platforms)
 */

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
