<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Enums;

// The keys the statement parsers write into StatementSummaryData::$extras.
// MT940 and CAMT.053 both answer the multi-statement question, and spelling
// the key out at each site is how they came to answer it differently.
enum StatementExtraKey: string
{
    case StatementId = 'statementId';

    case MultiStatement = 'multiStatement';

    case ClosingBalanceUnreadable = 'closingBalanceUnreadable';

    case CreatedOn = 'createdOn';

    // Present only on a statement whose own figures do not add up, and never
    // on one that was never checkable: opening and closing are both on the
    // summary, so an absent key beside two present balances is a statement
    // that balanced.
    case StatementDifference = 'statementDifferenceMinor';
}
