<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Exceptions;

use InvalidArgumentException;
use Modules\Core\Public\Support\MessageNamesNoUserData;
use Modules\Import\Internal\Enums\AliasFileRejection;
use Throwable;

// The reader's own YAML is refused with a reason the screen can translate; the
// message stays English because it is the log's copy, and it names the shape of
// the refusal and the position in the file, never a value read out of it.
final class AliasFileRejectedException extends InvalidArgumentException implements MessageNamesNoUserData
{
    private function __construct(
        public readonly AliasFileRejection $rejection,
        public readonly int $entry,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            'alias file rejected: '.$rejection->value.' at entry '.$entry,
            0,
            $previous,
        );
    }

    public static function file(AliasFileRejection $rejection, ?Throwable $previous = null): self
    {
        return new self($rejection, 0, $previous);
    }

    public static function entry(AliasFileRejection $rejection, int $position): self
    {
        return new self($rejection, $position);
    }

    public function sentence(): string
    {
        return $this->rejection->sentence($this->entry);
    }
}
