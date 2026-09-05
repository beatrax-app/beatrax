<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Modules\Community\Public\Services\ClassificationRuleProvider;
use Modules\Counterparties\Internal\Enums\CounterpartyMetadataKey;
use Modules\Counterparties\Internal\Enums\CounterpartySubcategory;
use Modules\Counterparties\Internal\Resolver\CounterpartySlugResolver;
use Modules\Counterparties\Public\Enums\CounterpartyType;

// The bank-fee corpus names a fee in the jurisdiction's language and the
// resolver wrote that word straight into `display_name`, with nothing beside it
// saying whose words they were. Every screen has read it back ever since, so a
// reader in any of the other twenty-five languages has been shown "Bankkosten"
// or "Χρεωστικοί τόκοι" with no key to fall back from. The corpus now declares
// the KIND of charge each word names, and that kind is the provenance token
// `CounterpartyDefaultName` already resolves for the three rows the resolver
// names itself.
//
// The backfill does not assume, and it cannot read the name it wants to check:
// `display_name` is sealed at rest and a migration holds no key. It asks the
// slug instead — the registry records it as the readable shadow of that column,
// derived from the same name — and marks a row only where the slug is still
// exactly what the corpus's own word slugifies to. A reader who renamed the row
// slugged it to something else and keeps their name in every language.
return new class extends Migration
{
    public function up(): void
    {
        $kinds = $this->kindBySlug();
        if ($kinds === []) {
            return;
        }

        $rows = DB::table('counterparties')
            ->where('type', CounterpartyType::Bank->value)
            ->where(CounterpartyMetadataKey::Subcategory->column(), CounterpartySubcategory::Fee->value)
            ->whereNull(CounterpartyMetadataKey::DefaultName->column())
            ->get(['id', 'slug', 'metadata']);

        foreach ($rows as $row) {
            $kind = $kinds[is_string($row->slug) ? $row->slug : ''] ?? null;
            if ($kind === null) {
                continue;
            }

            $metadata = $this->metadataOf($row->metadata);
            $metadata[CounterpartyMetadataKey::DefaultName->value] = $kind;

            DB::table('counterparties')
                ->where('id', $row->id)
                ->update(['metadata' => json_encode($metadata)]);
        }
    }

    // Nothing to put back: the flag says the wording is the corpus's, which was
    // already true of every row this touched. Dropping it would only return
    // those rows to answering in one language.
    public function down(): void {}

    // Every region's rules at once, because the row does not record which
    // country's file named it and the reader's country may have changed since.
    // A slug two countries disagree about is left alone rather than guessed.
    /** @return array<string, string> slug => the one kind the corpus gives it */
    private function kindBySlug(): array
    {
        $seen = [];
        foreach (app(ClassificationRuleProvider::class)->bankFeeRules() as $rule) {
            if ($rule->name === null || $rule->key === null) {
                continue;
            }
            $seen[CounterpartySlugResolver::slugify($rule->name)][$rule->key] = true;
        }

        return array_map(
            static fn (array $kinds): string => (string) array_key_first($kinds),
            array_filter($seen, static fn (array $kinds): bool => count($kinds) === 1),
        );
    }

    /** @return array<string, mixed> */
    private function metadataOf(mixed $stored): array
    {
        $decoded = is_string($stored) ? json_decode($stored, true) : $stored;

        return is_array($decoded) ? $decoded : [];
    }
};
