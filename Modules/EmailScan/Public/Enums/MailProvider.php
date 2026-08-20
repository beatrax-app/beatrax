<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Enums;

// The two mail providers an inbox can connect through. Persisted as the
// `provider` column and validated at every OAuth entry point — the client
// picker, the secrets store, the state repository — so the pair lives once.
enum MailProvider: string
{
    case Gmail = 'gmail';

    case Microsoft = 'microsoft';
}
