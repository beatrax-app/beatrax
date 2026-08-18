<?php

declare(strict_types=1);

use Illuminate\Contracts\View\Factory as ViewFactory;
use Modules\Mobile\Internal\Http\Middleware\EncodedUploadTransport;

/*
 * Which runtime encodes its uploads is decided by the server and read by the
 * client, so the encoder and the decoder cannot drift apart.
 *
 * The client used to decide alone, from `location.protocol`, treating http as
 * proof that multipart worked. That read Android's real http://127.0.0.1 as
 * safe, and it is not: on a device, Livewire's multipart POST returns 200 with
 * `{"paths":[]}` — PHP parsed no file — and the component then dies on
 * `Undefined array key 0` inside WithFileUploads. Two runtimes, one fault, and
 * the scheme did not tell them apart.
 *
 * The narrowness matters as much as the signal. Modules are auto-discovered,
 * so the Mobile provider boots in the desktop root as well; a flag shared
 * merely because the provider loaded would move every desktop and web upload
 * onto the encoded path.
 */

it('does not put a desktop or web client on the encoded path', function (): void {
    // This suite runs from the repo root, which is not the mobile runtime.
    expect(app(ViewFactory::class)->getShared())->not->toHaveKey('beatraxEncodedUploads');

    $html = view('layouts.app', ['title' => 'Test'])->render();

    expect($html)->not->toContain('beatrax-upload-transport');
});

it('keeps the decoding middleware registered even there, because it is inert without the marker', function (): void {
    $middleware = app('router')->getMiddlewareGroups()['web'] ?? [];

    expect($middleware)->toContain(EncodedUploadTransport::class);
});

it('emits the signal the client reads once the flag is set', function (): void {
    app(ViewFactory::class)->share('beatraxEncodedUploads', true);

    $html = view('layouts.app', ['title' => 'Test'])->render();

    expect($html)->toContain('name="beatrax-upload-transport"')
        ->and($html)->toContain('content="base64"');
});
