<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Services;

use Modules\Budgets\Models\CategoryBudget;
use Modules\Core\Models\User;
use Modules\Core\Public\Scopes\UserScope;
use Modules\Ledger\Public\ValueObjects\MoneyInput;

final class BudgetWriter
{
    public function __construct(
        private readonly BudgetProgressQuery $query,
    ) {}

    public function save(User $user, int $categoryId, int $minor): bool
    {
        if ($minor <= 0 || ! $this->query->canBudget($user, $categoryId)) {
            return false;
        }

        // With the global scope active, an upsert for any other user would
        // mismatch and fall through to an INSERT that trips the
        // (user_id, category_id) unique constraint.
        CategoryBudget::query()->withoutGlobalScope(UserScope::class)->updateOrCreate(
            ['user_id' => $user->id, 'category_id' => $categoryId],
            ['budget_minor' => $minor, 'currency' => 'EUR', 'period_type' => 'monthly'],
        );

        return true;
    }

    public function remove(User $user, int $categoryId): void
    {
        CategoryBudget::query()
            ->withoutGlobalScope(UserScope::class)
            ->where('user_id', $user->id)
            ->where('category_id', $categoryId)
            ->delete();
    }

    public function parseAmount(string $value): ?int
    {
        return MoneyInput::tryToPositiveMinor($value);
    }
}
