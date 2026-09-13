<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http;

use Carbon\CarbonImmutable;
use Illuminate\Routing\UrlGenerator;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\GenerateSignedUploadUrl;

// The iOS shell serves under `php://`, which no absolute signature survives:
// the verifier rebuilds the URL from a Request that can only say `http://`.
// Livewire 4.4 hashes the path alone, so the scheme is out of it and the shape
// handed back is the only thing left that differs per shell.
final class BridgeSignedUploadUrl extends GenerateSignedUploadUrl
{
    public function __construct(
        private readonly UrlGenerator $urls,
    ) {}

    public function forLocal(): string
    {
        // Livewire declares no return type here, so the value is mixed. Narrowed
        // rather than cast: a config file holding a string or null should fall
        // back to Livewire's own default, not be coerced into a nonsense expiry.
        $configured = FileUploadConfiguration::maxUploadTime();
        $minutes = is_int($configured) || is_float($configured) ? $configured : 5;

        $expiry = CarbonImmutable::now()->addMinutes($minutes);

        $relative = $this->urls->temporarySignedRoute('livewire.upload-file', $expiry, absolute: false);

        // The signature covers the path and query either way. What the shell
        // gets back does not: a WebView on php:// resolves a relative URL
        // against the origin it is running on, and re-absolutising it there
        // would hand back the `php://` root the browser cannot fetch.
        return $this->writesResolvableRoot() ? $this->urls->to($relative) : $relative;
    }

    private function writesResolvableRoot(): bool
    {
        $scheme = parse_url($this->urls->to('/'), PHP_URL_SCHEME);

        return $scheme === 'http' || $scheme === 'https';
    }
}
