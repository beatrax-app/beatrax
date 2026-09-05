<?php

declare(strict_types=1);

namespace Modules\Tax\Internal\Actions;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;
use Modules\Tax\Internal\Corpus\TaxCorpusLoader;
use Modules\Tax\Internal\Enums\TaxCategoryStatus;
use Modules\Tax\Internal\Exceptions\CategoryPersistenceException;
use Modules\Tax\Internal\Exceptions\DuplicateTaxCategoryNameException;
use Modules\Tax\Internal\Support\TaxCorpusWording;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class TaxCategoryStore
{
    public function __construct(
        private DatabaseManager $db,
        private TaxCorpusLoader $corpusLoader,
    ) {}

    /**
     * @return int Number of rows actually inserted (0 when already seeded).
     */
    public function seedFromCorpus(User $user, string $countryCode): int
    {
        $entries = $this->corpusLoader->loadForCountry($countryCode);
        if ($entries === []) {
            return 0;
        }

        $connection = $this->db->connection();
        $now = Carbon::now()->toDateTimeString();

        // Continuing past the user's existing categories keeps a second country's
        // block together instead of interleaving it with the first country's.
        $maxOrder = $connection->table('tax_deduction_categories')
            ->where('user_id', $user->id)
            ->max('sort_order');
        $sortBase = is_numeric($maxOrder) ? (int) $maxOrder + 1 : 0;

        $inserted = 0;
        foreach ($entries as $entry) {
            /** @var array<int|string, mixed> $entry */
            $didInsert = $this->seedEntry(
                $connection,
                $user->id,
                $countryCode,
                $entry,
                $now,
                $sortBase + $inserted,
            );

            if ($didInsert) {
                $inserted++;
            }
        }

        return $inserted;
    }

    /**
     * @param  array<int|string, mixed>  $entry
     * @return bool Whether a row was actually inserted for this entry.
     */
    private function seedEntry(
        ConnectionInterface $connection,
        int $userId,
        string $countryCode,
        array $entry,
        string $now,
        int $sortOrder,
    ): bool {
        $key = self::stringField($entry, 'key');
        $name = self::stringField($entry, 'name');
        if ($key === '' || $name === '') {
            return false;
        }

        // Insert-only: re-seeding a corpus_key would undo a rename, and a name the
        // user already holds breaks unique(user_id, name), which 500'd the
        // settings and wizard country pickers.
        if ($this->userAlreadyHas($connection, $userId, $key, $name)) {
            return false;
        }

        $connection->table('tax_deduction_categories')->insert([
            'user_id' => $userId,
            'name' => $name,
            'short_name' => self::nullableStringField($entry, 'short_name'),
            'hint' => self::nullableStringField($entry, 'hint'),
            'corpus_key' => $key,
            'country_code' => $countryCode,
            'name_is_default' => true,
            'status' => TaxCategoryStatus::Active->value,
            'sort_order' => $sortOrder,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return true;
    }

    private function userAlreadyHas(ConnectionInterface $connection, int $userId, string $key, string $name): bool
    {
        $keySeeded = $connection->table('tax_deduction_categories')
            ->where('user_id', $userId)
            ->where('corpus_key', $key)
            ->exists();

        if ($keySeeded) {
            return true;
        }

        return $connection->table('tax_deduction_categories')
            ->where('user_id', $userId)
            ->where('name', $name)
            ->exists();
    }

    /**
     * @param  array<int|string, mixed>  $entry
     */
    private static function stringField(array $entry, string $key): string
    {
        $value = $entry[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * @param  array<int|string, mixed>  $entry
     */
    private static function nullableStringField(array $entry, string $key): ?string
    {
        $value = $entry[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @return int The new category's id.
     *
     * @throws DuplicateTaxCategoryNameException When a category with the same name already exists for the user.
     */
    public function add(int $userId, string $name, ?string $shortName = null, ?string $hint = null): int
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException(Lang::get('tax::messages.errors.name_empty'));
        }

        $connection = $this->db->connection();
        $now = Carbon::now()->toDateTimeString();

        $exists = $connection->table('tax_deduction_categories')
            ->where('user_id', $userId)
            ->where('name', $name)
            ->exists();

        if ($exists) {
            throw new DuplicateTaxCategoryNameException(Lang::get('tax::messages.errors.name_duplicate'));
        }

        $maxOrder = $connection->table('tax_deduction_categories')
            ->where('user_id', $userId)
            ->max('sort_order');

        $sortOrder = is_numeric($maxOrder) ? (int) $maxOrder + 1 : 0;

        $connection->table('tax_deduction_categories')->insert([
            'user_id' => $userId,
            'name' => $name,
            'short_name' => $shortName,
            'hint' => $hint,
            'corpus_key' => null,
            'country_code' => null,
            'name_is_default' => false,
            'status' => TaxCategoryStatus::Active->value,
            'sort_order' => $sortOrder,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /** @var int|null $id */
        $id = $connection->table('tax_deduction_categories')
            ->where('user_id', $userId)
            ->where('name', $name)
            ->value('id');

        if (! is_int($id)) {
            throw new CategoryPersistenceException('Failed to retrieve new category id.');
        }

        return $id;
    }

    /**
     * @throws NotFoundHttpException When the category id is not owned by the user.
     * @throws \InvalidArgumentException When the new name is empty.
     * @throws DuplicateTaxCategoryNameException When another category already carries the new name.
     */
    public function rename(int $userId, int $categoryId, string $name): void
    {
        $name = trim($name);
        if ($name === '') {
            throw new \InvalidArgumentException(Lang::get('tax::messages.errors.name_empty'));
        }

        $connection = $this->db->connection();

        $existing = $connection->table('tax_deduction_categories')
            ->where('id', $categoryId)
            ->where('user_id', $userId)
            ->first();

        if ($existing === null) {
            throw new NotFoundHttpException;
        }

        $nameTaken = $connection->table('tax_deduction_categories')
            ->where('user_id', $userId)
            ->where('name', $name)
            ->where('id', '!=', $categoryId)
            ->exists();

        if ($nameTaken) {
            throw new DuplicateTaxCategoryNameException(Lang::get('tax::messages.errors.name_duplicate'));
        }

        $connection->table('tax_deduction_categories')
            ->where('id', $categoryId)
            ->where('user_id', $userId)
            ->update([
                'name' => $name,
                'name_is_default' => false,
                'updated_at' => Carbon::now()->toDateTimeString(),
            ]);
    }

    /**
     * @throws NotFoundHttpException When the category id is not owned by the user.
     */
    public function archive(int $userId, int $categoryId): void
    {
        $connection = $this->db->connection();

        $existing = $connection->table('tax_deduction_categories')
            ->where('id', $categoryId)
            ->where('user_id', $userId)
            ->first();

        if ($existing === null) {
            throw new NotFoundHttpException;
        }

        $connection->table('tax_deduction_categories')
            ->where('id', $categoryId)
            ->where('user_id', $userId)
            ->update([
                'status' => TaxCategoryStatus::Archived->value,
                'updated_at' => Carbon::now()->toDateTimeString(),
            ]);
    }

    /**
     * @throws NotFoundHttpException When the category id is not owned by the user.
     */
    public function unarchive(int $userId, int $categoryId): void
    {
        $connection = $this->db->connection();

        $existing = $connection->table('tax_deduction_categories')
            ->where('id', $categoryId)
            ->where('user_id', $userId)
            ->first();

        if ($existing === null) {
            throw new NotFoundHttpException;
        }

        $connection->table('tax_deduction_categories')
            ->where('id', $categoryId)
            ->where('user_id', $userId)
            ->update([
                'status' => TaxCategoryStatus::Active->value,
                'updated_at' => Carbon::now()->toDateTimeString(),
            ]);
    }

    /**
     * @return list<\stdClass>
     */
    public function listForUser(int $userId, bool $includeArchived = false): array
    {
        $query = $this->db->connection()
            ->table('tax_deduction_categories')
            ->where('user_id', $userId);

        if (! $includeArchived) {
            $query->where('status', TaxCategoryStatus::Active->value);
        }

        /** @var list<\stdClass> $rows */
        $rows = $query->orderBy('sort_order')->orderBy('name')->get()->all();

        // Resolved here rather than at each render: the settings list, the tag
        // picker and the rule form read this one shape. Ordering stays on the
        // stored columns, so the list does not reshuffle per locale and two
        // devices agree on it.
        foreach ($rows as $row) {
            $country = self::textOf($row, 'country_code');
            $key = self::textOf($row, 'corpus_key');
            $row->name = TaxCorpusWording::name(self::textOf($row, 'name'), $country, $key, $row->name_is_default ?? false);
            $row->short_name = TaxCorpusWording::shortName(self::textOf($row, 'short_name'), $country, $key);
            $row->hint = TaxCorpusWording::hint(self::textOf($row, 'hint'), $country, $key);
        }

        return $rows;
    }

    private static function textOf(\stdClass $row, string $column): ?string
    {
        return is_string($row->{$column} ?? null) ? $row->{$column} : null;
    }
}
