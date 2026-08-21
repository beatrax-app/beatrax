<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport\Discovery;

// The record types an mDNS answer can carry that this module acts on. Backed
// by the numbers on the wire (RFC 1035 §3.2.2, and RFC 2782 for SRV), so a
// parser can name what it matched rather than comparing bare integers.
enum DnsRecordType: int
{
    case Address = 1;

    case Pointer = 12;

    case Text = 16;

    case Service = 33;
}
