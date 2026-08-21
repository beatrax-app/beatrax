<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

use League\OAuth2\Client\Provider\Google;

final class PkceGoogleProvider extends Google
{
    // The league base returns null from getPkceMethod, so PKCE stays off
    // unless a subclass opts in like this.
    protected function getPkceMethod(): string
    {
        return self::PKCE_METHOD_S256;
    }
}
