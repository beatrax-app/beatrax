<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

// How long a stored OAuth `state` value stays acceptable. Ten minutes is long
// enough for a typical consent + SCA round-trip; anything older is treated as
// expired and rejected. Mailbox connect and bank connect each spelled it out,
// and only one of the two said why.
final class OAuthStateWindow
{
    public const int MAX_AGE_SECONDS = 600;
}
