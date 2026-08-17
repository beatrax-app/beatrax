<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

/**
 * @link ../../../../.docs/features/email-scan/architecture.md
 */
final readonly class AuthorizationRequest
{
    public function __construct(
        public string $url,
        public string $pkceVerifier,
    ) {}
}
