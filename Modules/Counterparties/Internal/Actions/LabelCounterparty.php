<?php

declare(strict_types=1);

namespace Modules\Counterparties\Internal\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Query\Builder;
use Modules\Counterparties\Internal\Enums\CounterpartyMetadataKey;
use Modules\Counterparties\Internal\Resolver\CounterpartySlugResolver;
use Modules\Counterparties\Models\Counterparty;
use Modules\Counterparties\Public\Enums\CounterpartyType;
use Modules\Counterparties\Public\Support\CounterpartyDefaultName;
use Modules\Sync\Public\Events\EntityMutated;
use Modules\Sync\Public\Services\SensitiveColumnCodec;

/**
 * @link ../../../../.docs/features/counterparties/triage-suggestions.md
 */
final readonly class LabelCounterparty
{
    public function __construct(
        private CounterpartySlugResolver $slugResolver,
        private SensitiveColumnCodec $codec,
        private Dispatcher $events,
    ) {}

    public function label(
        Counterparty $row,
        int $userId,
        CounterpartyType $type,
        string $displayName,
        ?string $merchantName,
        Session $session,
    ): void {
        // The slug follows the display name, so keeping the old one on a rename
        // leaves the next import to slugify the new name, find it free, and mint
        // a second row — the fragmentation slugIsFreeFor() exists to prevent.
        $fields = [
            'type' => $type->value,
            'slug' => $this->slugResolver->resolveUnique($userId, $displayName, $row->id),
            'display_name' => $displayName,
        ];

        if ($merchantName !== null) {
            $fields['merchant_name'] = $merchantName;
        }

        // These are the reader's own words now, so the flag marking the name as
        // the app's has to go with the name it described. Left behind, the next
        // read would translate their name back into a placeholder.
        $metadata = is_array($row->metadata) ? $row->metadata : [];
        if (CounterpartyDefaultName::tokenIn($metadata) !== null) {
            unset($metadata[CounterpartyMetadataKey::DefaultName->value]);
            $fields['metadata'] = $metadata === [] ? null : $metadata;
        }

        $row->forceFill($this->codec->encryptAttrs('counterparties', $fields, $userId, $session));
        $row->save();

        $this->announce($row->id, $userId, $fields);
    }

    public function ignore(Counterparty $row, int $userId): void
    {
        $metadata = is_array($row->metadata) ? $row->metadata : [];
        $metadata[CounterpartyMetadataKey::Ignored->value] = true;

        $row->metadata = $metadata;
        $row->save();

        $this->announce($row->id, $userId, ['metadata' => $metadata]);
    }

    // The triage queue selects on type='unknown' and nothing else, so without
    // this an ignored row is gone for the session and back on the next visit.
    public static function excludeIgnored(Builder $query): void
    {
        $column = CounterpartyMetadataKey::Ignored->column();

        $query->where(static function (Builder $nested) use ($column): void {
            $nested->whereNull($column)->orWhere($column, false);
        });
    }

    // Plaintext, like the resolver's own announcements: OpLogWriter seals the
    // sensitive columns again under the current key epoch, so handing it the
    // stored ciphertext would encrypt it twice and the peer would never read it.
    /**
     * @param  array<string, mixed>  $fields
     */
    private function announce(int $id, int $userId, array $fields): void
    {
        $this->events->dispatch(new EntityMutated(
            table: 'counterparties',
            pk: $id,
            userId: $userId,
            mutationType: 'edit',
            dirtyFields: $fields,
        ));
    }
}
