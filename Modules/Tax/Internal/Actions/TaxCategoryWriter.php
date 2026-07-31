<?php

declare(strict_types=1);

namespace Modules\Tax\Internal\Actions;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Carbon;
use Modules\Core\Models\User;
use Modules\Tax\Internal\Corpus\TaxCorpusLoader;
use Modules\Tax\Public\Exceptions\DuplicateTaxCategoryNameException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @link ../../../../.docs/features/tax/architecture.md
 */
final class TaxCategoryWriter
{
    public function __construct(
        private readonly DatabaseManager $db,
        private readonly TaxCorpusLoader $corpusLoader,
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
        $inserted = 0;

        // sort_order continues after the user's existing categories (as
        // add() does) so seeding a second country appends its block
        // instead of interleaving pairwise with the first country's set.
        $maxOrder = $connection->table('tax_deduction_categories')
            ->where('user_id', $user->id)
            ->max('sort_order');
        $sortBase = is_numeric($maxOrder) ? (int) $maxOrder + 1 : 0;

        foreach ($entries as $entry) {
            $key = is_string($entry['key'] ?? null) ? $entry['key'] : '';
            $name = is_string($entry['name'] ?? null) ? $entry['name'] : '';
            if ($key === '' || $name === '') {
                continue;
            }

            // Check if this corpus_key already exists for the user (the
            // INSERT-only contract).
            $existingId = $connection->table('tax_deduction_categories')
                ->where('user_id', $user->id)
                ->where('corpus_key', $key)
                ->value('id');

            if ($existingId !== null) {
                // Already seeded — skip to preserve any rename the user
                // has made since.
                continue;
            }

            // A user-created category may already carry this exact name;
            // inserting would violate unique(user_id, name) and 500 the
            // settings/wizard country pickers. Skip — the user's own
            // category wins.
            $nameTaken = $connection->table('tax_deduction_categories')
                ->where('user_id', $user->id)
                ->where('name', $name)
                ->exists();

            if ($nameTaken) {
                continue;
            }

            $shortName = is_string($entry['short_name'] ?? null) ? $entry['short_name'] : null;
            $hint = is_string($entry['hint'] ?? null) ? $entry['hint'] : null;

            $connection->table('tax_deduction_categories')->insert([
                'user_id' => $user->id,
                'name' => $name,
                'short_name' => $shortName,
                'hint' => $hint,
                'corpus_key' => $key,
                'country_code' => $countryCode,
                'status' => 'active',
                'sort_order' => $sortBase + $inserted,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $inserted++;
        }

        return $inserted;
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
            throw new \InvalidArgumentException('Category name cannot be empty.');
        }

        $connection = $this->db->connection();
        $now = Carbon::now()->toDateTimeString();

        // Check for existing name (the unique constraint will also catch this at DB level,
        // but we surface a friendly message before the DB exception).
        $exists = $connection->table('tax_deduction_categories')
            ->where('user_id', $userId)
            ->where('name', $name)
            ->exists();

        if ($exists) {
            throw new DuplicateTaxCategoryNameException('A category with this name already exists.');
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
            'status' => 'active',
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
            throw new \RuntimeException('Failed to retrieve new category id.');
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
            throw new \InvalidArgumentException('Category name cannot be empty.');
        }

        $connection = $this->db->connection();

        $existing = $connection->table('tax_deduction_categories')
            ->where('id', $categoryId)
            ->where('user_id', $userId)
            ->first();

        if ($existing === null) {
            throw new NotFoundHttpException;
        }

        // Duplicate-name guard mirroring add() — surfaces a friendly message
        // instead of an uncaught unique(user_id, name) QueryException.
        $nameTaken = $connection->table('tax_deduction_categories')
            ->where('user_id', $userId)
            ->where('name', $name)
            ->where('id', '!=', $categoryId)
            ->exists();

        if ($nameTaken) {
            throw new DuplicateTaxCategoryNameException('A category with this name already exists.');
        }

        $connection->table('tax_deduction_categories')
            ->where('id', $categoryId)
            ->where('user_id', $userId)
            ->update([
                'name' => $name,
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
                'status' => 'archived',
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
                'status' => 'active',
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
            $query->where('status', 'active');
        }

        /** @var list<\stdClass> $rows */
        $rows = $query->orderBy('sort_order')->orderBy('name')->get()->all();

        return $rows;
    }
}
