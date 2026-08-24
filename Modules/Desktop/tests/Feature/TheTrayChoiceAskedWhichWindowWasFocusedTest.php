<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Modules\Desktop\Internal\Listeners\ApplyCloseWindowChoice;
use Modules\Desktop\Internal\Native\WindowCloseBehavior;

// The tray answer hid "the current window", and the shell resolves that by
// reading BrowserWindow.getFocusedWindow().id. A close arriving from the tray
// menu, or from a window that has already given up focus, reads null there and
// the whole POST 500s — silently, because the layout's JS hook ignores the
// response. The app then neither quits nor hides, and the click did nothing.

function unfocusedCurrentWindowBody(): string
{
    return "<!DOCTYPE html><html><body><pre>TypeError: Cannot read properties of null (reading 'id')</pre></body></html>";
}

beforeEach(function (): void {
    Http::preventStrayRequests();
    Http::fake([
        '*window/current*' => Http::response(unfocusedCurrentWindowBody(), 500, ['Content-Type' => 'text/html']),
        '*window/hide*' => Http::response('', 200),
    ]);
});

it('hides the window the app owns rather than the one the OS happens to be showing', function (): void {
    app(ApplyCloseWindowChoice::class)->apply(WindowCloseBehavior::CHOICE_TRAY);

    Http::assertSent(static fn ($request): bool => str_contains($request->url(), 'window/hide')
        && $request['id'] === 'main');
});
