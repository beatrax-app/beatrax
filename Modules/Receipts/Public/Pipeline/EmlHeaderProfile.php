<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Pipeline;

// Format profile for a single RFC 822 .eml file, used by
// HeaderSniffer::sniff() to discriminate the upload from other
// formats. Lives in Public because HeaderSniffer sits in a sister
// module — Internal placement would violate the module-boundary rule.
final class EmlHeaderProfile
{
    public const FORMAT = 'eml';

    public const SOURCE_ENCODING = 'UTF-8';

    // Any one of these canonical RFC 822 header tokens appearing at
    // the start of a logical line in the first 8 KB is a sufficient
    // sniff signal. Case-sensitive — RFC 822 header names are
    // uppercase-first by definition.
    public const SIGNATURE_REGEX = '/(^|\r\n|\n)(Return-Path|Received|From|Message-ID):/m';
}
