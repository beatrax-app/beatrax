<?php

declare(strict_types=1);

namespace Modules\Core\Public\Exceptions;

use RuntimeException;

// The markup could not be read to the end: a start tag with no `>`, an
// attribute value with no closing quote, or a document the parser turned into
// nothing. Raised rather than answered with an empty element list, because a
// guard that cannot tell those apart reports a clean tree it never read.
/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-guard-that-reads-html-with-a-regex
 */
final class MarkupParseFailedException extends RuntimeException
{
    public function __construct(public readonly string $reason, string $excerpt)
    {
        parent::__construct(
            'The markup stopped reading before it finished: '.$reason.
            ($excerpt === '' ? '' : ' — at `'.$excerpt.'`').
            '. Markup that could not be read is not markup with nothing in it.',
        );
    }
}
