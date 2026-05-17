<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Pipeline;

/**
 * Format profile for an `.mbox` archive dropped via the upload
 * wizard's "Email file" arm.
 *
 * Used by `Modules\Ingestion\Public\Services\HeaderSniffer::sniff()`
 * to discriminate a mbox archive from a single-message `.eml`. The
 * mboxrd spec requires every message to be preceded by a literal
 * `From ` (note the trailing space) at the start of a line; the
 * first byte of a valid mbox is therefore always `From `.
 *
 * Lives in the Receipts Public surface because `HeaderSniffer`
 * sits in a sister module — Internal placement would violate the
 * `Modules\Receipts\Internal is only used inside Modules\Receipts`
 * arch invariant.
 */
final class MboxHeaderProfile
{
    public const FORMAT = 'mbox';

    public const SOURCE_ENCODING = 'UTF-8';

    /** First five bytes of every mboxrd archive — `From ` literal. */
    public const MBOX_PREFIX = 'From ';
}
