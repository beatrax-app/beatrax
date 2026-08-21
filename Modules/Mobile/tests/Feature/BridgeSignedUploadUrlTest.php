<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Facades\URL;
use Livewire\Facades\GenerateSignedUploadUrlFacade;
use Modules\Mobile\Internal\Http\BridgeSignedUploadUrl;

function phpSchemeGenerator(Request $request): UrlGenerator
{
    $generator = new class(app('router')->getRoutes(), $request) extends UrlGenerator
    {
        public function formatScheme($secure = null): string
        {
            return 'php://';
        }
    };

    $generator->setKeyResolver(fn (): array => [config('app.key')]);

    return $generator;
}

// The iOS shell's URL generator answers `php://` for every absolute URL it
// writes, including the string Laravel signs, while hasValidSignature() rebuilds
// the URL from Request::url(), which can only say `http://`. The two halves
// hashed different strings and every upload on the device was rejected 401.

it('signs an upload URL a php:// shell can actually get verified', function (): void {
    $request = Request::create('http://127.0.0.1/imports/new');

    $subject = new BridgeSignedUploadUrl(
        phpSchemeGenerator($request),
        app('router'),
        app('config'),
    );

    $url = $subject->forLocal();

    // Relative, so the WebView resolves it against the php:// origin it is on
    // while the signature underneath belongs to the http:// root PHP rebuilds.
    expect($url)->toStartWith('/')
        ->and($url)->toContain('signature=');

    expect(URL::hasValidSignature(Request::create('http://127.0.0.1'.$url)))->toBeTrue();
});

it('leaves the ordinary absolute URL alone where the root already verifies', function (): void {
    $subject = new BridgeSignedUploadUrl(
        app('url'),
        app('router'),
        app('config'),
    );

    $url = $subject->forLocal();

    expect($url)->toStartWith(url('/'))
        ->and(URL::hasValidSignature(Request::create($url)))->toBeTrue();
});

it('is what Livewire asks for the URL', function (): void {
    expect(GenerateSignedUploadUrlFacade::getFacadeRoot())->toBeInstanceOf(BridgeSignedUploadUrl::class);
});
