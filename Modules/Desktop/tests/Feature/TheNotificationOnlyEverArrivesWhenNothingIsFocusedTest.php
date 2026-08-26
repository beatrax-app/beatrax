<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Modules\Desktop\Internal\Listeners\NavigateOnNotificationDeepLink;
use Modules\Desktop\Public\Events\NotificationDeepLink;

// An operating-system notification is only ever raised while the window is NOT
// focused — DispatchOsNotification checks exactly that before firing one. The
// click handler then asked the shell for the *focused* window, and the shell
// answers that question with BrowserWindow.getFocusedWindow().id, which is a
// read of null. So every notification click reached a 500 and navigated
// nowhere. The bundled window fake hands back a window whatever is focused,
// which is why nothing noticed.

/**
 * The body the shell really returns for GET window/current with nothing focused.
 */
function unfocusedWindowCurrent(): string
{
    return "<!DOCTYPE html><html><body><pre>TypeError: Cannot read properties of null (reading 'id')</pre></body></html>";
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    Http::fake([
        '*window/current*' => Http::response(unfocusedWindowCurrent(), 500, ['Content-Type' => 'text/html']),
        '*window/get/main*' => Http::response(['id' => 'main', 'title' => 'Beatrax', 'url' => 'http://127.0.0.1:8100/'], 200),
        '*window/url*' => Http::response('', 200),
        '*window/open*' => Http::response('', 200),
        '*window/show*' => Http::response('', 200),
    ]);
});

it('takes the notification to its screen with no window focused', function (): void {
    app(NavigateOnNotificationDeepLink::class)->handle(new NotificationDeepLink('/drift'));

    Http::assertSent(static function ($request): bool {
        return str_contains($request->url(), 'window/url')
            && $request['url'] === '/drift'
            && $request['id'] === 'main';
    });
});

it('brings the window back to the front, which is the whole point of the click', function (): void {
    app(NavigateOnNotificationDeepLink::class)->handle(new NotificationDeepLink('/drift'));

    Http::assertSent(static fn ($request): bool => str_contains($request->url(), 'window/open'));
});
