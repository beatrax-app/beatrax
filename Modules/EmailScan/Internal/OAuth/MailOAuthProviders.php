<?php

declare(strict_types=1);

namespace Modules\EmailScan\Internal\OAuth;

use Modules\EmailScan\Public\Enums\MailProvider;

final readonly class MailOAuthProviders
{
    public function __construct(
        private GoogleOAuthProvider $google,
        private MicrosoftOAuthProvider $microsoft,
    ) {}

    public function for(MailProvider $provider): GoogleOAuthProvider|MicrosoftOAuthProvider
    {
        return match ($provider) {
            MailProvider::Gmail => $this->google,
            MailProvider::Microsoft => $this->microsoft,
        };
    }
}
