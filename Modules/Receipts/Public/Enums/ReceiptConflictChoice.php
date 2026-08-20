<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Enums;

// How a receipt-vs-stored-value conflict is resolved: PreferReceipt writes the
// receipt's value onto the transaction then deletes the conflict row;
// PreferFirstWrite keeps the stored value and just deletes the row.
enum ReceiptConflictChoice: string
{
    case PreferReceipt = 'prefer_receipt';

    case PreferFirstWrite = 'prefer_first_write';
}
