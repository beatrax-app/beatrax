<?php

declare(strict_types=1);

namespace Modules\EmailScan\Public\Enums;

// Validated at every OAuth entry point — client picker, secrets store, state
// repository — so the pair is written down exactly once.
enum MailProvider: string
{
    case Gmail = 'gmail';

    case Microsoft = 'microsoft';
}
