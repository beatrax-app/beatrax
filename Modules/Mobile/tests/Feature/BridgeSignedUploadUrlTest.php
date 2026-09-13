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

// The check is the one FileUploadController performs, resolved from the URL
// rather than restated here. An earlier version of this file asserted
// hasValidSignature() — the absolute check — and stayed green through the
// Livewire 4.4 upgrade that moved the controller to the relative one, while
// every upload on every shell would have been rejected 401.
function uploadUrlVerifiesLikeTheController(string $url): bool
{
    $path = (string) parse_url($url, PHP_URL_PATH);
    $query = (string) parse_url($url, PHP_URL_QUERY);

    return URL::hasValidRelativeSignature(Request::create('http://127.0.0.1'.$path.'?'.$query));
}

// The iOS shell's URL generator answers `php://` for every absolute URL it
// writes, including the string Laravel signs, while the verifier rebuilds the
// URL from the incoming Request, which can only say `http://`.

it('signs an upload URL a php:// shell can actually get verified', function (): void {
    $request = Request::create('http://127.0.0.1/imports/new');

    $subject = new BridgeSignedUploadUrl(phpSchemeGenerator($request));

    $url = $subject->forLocal();

    // Relative, so the WebView resolves it against the php:// origin it is on.
    expect($url)->toStartWith('/')
        ->and($url)->toContain('signature=')
        ->and(uploadUrlVerifiesLikeTheController($url))->toBeTrue();
});

it('leaves the ordinary absolute URL alone where the root already verifies', function (): void {
    $subject = new BridgeSignedUploadUrl(app('url'));

    $url = $subject->forLocal();

    expect($url)->toStartWith(url('/'))
        ->and(uploadUrlVerifiesLikeTheController($url))->toBeTrue();
});

it('is what Livewire asks for the URL', function (): void {
    expect(GenerateSignedUploadUrlFacade::getFacadeRoot())->toBeInstanceOf(BridgeSignedUploadUrl::class);
});
