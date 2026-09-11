<?php

declare(strict_types=1);

namespace Modules\Ledger\Database\Seeders\Demo;

// The demo rows a later seeder has to find again. transactions.description is
// sealed, so a literal match on it finds nothing for a reader who has enabled
// encryption; source_ref is plaintext by decision and carries the tag instead.
/**
 * @link ../../../../../.docs/features/sync/sensitive-columns-at-rest.md#the-sample-dataset
 */
enum DemoTransactionRef: string
{
    case PaypalTopUp = 'paypal-top-up';

    case PaypalTopUpArrival = 'paypal-top-up-arrival';

    case IcsSettlementBankSide = 'ics-settlement-bank-side';

    case IcsSettlementCardSide = 'ics-settlement-card-side';

    case BolViaPaypal = 'bol-via-paypal';

    case BolOnCard = 'bol-on-card';

    case Coolblue = 'coolblue';

    case BolRefund = 'bol-refund';

    case CoolblueRefund = 'coolblue-refund';

    case MediaMarkt = 'mediamarkt';

    case Hema = 'hema';

    case JrEast = 'jr-east';

    case Kpn = 'kpn';

    case ZilverenKruis = 'zilveren-kruis';

    private const string PREFIX = 'DEMO';

    private const string TAG_END = '#';

    public static function plain(int $userId, int $accountId, int $rowIndex): string
    {
        return self::PREFIX.'-'.$userId.'-'.$accountId.'-'.$rowIndex;
    }

    // TAG_END closes the tag, so no tag is a prefix of another and the LIKE
    // below cannot answer for a neighbouring one.
    public function tagged(int $userId, int $accountId, int $rowIndex): string
    {
        return self::PREFIX.'-'.$this->value.self::TAG_END.$userId.'-'.$accountId.'-'.$rowIndex;
    }

    public function pattern(): string
    {
        return self::PREFIX.'-'.$this->value.self::TAG_END.'%';
    }
}
