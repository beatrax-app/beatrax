<?php

declare(strict_types=1);

namespace Modules\Counterparties\Internal\Enums;

// The metadata keys something reads back, as opposed to the opaque provenance
// the resolver only ever writes. A key spelled twice is a key that drifts: the
// triage queue's hidden-row predicate and the profile page's fee branch each
// have a second site that has to agree with the write.
enum CounterpartyMetadataKey: string
{
    case Ignored = 'ignored';

    case Subcategory = 'subcategory';

    case DefaultName = 'default_name';

    // The JSON path a query builder addresses the key by, so a SQL predicate
    // and an in-PHP read of the same flag cannot spell it differently.
    public function column(): string
    {
        return 'metadata->'.$this->value;
    }
}
