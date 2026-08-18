<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Routing\Router;
use Illuminate\Routing\UrlGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\GenerateSignedUploadUrl;

// The iOS shell serves under `php://`, so signedRoute() hashes a `php://` URL
// while hasValidSignature() rebuilds it from Symfony's Request::url(), which
// can only say `http://`. The two halves hashed different strings and the
// upload endpoint answered 401 for every statement.
/**
 * @link ../../../../.docs/features/mobile/architecture.md
 */
final class BridgeSignedUploadUrl extends GenerateSignedUploadUrl
{
    public function __construct(
        private readonly UrlGenerator $urls,
        private readonly Router $router,
        private readonly Config $config,
    ) {}

    public function forLocal(): string
    {
        // Livewire declares no return type here, so the value is mixed. Narrowed
        // rather than cast: a config file holding a string or null should fall
        // back to Livewire's own default, not be coerced into a nonsense expiry.
        $configured = FileUploadConfiguration::maxUploadTime();
        $minutes = is_int($configured) || is_float($configured) ? $configured : 5;

        $expiry = Carbon::now()->addMinutes($minutes);

        // Everywhere the app's own generator writes a root the verifier can
        // rebuild, the ordinary absolute URL already verifies. Keyed on the
        // scheme in use rather than on the platform, so this stops applying by
        // itself the day the shell stops rewriting the scheme.
        if ($this->writesVerifiableRoot()) {
            return $this->urls->temporarySignedRoute('livewire.upload-file', $expiry);
        }

        // Signed through a plain generator, whose root is the one the incoming
        // request will present, and handed back relative so the WebView still
        // resolves it against the php:// origin it is running on. The browser
        // and the verifier need different halves of the same URL.
        $request = $this->urls->getRequest();

        $plain = new UrlGenerator($this->router->getRoutes(), $request);
        $plain->setKeyResolver(fn (): array => $this->signingKeys());

        return Str::after(
            $plain->temporarySignedRoute('livewire.upload-file', $expiry),
            $request->root(),
        );
    }

    private function writesVerifiableRoot(): bool
    {
        $scheme = parse_url($this->urls->to('/'), PHP_URL_SCHEME);

        return $scheme === 'http' || $scheme === 'https';
    }

    // Mirrors the resolver Laravel's own RoutingServiceProvider installs, so a
    // rotated key and its predecessors verify here exactly as they do on the
    // routes that were signed by the container's generator.
    /**
     * @return list<string>
     */
    private function signingKeys(): array
    {
        $key = $this->config->get('app.key');
        $previous = $this->config->get('app.previous_keys');

        return [
            is_string($key) ? $key : '',
            ...(is_array($previous) ? array_values(array_filter($previous, 'is_string')) : []),
        ];
    }
}
