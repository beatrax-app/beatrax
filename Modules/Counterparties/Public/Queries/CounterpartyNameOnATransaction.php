<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Queries;

use Modules\Core\Public\Services\SessionFactory;
use Modules\Counterparties\Public\Support\CounterpartyDefaultName;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

// A description-only line names nobody, so its own column is null while the
// counterparty behind it always has a name — the reader's, or the app's word
// re-resolved into their language. Reading the transaction column alone drew
// an em dash over rows the counterparty screens name, one click apart.
/**
 * @link ../../../../.docs/features/counterparties/resolution-chain.md#the-apps-own-words-for-a-row-it-had-to-name
 */
final readonly class CounterpartyNameOnATransaction
{
    private const string DISPLAY_NAME = '_display_name';

    private const string METADATA = '_metadata';

    public function __construct(
        private SensitiveColumnCodec $codec,
        private SessionFactory $session,
    ) {}

    /**
     * @return list<string>
     */
    public static function columns(string $table = 'counterparties', string $alias = 'counterparty'): array
    {
        return [
            $table.'.display_name as '.$alias.self::DISPLAY_NAME,
            $table.'.metadata as '.$alias.self::METADATA,
        ];
    }

    public function fromRow(stdClass $row, ?string $ownName, int $userId, string $alias = 'counterparty'): ?string
    {
        return $this->resolve(
            $ownName,
            $row->{$alias.self::DISPLAY_NAME} ?? null,
            $row->{$alias.self::METADATA} ?? null,
            $userId,
        );
    }

    // The file's wording wins where there is one, so renaming a counterparty
    // never rewrites the rows whose own statement named them.
    public function resolve(?string $ownName, mixed $storedDisplayName, mixed $metadata, int $userId): ?string
    {
        if ($ownName !== null && trim($ownName) !== '') {
            return $ownName;
        }

        if (! is_string($storedDisplayName) || $storedDisplayName === '') {
            return null;
        }

        return CounterpartyDefaultName::resolve(
            $this->codec->decryptValue('counterparties', 'display_name', $storedDisplayName, $userId, ($this->session)())['value'],
            $metadata,
        );
    }
}
