<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Pipeline;

/**
 * Format profile for a single RFC 822 `.eml` file dropped via the
 * upload wizard's "Email file" arm.
 *
 * Used by `Modules\Ingestion\Public\Services\HeaderSniffer::sniff()`
 * to discriminate the upload from other formats (CSV, XML, MT940,
 * PDF). Lives in the Receipts Public surface because `HeaderSniffer`
 * sits in a sister module — Internal placement would violate the
 * `Modules\Receipts\Internal is only used inside Modules\Receipts`
 * arch invariant.
 *
 * The discriminator regex targets the standard RFC 822 header set
 * that any well-formed message body will start with —
 * `Return-Path:`, `Received:`, `From:`, or `Message-ID:`. Any one
 * of these in the first 8 KB is a sufficient sniff signal.
 */
final class EmlHeaderProfile
{
    public const FORMAT = 'eml';

    public const SOURCE_ENCODING = 'UTF-8';

    /**
     * Discriminator: any one of the canonical RFC 822 header tokens
     * appearing at the start of a logical line in the message head
     * counts as a hit. Case-sensitive — RFC 822 header names are
     * defined uppercase-first.
     */
    public const SIGNATURE_REGEX = '/(^|\r\n|\n)(Return-Path|Received|From|Message-ID):/m';
}
