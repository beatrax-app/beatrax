<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Exceptions;

use RuntimeException;

// Internal because the only method that throws it, UnicodeFolding::registerOn(),
// is pinned to one caller: the provider beside this file. The connection name
// is a property and not only a sentence, because which connection came up short
// is the one question anything catching this has.
/**
 * @link ../../../../.docs/architecture/case-folding-is-one-function.md
 */
final class UnicodeFoldingUnavailableException extends RuntimeException
{
    private function __construct(public readonly string $connectionName, string $message)
    {
        parent::__construct($message);
    }

    // Laravel builds its PDO with PDO::connect() from PHP 8.4 and `new PDO`
    // below it, and only the first hands back the subclass carrying
    // createFunction(). A connection opened outside the framework is the other
    // way to arrive here.
    public static function notASqlitePdo(string $connectionName, string $pdoClass, string $function): self
    {
        return new self($connectionName, sprintf(
            'Connection [%s] holds a %s rather than a Pdo\Sqlite, so the case fold %s() cannot be registered on it. '
            .'Every folded search would fail on this connection.',
            $connectionName,
            $pdoClass,
            $function,
        ));
    }

    public static function driverRefused(string $connectionName, string $function): self
    {
        return new self($connectionName, sprintf(
            'SQLite refused to register the case fold %s() on connection [%s].',
            $function,
            $connectionName,
        ));
    }
}
