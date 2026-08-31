<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Enums;

// A card statement and a wallet export carry no IBAN of the reader's own, so
// the adapter that reads one emits a sentinel in its place and every module
// downstream — the own-account prompt, the onboarding steps, the receipt
// matchers — has to recognise the same spelling. This is where it is spelled.
enum SyntheticIban: string
{
    case IcsCard = 'ICS-CARD';

    case Paypal = 'PAYPAL';

    case GooglePlay = 'GOOGLE-PLAY';
}
