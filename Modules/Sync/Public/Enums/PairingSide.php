<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Enums;

// Which half of a pairing handshake a device owns. The backing values are the
// `pairing_tokens` column prefixes and they cross between devices inside the
// row a seeded responder writes, so they are storage values, never labels.
enum PairingSide: string
{
    case Initiator = 'initiator';

    case Responder = 'responder';

    // Every peer-facing mapping below routes through this one flip, so the
    // column a device stamps and the column it reads the peer's stamp from
    // cannot come out as the same name.
    public function peer(): self
    {
        return match ($this) {
            self::Initiator => self::Responder,
            self::Responder => self::Initiator,
        };
    }

    public function columnPrefix(): string
    {
        return $this->value.'_';
    }

    public function peerPrefix(): string
    {
        return $this->peer()->columnPrefix();
    }

    public function confirmedAtColumn(): string
    {
        return $this->columnPrefix().'confirmed_at';
    }

    public function peerConfirmedAtColumn(): string
    {
        return $this->peer()->confirmedAtColumn();
    }
}
