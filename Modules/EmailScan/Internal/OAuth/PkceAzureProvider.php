<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

use TheNetworg\OAuth2\Client\Provider\Azure;

final class PkceAzureProvider extends Azure
{
    // The league base returns null from getPkceMethod, so PKCE stays off
    // unless a subclass opts in. Overriding it makes getAuthorizationUrl emit
    // the code_challenge and mint the verifier the callback later replays.
    protected function getPkceMethod(): string
    {
        return self::PKCE_METHOD_S256;
    }
}
