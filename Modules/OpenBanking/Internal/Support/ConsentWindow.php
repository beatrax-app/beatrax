<?php

declare(strict_types=1);

namespace Modules\OpenBanking\Internal\Support;

// The consent lifetime this app asks the ASPSP for, and the expiry it stores
// once the bank grants it. Asking for one span and recording another leaves a
// connection that looks live locally after the bank has already revoked it,
// so both controllers read the number from here.
final class ConsentWindow
{
    public const int VALID_FOR_DAYS = 180;
}
