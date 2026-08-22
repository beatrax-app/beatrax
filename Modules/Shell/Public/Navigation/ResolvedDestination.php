<?php

declare(strict_types=1);

namespace Modules\Shell\Public\Navigation;

use Modules\Core\Public\Navigation\Destination;

// A Destination resolved against the route table and the active locale: what a
// surface needs to actually draw the row. `keywords` widen a search past the
// label — the way "receipts" or "senders" reaches the Email screen.
final readonly class ResolvedDestination
{
    /**
     * @param  list<string>  $keywords
     */
    public function __construct(
        public Destination $id,
        public string $label,
        public string $hint,
        public string $icon,
        public string $path,
        public array $keywords,
    ) {}
}
