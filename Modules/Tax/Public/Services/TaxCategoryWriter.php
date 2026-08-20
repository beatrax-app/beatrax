<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Services;

use Illuminate\Contracts\Events\Dispatcher;
use Modules\Core\Models\User;
use Modules\Sync\Public\Events\EntityMutated;
use Modules\Tax\Internal\Actions\TaxCategoryWriter as InternalTaxCategoryWriter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class TaxCategoryWriter
{
    public function __construct(
        private readonly InternalTaxCategoryWriter $writer,
        private readonly Dispatcher $events,
    ) {}

    /**
     * @return int Number of rows actually inserted (0 when already seeded).
     */
    public function seedFromCorpus(User $user, string $countryCode): int
    {
        return $this->writer->seedFromCorpus($user, $countryCode);
    }

    /**
     * @return int The new category's id.
     *
     * @throws \RuntimeException When a category with the same name already exists for the user.
     */
    public function add(int $userId, string $name, ?string $shortName = null, ?string $hint = null): int
    {
        $id = $this->writer->add($userId, $name, $shortName, $hint);

        $this->capture($userId, $id, 'create', [
            'user_id' => $userId,
            'name' => $name,
            'short_name' => $shortName,
            'hint' => $hint,
        ]);

        return $id;
    }

    /**
     * @throws NotFoundHttpException When the category id is not owned by the user.
     */
    public function rename(int $userId, int $categoryId, string $name): void
    {
        $this->writer->rename($userId, $categoryId, $name);

        $this->capture($userId, $categoryId, 'edit', ['name' => $name]);
    }

    /**
     * @throws NotFoundHttpException When the category id is not owned by the user.
     */
    public function archive(int $userId, int $categoryId): void
    {
        $this->writer->archive($userId, $categoryId);

        $this->capture($userId, $categoryId, 'edit', ['status' => 'archived']);
    }

    /**
     * @throws NotFoundHttpException When the category id is not owned by the user.
     */
    public function unarchive(int $userId, int $categoryId): void
    {
        $this->writer->unarchive($userId, $categoryId);

        $this->capture($userId, $categoryId, 'edit', ['status' => 'active']);
    }

    /**
     * @return list<\stdClass>
     */
    public function listForUser(int $userId, bool $includeArchived = false): array
    {
        return $this->writer->listForUser($userId, $includeArchived);
    }

    // Deduction categories had no capture, so a category added on one device
    // could not be chosen on another — and any tag referencing it arrived
    // pointing at a row the peer did not have.
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
