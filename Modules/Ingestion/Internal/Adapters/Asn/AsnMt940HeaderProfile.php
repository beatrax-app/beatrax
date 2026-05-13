<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Asn;

/**
 * MT940 (legacy SWIFT statement) profile for the ASN MT940 export. The
 * dialect is documented at `tests/fixtures/asn-mt940-sample-1.md` —
 * structured `:86:` narrative with `?NN` subfields and an extended
 * 34-character `:61:` customer reference.
 *
 * The file may arrive either as a bare MT940 body (starting with `:20:`)
 * or wrapped in a SWIFT block-1 envelope (`{1:...}{2:...}{4: ... -}`).
 * `SWIFT_ENVELOPE_REGEX` matches the block-4 contents in either form so
 * the sniffer + lexer can strip the wrapper before inspecting tags.
 */
final class AsnMt940HeaderProfile
{
    public const FORMAT = 'asn-mt940';

    /** @var list<string> */
    public const FILE_EXTENSIONS = ['sta', 'mt940', '940', 'txt'];

    /**
     * Matches the SWIFT block-4 envelope content. The terminator `-}` may
     * be separated by whitespace because exporters that emit the EOM `-`
     * marker on its own line then close the envelope on the next line.
     */
    public const SWIFT_ENVELOPE_REGEX = '/\{4:\s*([\s\S]+?)\s*-\s*\}/';

    /** Matches the `:20:` Transaction Reference Number tag — first tag of every statement. */
    public const SIGNATURE_REGEX = '/(?:^|[\r\n])\s*:20:/';

    public const SOURCE_ENCODING = 'UTF-8';
}
