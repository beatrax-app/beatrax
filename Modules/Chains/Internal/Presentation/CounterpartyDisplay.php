<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Presentation;

use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Services\SessionFactory;
use Modules\Sync\Public\Services\SensitiveColumnCodec;
use stdClass;

// Every chain read path renders the same two counterparty facts off a
// transactions row: the encrypted name, and the joined profile slug. Holding
// the codec and the select expression here keeps the drawer's walk and the
// review queue reading one spelling of both.
final readonly class CounterpartyDisplay
{
    use CoercesScalars;

    public const string SLUG_SELECT = 'counterparties.slug as counterparty_slug';

    public function __construct(
        private SensitiveColumnCodec $codec,
        // A factory, not the session: resolving one builds the encrypter, and
        // Artisan constructs the reading services merely to list a command.
        private SessionFactory $session,
    ) {}

    public function name(?string $stored, int $userId): string
    {
        $raw = $stored ?? '';
        if ($raw === '') {
            return '';
        }

        return $this->codec->decryptValue('transactions', 'counterparty_name', $raw, $userId, ($this->session)())['value'];
    }

    public function slug(stdClass $row): ?string
    {
        if (! property_exists($row, 'counterparty_slug') || $row->counterparty_slug === null) {
            return null;
        }
        $slug = self::toString($row->counterparty_slug);

        return $slug === '' ? null : $slug;
    }
}
