<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Modules\Core\Models\User;
use Modules\Sync\Public\Events\EntityMutated;
use Modules\Tax\Internal\Actions\TaxCategoryStore;
use Modules\Tax\Internal\Enums\TaxCategoryStatus;
use Modules\Tax\Internal\Exceptions\DuplicateTaxCategoryNameException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class TaxCategoryWriter
{
    public function __construct(
        private TaxCategoryStore $store,
        private Dispatcher $events,
    ) {}

    /**
     * @return int Number of rows actually inserted (0 when already seeded).
     */
    public function seedFromCorpus(User $user, string $countryCode): int
    {
        return $this->store->seedFromCorpus($user, $countryCode);
    }

    /**
     * @return int The new category's id.
     *
     * @throws \RuntimeException When a category with the same name already exists for the user.
     */
    public function add(int $userId, string $name, ?string $shortName = null, ?string $hint = null): int
    {
        $id = $this->store->add($userId, $name, $shortName, $hint);

        $this->capture($userId, $id, 'create', [
            'user_id' => $userId,
            'name' => $name,
            'short_name' => $shortName,
            'hint' => $hint,
            'name_is_default' => false,
        ]);

        return $id;
    }

    /**
     * @throws NotFoundHttpException When the category id is not owned by the user.
     * @throws \InvalidArgumentException When the new name is empty.
     * @throws DuplicateTaxCategoryNameException When another category already carries the new name.
     */
    public function rename(int $userId, int $categoryId, string $name): void
    {
        $this->store->rename($userId, $categoryId, $name);

        // The flag travels with the rename it describes. Left behind, the peer
        // keeps resolving the corpus line and the rename is invisible there.
        $this->capture($userId, $categoryId, 'edit', ['name' => $name, 'name_is_default' => false]);
    }

    /**
     * @throws NotFoundHttpException When the category id is not owned by the user.
     */
    public function archive(int $userId, int $categoryId): void
    {
        $this->store->archive($userId, $categoryId);

        $this->capture($userId, $categoryId, 'edit', ['status' => TaxCategoryStatus::Archived->value]);
    }

    /**
     * @throws NotFoundHttpException When the category id is not owned by the user.
     */
    public function unarchive(int $userId, int $categoryId): void
    {
        $this->store->unarchive($userId, $categoryId);

        $this->capture($userId, $categoryId, 'edit', ['status' => TaxCategoryStatus::Active->value]);
    }

    /**
     * @return list<\stdClass>
     */
    public function listForUser(int $userId, bool $includeArchived = false): array
    {
        return $this->store->listForUser($userId, $includeArchived);
    }

    // Deduction categories had no capture, so a tag synced to a peer arrived
    // pointing at a category row that peer did not have.
    /**
     * @param  array<string, mixed>  $fields
     */
    private function capture(int $userId, int $categoryId, string $mutationType, array $fields): void
    {
        $this->events->dispatch(new EntityMutated(
            table: 'tax_deduction_categories',
            pk: $categoryId,
            userId: $userId,
            mutationType: $mutationType,
            dirtyFields: $fields,
        ));
    }
}
