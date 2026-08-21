<?php

declare(strict_types=1);

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-public-file-with-no-route-behind-it
 */
it('serves the app mark whole, not as a promise of one', function (string $uri): void {
    $response = test()->get($uri);

    $response->assertOk();

    $body = $response->getContent();

    expect($body)->toBeString()
        ->and(substr((string) $body, 0, 8))->toBe("\x89PNG\r\n\x1a\n")
        ->and(strlen((string) $body))->toBeGreaterThan(1000);

    // The bug was a Content-Length that did not match what arrived.
    expect((int) $response->headers->get('Content-Length'))->toBe(strlen((string) $body));
})->with([
    '/icon.png',
    '/splash.png',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    '/icons/icon-512-maskable.png',
    '/icons/apple-touch-icon.png',
]);

it('declares them as images', function (): void {
    expect(test()->get('/icon.png')->headers->get('Content-Type'))->toContain('image/png');
});

it('still refuses a path outside the icon allow-list', function (): void {
    test()->get('/icons/../.env')->assertNotFound();
    test()->get('/icons/anything-else.png')->assertNotFound();
});
