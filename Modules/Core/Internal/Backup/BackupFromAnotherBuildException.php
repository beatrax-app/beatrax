<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Backup;

use RuntimeException;

// A backup whose schema is not the one this build reads. Two subclasses rather
// than a flag, because the reader is told different things: installing a newer
// build answers one of them and nothing answers the other.
abstract class BackupFromAnotherBuildException extends RuntimeException
{
    /**
     * @param  list<string>  $unmatched  the migrations the two do not share, named in the log and never to the reader
     */
    final public function __construct(string $message, public readonly array $unmatched)
    {
        parent::__construct($message);
    }

    /**
     * @param  list<string>  $ahead
     */
    public static function newer(array $ahead): self
    {
        return new BackupFromANewerBuildException(
            'The backup was taken on a build carrying '.count($ahead).' schema changes this one does not have.',
            $ahead,
        );
    }

    /**
     * @param  list<string>  $behind
     */
    public static function older(array $behind): self
    {
        return new BackupFromAnOlderBuildException(
            'The backup is missing '.count($behind).' schema changes this build has run.',
            $behind,
        );
    }
}
