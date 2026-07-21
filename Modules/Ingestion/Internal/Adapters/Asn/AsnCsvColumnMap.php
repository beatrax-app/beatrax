<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Asn;

/**
 * @link ../../../../../.docs/features/ingestion/architecture.md
 */
final class AsnCsvColumnMap
{
    public const POSTED_DATE = 0;

    public const OWN_IBAN = 1;

    public const COUNTERPARTY_IBAN = 2;

    public const COUNTERPARTY_NAME = 3;

    public const COUNTERPARTY_ADDR = 4;

    public const COUNTERPARTY_POSTAL = 5;

    public const COUNTERPARTY_CITY = 6;

    public const ACCOUNT_CURRENCY = 7;

    public const SALDO_BEFORE = 8;

    public const MUTATION_CURRENCY = 9;

    public const AMOUNT = 10;

    public const JOURNAL_DATE = 11;

    public const VALUE_DATE = 12;

    public const INTERNAL_TX_CODE = 13;

    public const GLOBAL_TX_CODE = 14;

    public const SEQUENCE_NUMBER = 15;

    public const PAYMENT_REF = 16;

    public const DESCRIPTION = 17;

    public const STATEMENT_NUMBER = 18;

    public const CATEGORY = 19;
}
