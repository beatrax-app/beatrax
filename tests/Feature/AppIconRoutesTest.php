<?php

declare(strict_types=1);

/*
 * On a phone there is no web server in front of Laravel — every request is
 * answered by PHP — so a file that only exists in public/ is a 404 unless a
 * route serves it.
 *
 * Measured on an iPhone: GET /icon.png returned 404 with the styled error page,
 * and the veil's <img> reported naturalWidth 0. GET /icons/icon-192.png did
 * worse: 200, Content-Length 23548, and 10 bytes of body — the PNG signature
 * and nothing after it, because a streamed BinaryFileResponse does not survive
 * the bridge. A header promising an image over a body that is not one.
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
