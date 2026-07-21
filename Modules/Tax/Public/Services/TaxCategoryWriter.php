<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Services;

use Modules\Core\Models\User;
use Modules\Tax\Internal\Actions\TaxCategoryWriter as InternalTaxCategoryWriter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @link ../../../../.docs/features/tax/architecture.md
 */
final class TaxCategoryWriter
{
    public function __construct(
        private readonly InternalTaxCategoryWriter $writer,
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
        return $this->writer->add($userId, $name, $shortName, $hint);
    }

    /**
     * @throws NotFoundHttpException When the category id is not owned by the user.
     */
    public function rename(int $userId, int $categoryId, string $name): void
    {
        $this->writer->rename($userId, $categoryId, $name);
    }

    /**
     * @throws NotFoundHttpException When the category id is not owned by the user.
     */
    public function archive(int $userId, int $categoryId): void
    {
        $this->writer->archive($userId, $categoryId);
    }

    /**
     * @throws NotFoundHttpException When the category id is not owned by the user.
     */
    public function unarchive(int $userId, int $categoryId): void
    {
        $this->writer->unarchive($userId, $categoryId);
    }

    /**
     * @return list<\stdClass>
     */
    public function listForUser(int $userId, bool $includeArchived = false): array
    {
        return $this->writer->listForUser($userId, $includeArchived);
    }
}
