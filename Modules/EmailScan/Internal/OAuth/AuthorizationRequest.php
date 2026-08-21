<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

final readonly class AuthorizationRequest
{
    public function __construct(
        public string $url,
        public string $pkceVerifier,
    ) {}
}
