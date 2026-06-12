<?php

declare(strict_types=1);

namespace Modules\Tax\Public\Services;

use Modules\Core\Models\User;
use Modules\Tax\Internal\Actions\TaxCategoryWriter as InternalTaxCategoryWriter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public facade for deduction-category writes (create, rename, archive,
 * corpus seed, list).
 *
 * Delegates all work to the Internal implementation so cross-module
 * consumers — the HandlesTaxTagging trait running inside Ledger/CashBook/
 * Counterparties components — depend only on the Tax module's Public
 * surface (WR-07). Mirrors the Public\Services\TaxYearQuery /
 * TaxCountrySetup delegating pattern.
 */
final class TaxCategoryWriter
{
    public function __construct(
        private readonly InternalTaxCategoryWriter $writer,
    ) {}

    /**
     * Seed the user's categories from the given country corpus.
     * INSERT-only on (user_id, corpus_key) — existing rows are NEVER updated.
     *
     * @return int Number of rows actually inserted (0 when already seeded).
     */
    public function seedFromCorpus(User $user, string $countryCode): int
    {
        return $this->writer->seedFromCorpus($user, $countryCode);
    }

    /**
     * Create a user-owned category (corpus_key null).
     *
     * @return int The new category's id.
     *
     * @throws \RuntimeException When a category with the same name already exists for the user.
     */
    public function add(int $userId, string $name, ?string $shortName = null, ?string $hint = null): int
    {
        return $this->writer->add($userId, $name, $shortName, $hint);
    }

    /**
     * Rename a category. User-scoped: throws NotFoundHttpException on cross-user id.
     *
     * @throws NotFoundHttpException When the category id is not owned by the user (T-07-13).
     */
    public function rename(int $userId, int $categoryId, string $name): void
    {
        $this->writer->rename($userId, $categoryId, $name);
    }

    /**
     * Archive a category. User-scoped: throws NotFoundHttpException on cross-user id.
     *
     * @throws NotFoundHttpException When the category id is not owned by the user (T-07-13).
     */
    public function archive(int $userId, int $categoryId): void
    {
        $this->writer->archive($userId, $categoryId);
    }

    /**
     * List the user's deduction categories ordered by sort_order then name.
     *
     * @return list<\stdClass>
     */
    public function listForUser(int $userId, bool $includeArchived = false): array
    {
        return $this->writer->listForUser($userId, $includeArchived);
    }
}
