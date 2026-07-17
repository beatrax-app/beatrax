<?php

declare(strict_types=1);

namespace Modules\Budgets\Public\Services;

use Modules\Budgets\Models\CategoryBudget;
use Modules\Core\Models\User;
use Modules\Core\Public\Scopes\UserScope;

/**
 * The single write path for category budgets, shared by the /budgets page and
 * the onboarding wizard step. Parses user-entered amounts, validates that a
 * client-supplied category id is actually one the user may budget, and upserts
 * the budget — so neither caller can attach a budget to another user's
 * category or duplicate logic.
 */
final class BudgetWriter
{
    public function __construct(
        private readonly BudgetProgressQuery $query,
    ) {}

    /**
     * Save a monthly EUR budget for ($user, $categoryId). Returns false (no
     * write) when the category is not one the user may budget — see
     * BudgetProgressQuery::canBudget.
     */
    public function save(User $user, int $categoryId, int $minor): bool
    {
        if ($minor <= 0 || ! $this->query->canBudget($user, $categoryId)) {
            return false;
        }

        // Bypass the current-user global scope so the explicit $user is
        // authoritative: with the scope active, an upsert for any user other
        // than the logged-in one would mismatch and fall through to an INSERT
        // that trips the (user_id, category_id) unique constraint.
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

    /**
     * Parse a user-entered amount into positive integer minor units, or null
     * if invalid. Handles the Dutch grouped form "1.234,56" and the plain
     * forms "1234.56" / "50,00" / "50": the rightmost of '.'/',' is the
     * decimal separator and the other is thousands. The whole part is capped
     * at 12 digits so the cents arithmetic cannot overflow PHP_INT_MAX.
     */
    public function parseAmount(string $value): ?int
    {
        $normalised = str_replace([' ', "\u{00A0}"], '', trim($value));
        if ($normalised === '') {
            return null;
        }

        $lastDot = strrpos($normalised, '.');
        $lastComma = strrpos($normalised, ',');
        if ($lastDot !== false && $lastComma !== false) {
            $normalised = $lastComma > $lastDot
                ? str_replace(['.', ','], ['', '.'], $normalised)
                : str_replace(',', '', $normalised);
        } elseif ($lastComma !== false) {
            $normalised = str_replace(',', '.', $normalised);
        }

        if (preg_match('/^\d{1,12}(\.\d{1,2})?$/', $normalised) !== 1) {
            return null;
        }

        [$whole, $frac] = array_pad(explode('.', $normalised, 2), 2, '');
        $minor = (int) $whole * 100 + (int) str_pad($frac, 2, '0');

        return $minor > 0 ? $minor : null;
    }
}
