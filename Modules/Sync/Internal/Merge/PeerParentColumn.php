<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

// Where one id crossed: the local row it was written onto, the column it was
// written into, and the peer's own coordinates for both. Carried as a value
// rather than as six loose strings, because the repair has to hand the peer's
// id and the parent table back to `PeerRowAliases` unchanged.
final readonly class PeerParentColumn
{
    public function __construct(
        public string $table,
        public string $localId,
        public string $column,
        public string $parentTable,
        public string $deviceId,
        public string $peerPk,
        public string $peerValue,
    ) {}

    public function where(): string
    {
        return $this->table.'#'.$this->localId;
    }

    public function columnPath(): string
    {
        return $this->table.'.'.$this->column;
    }
}
